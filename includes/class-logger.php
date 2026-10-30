<?php
/**
 * Простой кольцевой лог в options.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Хранит последние события плагина.
 */
class Logger {

	const OPTION = 'dzen_importer_log';
	const LIMIT  = 300;

	/**
	 * Добавляет запись.
	 *
	 * @param string $level   info|warning|error.
	 * @param string $message Текст.
	 * @return void
	 */
	public static function log( $level, $message ) {
		$entries = self::all();

		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'level'   => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'message' => (string) $message,
			)
		);

		$entries = array_slice( $entries, 0, self::LIMIT );

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Все записи, свежие сверху.
	 *
	 * @return array
	 */
	public static function all() {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Очищает лог.
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( self::OPTION, array(), false );
	}
}
