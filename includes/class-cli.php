<?php
/**
 * WP-CLI команды.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Команды `wp dzen ...`.
 */
class Cli {

	/**
	 * Показывает состояние импорта.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dzen status
	 *
	 * @return void
	 */
	public function status() {
		$settings = Settings::get();
		$state    = State::get();

		\WP_CLI::line( 'Канал: ' . ( $settings['channel'] ? $settings['channel'] : 'не задан' ) );
		\WP_CLI::line( 'Интервал: ' . $settings['interval'] . ' мин.' );
		\WP_CLI::line( 'Базовая линия: ' . ( $state['baseline_done'] ? 'зафиксирована (' . $state['baseline_count'] . ')' : 'нет' ) );
		\WP_CLI::line( 'Импортировано: ' . (int) $state['imported_total'] );
		\WP_CLI::line( 'Последний запуск: ' . ( $state['last_run'] ? gmdate( 'Y-m-d H:i:s', (int) $state['last_run'] ) : '—' ) );
		\WP_CLI::line( 'Последняя ошибка: ' . ( $state['last_error'] ? $state['last_error'] : '—' ) );
	}

	/**
	 * Синхронизирует канал.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dzen sync
	 *
	 * @return void
	 */
	public function sync() {
		$result = Sync::run();

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['message'] );
		}

		if ( ! empty( $result['baseline'] ) ) {
			\WP_CLI::success( 'Канал зафиксирован: ' . $result['count'] . ' публикаций.' );

			return;
		}

		\WP_CLI::success(
			sprintf(
				'Импортировано: %d, пропущено: %d, ошибок: %d.',
				$result['imported'],
				$result['skipped'],
				$result['errors']
			)
		);
	}

	/**
	 * Догружает старые публикации.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Сколько статей импортировать. По умолчанию 10.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dzen backfill --limit=20
	 *
	 * @param array $args       Позиционные аргументы.
	 * @param array $assoc_args Флаги.
	 * @return void
	 */
	public function backfill( $args, $assoc_args ) {
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : (int) Settings::get()['backfill_batch'];
		$result = Backfill::run( $limit );

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['message'] );
		}

		\WP_CLI::success(
			sprintf(
				'Догружено: %d, пропущено: %d.%s',
				$result['imported'],
				$result['skipped'],
				! empty( $result['done'] ) ? ' Архив закончился.' : ''
			)
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'dzen', 'DzenImporter\\Cli' );
}
