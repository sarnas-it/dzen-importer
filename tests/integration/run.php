<?php
/**
 * Интеграционная проверка внутри WordPress.
 *
 * Запуск: wp eval-file tests/integration/run.php
 *
 * @package DzenImporter
 */

use DzenImporter\FeedClient;
use DzenImporter\Importer;
use DzenImporter\Logger;
use DzenImporter\Settings;
use DzenImporter\State;
use DzenImporter\Sync;

$GLOBALS['dzen_failures'] = 0;

/**
 * Проверка условия.
 *
 * @param string $label Подпись.
 * @param bool   $cond  Условие.
 * @param string $extra Дополнительно.
 * @return void
 */
function dzen_check( $label, $cond, $extra = '' ) {
	if ( $cond ) {
		WP_CLI::log( '  ok   ' . $label );
		return;
	}

	$GLOBALS['dzen_failures']++;
	WP_CLI::log( '  FAIL ' . $label . ( '' !== $extra ? ' — ' . $extra : '' ) );
}

WP_CLI::log( 'Плагин загружен: ' . ( class_exists( 'DzenImporter\\Importer' ) ? 'да' : 'НЕТ' ) );

$settings = Settings::update(
	array(
		'channel'             => 'https://dzen.ru/radar-c.ru',
		'interval'            => 10,
		'post_status'         => 'draft',
		'seo_plugin'          => 'none',
		'source_link'         => 1,
		'source_link_nofollow' => 1,
		'max_per_run'         => 10,
	)
);

dzen_check( 'слаг канала', 'radar-c.ru' === $settings['channel'], $settings['channel'] );

State::reset();
Logger::clear();

// Чистим прошлые импорты, чтобы прогон был повторяемым.
$old_posts = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_dzen_publication_id', // phpcs:ignore WordPress.DB.SlowDBQuery
	)
);
foreach ( $old_posts as $old_id ) {
	wp_delete_post( $old_id, true );
}

// 1. Первый запуск фиксирует канал и ничего не импортирует.
$before = wp_count_posts( 'post' )->draft + wp_count_posts( 'post' )->publish;
$run    = Sync::run();
$after  = wp_count_posts( 'post' )->draft + wp_count_posts( 'post' )->publish;

dzen_check( 'базовая линия создана', ! empty( $run['baseline'] ) && $run['count'] > 0, wp_json_encode( $run ) );
dzen_check( 'на первом запуске ничего не импортировано', $before === $after );

$state = State::get();
dzen_check( 'baseline_done', 1 === (int) $state['baseline_done'] );
dzen_check( 'канал определён', '' !== $state['channel_title'], $state['channel_title'] );

// 2. Повторный запуск не находит нового.
$second = Sync::run();
dzen_check( 'повторный запуск без импорта', 0 === $second['imported'], wp_json_encode( $second ) );

// 3. Импорт конкретной существующей статьи напрямую.
$client = new FeedClient();
$page   = $client->page( 'radar-c.ru' );
$item   = $page['items'][0];

$importer = new Importer();
$post_id  = $importer->import_item( $item, Settings::get() );

dzen_check( 'запись создана', is_int( $post_id ) && $post_id > 0, var_export( $post_id, true ) );

if ( is_int( $post_id ) && $post_id > 0 ) {
	$post = get_post( $post_id );

	dzen_check( 'заголовок совпадает', $post->post_title === $item['title'], $post->post_title );
	dzen_check( 'текст большой', strlen( $post->post_content ) > 8000, (string) strlen( $post->post_content ) );
	$classes = array();
	preg_match_all( '~class="([^"]*)"~', $post->post_content, $classes );
	$classes = array_unique( $classes[1] );
	dzen_check( 'классы вырезаны', array( 'dzen-importer-source' ) === array_values( $classes ), implode( ',', $classes ) );
	dzen_check( 'картинки локальные', false !== strpos( $post->post_content, wp_upload_dir()['baseurl'] ) );
	dzen_check( 'заголовки сохранены', false !== strpos( $post->post_content, '<h2>' ) );
	dzen_check( 'миниатюра есть', get_post_thumbnail_id( $post_id ) > 0 );
	dzen_check( 'meta id', get_post_meta( $post_id, '_dzen_publication_id', true ) === $item['id'] );
	dzen_check( 'meta url', get_post_meta( $post_id, '_dzen_source_url', true ) === $item['url'] );
	dzen_check( 'слаг', '' !== $post->post_name && 1 === preg_match( '~^[a-z0-9-]+$~', $post->post_name ), $post->post_name );
	dzen_check( 'дата оригинальная', gmdate( 'Y-m-d', $item['ts'] ) === substr( $post->post_date, 0, 10 ), $post->post_date );
	dzen_check( 'черновик', 'draft' === $post->post_status, $post->post_status );
	dzen_check( 'описание записано', '' !== get_post_meta( $post_id, '_dzen_seo_description', true ) );
	dzen_check( 'блок источника', false !== strpos( $post->post_content, 'dzen-importer-source' ) );
	dzen_check( 'nofollow', false !== strpos( $post->post_content, 'rel="nofollow noopener"' ) );
	dzen_check( 'вложения созданы', count( get_attached_media( 'image', $post_id ) ) > 0 );

	// 4. Повторный импорт не создаёт дубль.
	$again = $importer->import_item( $item, Settings::get() );
	dzen_check( 'дубль не создан', 0 === $again, var_export( $again, true ) );
}

$log = Logger::all();
dzen_check( 'лог не пуст', count( $log ) > 0 );

if ( $GLOBALS['dzen_failures'] > 0 ) {
	WP_CLI::error( sprintf( 'Провалено проверок: %d', $GLOBALS['dzen_failures'] ) );
}

WP_CLI::success( 'Все интеграционные проверки пройдены.' );
