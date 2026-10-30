<?php
/**
 * Синхронизация канала.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Забирает новые публикации из фида.
 */
class Sync {

	const LOCK          = 'dzen_importer_lock';
	const LOCK_TTL      = 300;
	const MAX_PAGES     = 5;
	const MAX_FAILURES  = 3;

	/**
	 * Запускает синхронизацию.
	 *
	 * @param FeedClient|null $client   Клиент фида (для тестов).
	 * @param Importer|null   $importer Импортёр (для тестов).
	 * @return array
	 */
	public static function run( $client = null, $importer = null ) {
		$settings = Settings::get();

		if ( empty( $settings['channel'] ) ) {
			return array(
				'error'   => 'no_channel',
				'message' => 'Канал не задан в настройках.',
			);
		}

		if ( get_transient( self::LOCK ) ) {
			return array(
				'error'   => 'locked',
				'message' => 'Синхронизация уже выполняется.',
			);
		}

		set_transient( self::LOCK, 1, self::LOCK_TTL );

		try {
			return self::do_run( $settings, $client, $importer );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Основной проход.
	 *
	 * @param array           $settings Настройки.
	 * @param FeedClient|null $client   Клиент.
	 * @param Importer|null   $importer Импортёр.
	 * @return array
	 */
	private static function do_run( array $settings, $client, $importer ) {
		$state    = State::get();
		$client   = $client ? $client : new FeedClient();
		$importer = $importer ? $importer : new Importer();

		try {
			$page = $client->page( $settings['channel'] );
		} catch ( FeedException $e ) {
			$state['last_error'] = $e->getMessage();
			State::save( $state );
			Logger::log( 'error', 'Фид недоступен: ' . $e->getMessage() );

			return array(
				'error'   => 'feed',
				'message' => $e->getMessage(),
			);
		}

		if ( empty( $state['baseline_done'] ) ) {
			return self::baseline( $state, $page );
		}

		$last_seen = (int) $state['last_seen_ts'];
		$max       = max( 1, (int) $settings['max_per_run'] );
		$collected = array();
		$current   = $page;
		$pages     = 0;

		do {
			$pages++;
			$page_is_all_new = true;

			foreach ( $current['items'] as $item ) {
				if ( (int) $item['ts'] <= $last_seen ) {
					$page_is_all_new = false;
					break;
				}

				if ( count( $collected ) >= $max ) {
					break;
				}

				$collected[] = $item;
			}

			$need_more = $page_is_all_new
				&& count( $collected ) < $max
				&& count( $current['items'] ) > 0
				&& ! empty( $current['more'] )
				&& $pages < self::MAX_PAGES;

			if ( ! $need_more ) {
				break;
			}

			try {
				$current = $client->page( $settings['channel'], $current['more'] );
			} catch ( FeedException $e ) {
				Logger::log( 'warning', 'Не удалось дочитать фид: ' . $e->getMessage() );
				break;
			}
		} while ( true );

		usort(
			$collected,
			function ( $a, $b ) {
				return (int) $a['ts'] - (int) $b['ts'];
			}
		);

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $collected as $item ) {
			$result = $importer->import_item( $item, $settings );

			if ( is_wp_error( $result ) ) {
				$errors++;
				$state['last_error'] = $result->get_error_message();
				Logger::log( 'error', sprintf( '%s: %s', $item['title'], $result->get_error_message() ) );

				$id      = (string) $item['id'];
				$attempt = isset( $state['failures'][ $id ] ) ? (int) $state['failures'][ $id ] : 0;
				$attempt++;

				if ( $attempt < self::MAX_FAILURES ) {
					$state['failures'][ $id ] = $attempt;
					State::save( $state );
					break;
				}

				unset( $state['failures'][ $id ] );
				Logger::log( 'error', sprintf( '«%s» пропущена после %d попыток.', $item['title'], self::MAX_FAILURES ) );
			} else {
				unset( $state['failures'][ $item['id'] ] );

				if ( $result > 0 ) {
					$imported++;
					$state['imported_total'] = (int) $state['imported_total'] + 1;
				} else {
					$skipped++;
				}
			}

			$last_seen                 = max( $last_seen, (int) $item['ts'] );
			$state['last_seen_ts']     = $last_seen;
			$state['last_run']         = time();
			$state['last_error']       = '';
			State::save( $state );

			usleep( 500000 );
		}

		$state['last_run'] = time();
		State::save( $state );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'pages'    => $pages,
		);
	}

	/**
	 * Первый запуск: фиксирует текущее состояние канала.
	 *
	 * @param array $state Состояние.
	 * @param array $page  Первая страница фида.
	 * @return array
	 */
	private static function baseline( array $state, array $page ) {
		$max = 0;

		foreach ( $page['items'] as $item ) {
			$max = max( $max, (int) $item['ts'] );
		}

		$state['baseline_done']  = 1;
		$state['baseline_ts']    = $max;
		$state['baseline_count'] = count( $page['items'] );
		$state['last_seen_ts']   = $max;
		$state['channel_title']  = $page['channel_title'];
		$state['last_run']       = time();
		State::save( $state );

		Logger::log(
			'info',
			sprintf(
				'Зафиксировано %d публикаций канала. Новые будут импортироваться с %s.',
				count( $page['items'] ),
				$max ? get_date_from_gmt( gmdate( 'Y-m-d H:i', $max ) ) : '—'
			)
		);

		return array(
			'baseline' => true,
			'count'    => count( $page['items'] ),
		);
	}
}
