<?php
/**
 * Админка плагина.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Страница настроек, действия и лог.
 */
class Admin {

	const PAGE    = 'dzen-importer';
	const NOTICE  = 'dzen_importer_notice';
	const CAP     = 'manage_options';

	/**
	 * Хуки.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'admin_post_dzen_importer_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_dzen_importer_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_post_dzen_importer_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_dzen_importer_backfill', array( __CLASS__, 'handle_backfill' ) );
		add_action( 'admin_post_dzen_importer_clear_log', array( __CLASS__, 'handle_clear_log' ) );
	}

	/**
	 * Пункт меню.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			'Импорт из Дзена',
			'Импорт из Дзена',
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Подключает стили на своей странице.
	 *
	 * @param string $hook Текущая страница админки.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'dzen-importer-admin',
			DZEN_IMPORTER_URL . 'assets/admin.css',
			array(),
			DZEN_IMPORTER_VERSION
		);
	}

	/**
	 * Редирект на настройки после активации.
	 *
	 * @return void
	 */
	public static function maybe_redirect() {
		if ( ! get_transient( 'dzen_importer_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'dzen_importer_activation_redirect' );

		if ( ! current_user_can( self::CAP ) || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * URL страницы настроек.
	 *
	 * @return string
	 */
	private static function url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Сохраняет настройки.
	 *
	 * @return void
	 */
	public static function handle_save() {
		self::guard( 'dzen_importer_save' );

		$before = Settings::get();
		$after  = Settings::update( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $before['channel'] !== $after['channel'] ) {
			State::reset();
		}

		Cron::reschedule();

		self::notice( 'success', 'Настройки сохранены.' );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Проверяет канал.
	 *
	 * @return void
	 */
	public static function handle_check() {
		self::guard( 'dzen_importer_check' );

		$settings = Settings::get();

		if ( empty( $settings['channel'] ) ) {
			self::notice( 'error', 'Сначала укажите канал.' );
			self::redirect();
		}

		try {
			$client = new FeedClient();
			$page   = $client->page( $settings['channel'] );

			$title = '' !== $page['channel_title'] ? $page['channel_title'] : $settings['channel'];
			$last  = ! empty( $page['items'] ) ? $page['items'][0] : null;

			$message = sprintf( 'Канал найден: %s. Публикаций на первой странице: %d.', $title, count( $page['items'] ) );

			if ( $last ) {
				$message .= sprintf(
					' Последняя: «%s» от %s.',
					$last['title'],
					get_date_from_gmt( gmdate( 'Y-m-d H:i', $last['ts'] ) )
				);
			}

			self::notice( 'success', $message );
		} catch ( FeedException $e ) {
			self::notice( 'error', 'Канал не отвечает: ' . $e->getMessage() );
		}

		self::redirect();
	}

	/**
	 * Запускает синхронизацию.
	 *
	 * @return void
	 */
	public static function handle_sync() {
		self::guard( 'dzen_importer_sync' );

		$result = Sync::run();

		if ( isset( $result['error'] ) ) {
			self::notice( 'error', self::error_text( $result ) );
			self::redirect();
		}

		if ( ! empty( $result['baseline'] ) ) {
			self::notice( 'success', sprintf( 'Канал зафиксирован: %d публикаций. Ждём новые.', $result['count'] ) );
			self::redirect();
		}

		self::notice(
			'success',
			sprintf(
				'Готово: статей %d, видео %d, пропущено %d, ошибок %d.',
				$result['imported'],
				isset( $result['videos'] ) ? $result['videos']['imported'] : 0,
				$result['skipped'],
				$result['errors']
			)
		);

		self::redirect();
	}

	/**
	 * Догружает старые публикации.
	 *
	 * @return void
	 */
	public static function handle_backfill() {
		self::guard( 'dzen_importer_backfill' );

		$settings = Settings::get();
		$result   = Backfill::run( (int) $settings['backfill_batch'] );

		if ( isset( $result['error'] ) ) {
			self::notice( 'error', self::error_text( $result ) );
			self::redirect();
		}

		$message = sprintf( 'Догружено: %d, пропущено: %d.', $result['imported'], $result['skipped'] );

		if ( ! empty( $result['done'] ) ) {
			$message .= ' Архив закончился.';
		}

		self::notice( 'success', $message );
		self::redirect();
	}

	/**
	 * Очищает лог.
	 *
	 * @return void
	 */
	public static function handle_clear_log() {
		self::guard( 'dzen_importer_clear_log' );

		Logger::clear();
		self::notice( 'success', 'Лог очищен.' );
		self::redirect();
	}

	/**
	 * Проверка прав и nonce.
	 *
	 * @param string $action Действие.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Недостаточно прав.' );
		}

		check_admin_referer( $action );
	}

	/**
	 * Редирект назад в настройки.
	 *
	 * @return void
	 */
	private static function redirect() {
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Запоминает уведомление.
	 *
	 * @param string $type success|error.
	 * @param string $text Текст.
	 * @return void
	 */
	private static function notice( $type, $text ) {
		set_transient(
			self::NOTICE,
			array(
				'type' => $type,
				'text' => $text,
			),
			60
		);
	}

	/**
	 * Текст ошибки.
	 *
	 * @param array $result Результат.
	 * @return string
	 */
	private static function error_text( array $result ) {
		$map = array(
			'no_channel' => 'Канал не задан.',
			'locked'     => 'Уже выполняется другой запуск.',
			'feed'       => 'Фид недоступен.',
		);

		$base = isset( $map[ $result['error'] ] ) ? $map[ $result['error'] ] : 'Ошибка.';

		return isset( $result['message'] ) ? $base . ' ' . $result['message'] : $base;
	}

	/**
	 * Рисует страницу.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings  = Settings::get();
		$state     = State::get();
		$detected  = Seo::detect();
		$notice    = get_transient( self::NOTICE );
		$next_run  = wp_next_scheduled( Cron::HOOK );
		$log       = array_slice( Logger::all(), 0, 50 );
		$imported  = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 10,
				'meta_key'       => Importer::META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( $notice ) {
			delete_transient( self::NOTICE );
		}

		$seo_labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
			'seopress' => 'SEOPress',
			'slimseo'  => 'Slim SEO',
			'none'     => 'нет SEO-плагина',
		);

		?>
		<div class="wrap dzen-importer">
			<h1>Импорт из Дзена</h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="dzen-status">
				<h2>Статус</h2>
				<table class="widefat striped">
					<tbody>
						<tr><td>Канал</td><td>
							<?php echo $settings['channel'] ? esc_html( $settings['channel'] ) : 'не задан'; ?>
							<?php if ( $state['channel_title'] ) : ?>
								— <?php echo esc_html( $state['channel_title'] ); ?>
							<?php endif; ?>
						</td></tr>
						<tr><td>Первый запуск</td><td>
							<?php if ( $state['baseline_done'] ) : ?>
								зафиксировано <?php echo (int) $state['baseline_count']; ?> публикаций
								<?php echo $state['baseline_ts'] ? 'на ' . esc_html( get_date_from_gmt( gmdate( 'd.m.Y H:i', (int) $state['baseline_ts'] ) ) ) : ''; ?>
								— новые импортируются с этой точки
							<?php else : ?>
								ещё не выполнялся
							<?php endif; ?>
						</td></tr>
						<tr><td>Последний запуск</td><td>
							<?php echo $state['last_run'] ? esc_html( get_date_from_gmt( gmdate( 'd.m.Y H:i', (int) $state['last_run'] ) ) ) : '—'; ?>
						</td></tr>
						<tr><td>Следующий запуск</td><td>
							<?php echo $next_run ? esc_html( get_date_from_gmt( gmdate( 'd.m.Y H:i', (int) $next_run ) ) ) : 'не запланирован'; ?>
						</td></tr>
						<tr><td>Импортировано всего</td><td><?php echo (int) $state['imported_total']; ?></td></tr>
						<tr><td>SEO</td><td>
							<?php
							echo 'auto' === $settings['seo_plugin']
								? 'автоопределение: ' . esc_html( isset( $seo_labels[ $detected ] ) ? $seo_labels[ $detected ] : $detected )
								: 'выбран вручную: ' . esc_html( isset( $seo_labels[ $settings['seo_plugin'] ] ) ? $seo_labels[ $settings['seo_plugin'] ] : $settings['seo_plugin'] );
							?>
						</td></tr>
						<?php if ( $state['last_error'] ) : ?>
							<tr><td>Последняя ошибка</td><td class="dzen-error"><?php echo esc_html( $state['last_error'] ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( ! $next_run && $settings['channel'] ) : ?>
					<p class="description">WP-Cron не запланирован. Сохраните настройки, чтобы создать расписание.</p>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dzen-settings">
				<input type="hidden" name="action" value="dzen_importer_save" />
				<?php wp_nonce_field( 'dzen_importer_save' ); ?>

				<h2>Настройки</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dzen-channel">Канал</label></th>
						<td>
							<input type="text" class="regular-text" id="dzen-channel" name="channel"
								value="<?php echo esc_attr( $settings['channel'] ); ?>"
								placeholder="https://dzen.ru/radar-c.ru" />
							<p class="description">Ссылка на канал, @ник или слаг. Например: dzen.ru/radar-c.ru</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dzen-interval">Интервал, минут</label></th>
						<td>
							<input type="number" min="5" max="1440" id="dzen-interval" name="interval"
								value="<?php echo (int) $settings['interval']; ?>" class="small-text" />
							<p class="description">Минимум 5 минут. WP-Cron срабатывает при посещении сайта; для точного графика добавьте системный cron.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dzen-status">Публиковать</label></th>
						<td>
							<select id="dzen-status" name="post_status">
								<option value="publish" <?php selected( $settings['post_status'], 'publish' ); ?>>сразу</option>
								<option value="draft" <?php selected( $settings['post_status'], 'draft' ); ?>>в черновики</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dzen-author">Автор</label></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'             => 'author_id',
									'id'               => 'dzen-author',
									'selected'         => (int) $settings['author_id'],
									'show_option_none' => 'по умолчанию',
									'option_none_value' => 0,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dzen-category">Рубрика</label></th>
						<td>
							<?php
							wp_dropdown_categories(
								array(
									'name'             => 'category_id',
									'id'               => 'dzen-category',
									'selected'         => (int) $settings['category_id'],
									'show_option_none' => 'Без рубрики',
									'option_none_value' => 0,
									'hide_empty'       => 0,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row">Ссылка на источник</th>
						<td>
							<label>
								<input type="checkbox" name="source_link" value="1" <?php checked( $settings['source_link'], 1 ); ?> />
								добавлять блок со ссылкой на Дзен в конце записи
							</label>
							<br />
							<label>
								<input type="checkbox" name="source_link_nofollow" value="1" <?php checked( $settings['source_link_nofollow'], 1 ); ?> />
								ставить <code>nofollow</code>
							</label>
							<p>
								<input type="text" class="regular-text" name="source_text"
									value="<?php echo esc_attr( $settings['source_text'] ); ?>" />
							</p>
							<label>
								<input type="checkbox" name="strip_cover" value="1" <?php checked( $settings['strip_cover'], 1 ); ?> />
								убирать обложку из начала текста, если она поставлена миниатюрой
							</label>
							<p class="description">Включайте, если тема показывает миниатюру на странице записи — иначе обложка будет дважды.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dzen-seo">SEO-плагин</label></th>
						<td>
							<select id="dzen-seo" name="seo_plugin">
								<option value="auto" <?php selected( $settings['seo_plugin'], 'auto' ); ?>>определить автоматически</option>
								<?php foreach ( $seo_labels as $value => $label ) : ?>
									<?php if ( 'none' === $value ) : ?>
										<option value="none" <?php selected( $settings['seo_plugin'], 'none' ); ?>>свои мета-теги (плагина нет)</option>
									<?php else : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['seo_plugin'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<p class="description">Сейчас на сайте: <?php echo esc_html( isset( $seo_labels[ $detected ] ) ? $seo_labels[ $detected ] : $detected ); ?>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Видео</th>
						<td>
							<label>
								<input type="checkbox" name="import_videos" value="1" <?php checked( $settings['import_videos'], 1 ); ?> />
								импортировать видео из вкладки «Видео» канала
							</label>
							<p class="dzen-field">
								<label for="dzen-video-storage">Где хранить:</label>
								<select id="dzen-video-storage" name="video_storage">
									<option value="embed" <?php selected( $settings['video_storage'], 'embed' ); ?>>на Дзене (встроенный плеер)</option>
									<option value="download" <?php selected( $settings['video_storage'], 'download' ); ?>>у нас в медиатеке (скачивать)</option>
								</select>
							</p>
							<p class="description">Встроенный плеер не занимает место. Скачивание освобождает от Дзена, но ролики весят десятки мегабайт.</p>
							<p class="dzen-field">
								<label for="dzen-video-quality">Качество:</label>
								<select id="dzen-video-quality" name="video_quality">
									<option value="lowest" <?php selected( $settings['video_quality'], 'lowest' ); ?>>240p</option>
									<option value="low" <?php selected( $settings['video_quality'], 'low' ); ?>>360p</option>
									<option value="medium" <?php selected( $settings['video_quality'], 'medium' ); ?>>480p</option>
									<option value="high" <?php selected( $settings['video_quality'], 'high' ); ?>>720p</option>
									<option value="fullhd" <?php selected( $settings['video_quality'], 'fullhd' ); ?>>1080p</option>
								</select>
							</p>
							<p class="dzen-field">
								<label for="dzen-video-max">Лимит, МБ:</label>
								<input type="number" id="dzen-video-max" min="1" max="4096" name="video_max_mb"
									value="<?php echo (int) $settings['video_max_mb']; ?>" class="small-text" />
							</p>
							<p class="description">Если ролик больше лимита — он не скачивается, в лог пишется предупреждение, запись публикуется со встроенным плеером.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Порции</th>
						<td>
							<p class="dzen-field">
								<label for="dzen-max-per-run">За запуск:</label>
								<input type="number" id="dzen-max-per-run" min="1" max="50" name="max_per_run"
									value="<?php echo (int) $settings['max_per_run']; ?>" class="small-text" />
							</p>
							<p class="dzen-field">
								<label for="dzen-backfill-batch">Догрузка старых:</label>
								<input type="number" id="dzen-backfill-batch" min="1" max="50" name="backfill_batch"
									value="<?php echo (int) $settings['backfill_batch']; ?>" class="small-text" />
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Сохранить настройки' ); ?>
			</form>

			<div class="dzen-actions">
				<h2>Действия</h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dzen-inline">
					<input type="hidden" name="action" value="dzen_importer_check" />
					<?php wp_nonce_field( 'dzen_importer_check' ); ?>
					<?php submit_button( 'Проверить канал', 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dzen-inline">
					<input type="hidden" name="action" value="dzen_importer_sync" />
					<?php wp_nonce_field( 'dzen_importer_sync' ); ?>
					<?php submit_button( 'Синхронизировать сейчас', 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dzen-inline">
					<input type="hidden" name="action" value="dzen_importer_backfill" />
					<?php wp_nonce_field( 'dzen_importer_backfill' ); ?>
					<?php submit_button( 'Догрузить старые', 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dzen-inline">
					<input type="hidden" name="action" value="dzen_importer_clear_log" />
					<?php wp_nonce_field( 'dzen_importer_clear_log' ); ?>
					<?php submit_button( 'Очистить лог', 'delete', 'submit', false ); ?>
				</form>
			</div>

			<h2>Импортированные записи</h2>
			<?php if ( empty( $imported ) ) : ?>
				<p>Пока ничего не импортировано.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Заголовок</th><th>Дата</th><th>Статус</th></tr></thead>
					<tbody>
					<?php foreach ( $imported as $post ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
							<td><?php echo esc_html( get_the_date( 'd.m.Y H:i', $post ) ); ?></td>
							<td><?php echo esc_html( get_post_status_object( $post->post_status )->label ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Лог</h2>
			<?php if ( empty( $log ) ) : ?>
				<p>Лог пуст.</p>
			<?php else : ?>
				<table class="widefat striped dzen-log">
					<thead><tr><th style="width:160px">Время</th><th style="width:90px">Уровень</th><th>Сообщение</th></tr></thead>
					<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr class="dzen-log-<?php echo esc_attr( $entry['level'] ); ?>">
							<td><?php echo esc_html( get_date_from_gmt( gmdate( 'd.m.Y H:i:s', (int) $entry['time'] ) ) ); ?></td>
							<td><?php echo esc_html( $entry['level'] ); ?></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
