<?php
/**
 * Состояние синхронизации.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Хранит прогресс импорта между запусками.
 */
class State {

	const OPTION = 'dzen_importer_state';

	/**
	 * Значения по умолчанию.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'baseline_done'   => 0,
			'baseline_ts'     => 0,
			'baseline_count'  => 0,
			'last_seen_ts'    => 0,
			'last_run'        => 0,
			'last_error'      => '',
			'channel_title'   => '',
			'imported_total'  => 0,
			'failures'        => array(),
			'backfill_url'    => '',
			'backfill_offset' => 0,
			'backfill_done'   => 0,
		);
	}

	/**
	 * Текущее состояние.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Сохраняет состояние.
	 *
	 * @param array $state Состояние.
	 * @return void
	 */
	public static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Сбрасывает состояние (используется при смене канала).
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::OPTION );
	}
}
