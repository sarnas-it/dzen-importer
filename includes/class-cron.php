<?php
/**
 * Планировщик.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Настраивает WP-Cron и точку входа синхронизации.
 */
class Cron {

	const HOOK     = 'dzen_importer_run';
	const SCHEDULE = 'dzen_importer_interval';

	/**
	 * Добавляет собственный интервал.
	 *
	 * @param array $schedules Расписания.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$settings = Settings::get();
		$minutes  = max( 5, (int) $settings['interval'] );

		$schedules[ self::SCHEDULE ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf( 'Каждые %d мин. (импорт из Дзена)', $minutes ),
		);

		return $schedules;
	}

	/**
	 * Точка входа крона.
	 *
	 * @return void
	 */
	public static function run() {
		Sync::run();
	}

	/**
	 * Пересоздаёт расписание.
	 *
	 * @return void
	 */
	public static function reschedule() {
		self::unschedule();

		$settings = Settings::get();

		if ( empty( $settings['channel'] ) ) {
			return;
		}

		wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
	}

	/**
	 * Снимает расписание.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Активация плагина.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( Settings::OPTION, false ) ) {
			update_option( Settings::OPTION, Settings::defaults() );
		}

		set_transient( 'dzen_importer_activation_redirect', 1, 60 );
		self::reschedule();
	}

	/**
	 * Деактивация плагина.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::unschedule();
		delete_transient( Sync::LOCK );
		delete_transient( Backfill::LOCK );
	}
}
