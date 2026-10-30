<?php
/**
 * Ручная догрузка старых публикаций.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Импортирует архив порциями, начиная с самых свежих старых.
 */
class Backfill {

	const LOCK     = 'dzen_importer_backfill_lock';
	const LOCK_TTL = 600;

	/**
	 * Импортирует очередную порцию.
	 *
	 * @param int $limit Сколько статей за раз.
	 * @return array
	 */
	public static function run( $limit = 10 ) {
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
				'message' => 'Догрузка уже выполняется.',
			);
		}

		set_transient( self::LOCK, 1, self::LOCK_TTL );

		try {
			return self::do_run( $settings, max( 1, (int) $limit ) );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * @param array $settings Настройки.
	 * @param int   $limit    Порция.
	 * @return array
	 */
	private static function do_run( array $settings, $limit ) {
		$state = State::get();

		if ( ! empty( $state['backfill_done'] ) ) {
			return array(
				'done'     => true,
				'imported' => 0,
				'skipped'  => 0,
			);
		}

		$client   = new FeedClient();
		$importer = new Importer();
		$url      = ! empty( $state['backfill_url'] ) ? $state['backfill_url'] : null;

		try {
			$page = $client->page( $settings['channel'], $url );
		} catch ( FeedException $e ) {
			Logger::log( 'error', 'Догрузка: фид недоступен — ' . $e->getMessage() );

			return array(
				'error'   => 'feed',
				'message' => $e->getMessage(),
			);
		}

		$items   = $page['items'];
		$offset  = (int) $state['backfill_offset'];
		$total   = count( $items );
		$done    = 0;
		$skipped = 0;
		$i       = $offset;

		for ( ; $i < $total && $done < $limit; $i++ ) {
			$result = $importer->import_item( $items[ $i ], $settings );

			if ( is_wp_error( $result ) ) {
				$skipped++;
				Logger::log( 'error', sprintf( 'Догрузка: %s — %s', $items[ $i ]['title'], $result->get_error_message() ) );
				continue;
			}

			if ( $result > 0 ) {
				$done++;
				$state['imported_total'] = (int) $state['imported_total'] + 1;
			} else {
				$skipped++;
			}

			usleep( 500000 );
		}

		if ( $i >= $total ) {
			$state['backfill_url']    = ! empty( $page['more'] ) ? $page['more'] : '';
			$state['backfill_offset'] = 0;

			if ( empty( $page['more'] ) ) {
				$state['backfill_done'] = 1;
			}
		} else {
			$state['backfill_offset'] = $i;
		}

		State::save( $state );

		return array(
			'imported' => $done,
			'skipped'  => $skipped,
			'done'     => ! empty( $state['backfill_done'] ),
		);
	}
}
