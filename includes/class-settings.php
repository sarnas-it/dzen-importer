<?php
/**
 * Настройки плагина.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Хранит и валидирует настройки.
 */
class Settings {

	const OPTION = 'dzen_importer_settings';

	const SEO_PLUGINS = array( 'auto', 'yoast', 'rankmath', 'aioseo', 'seopress', 'slimseo', 'none' );

	/**
	 * Значения по умолчанию.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'channel'             => '',
			'interval'            => 10,
			'post_status'         => 'publish',
			'author_id'           => 0,
			'category_id'         => 0,
			'source_link'         => 1,
			'source_link_nofollow' => 1,
			'source_text'         => 'Источник: Дзен',
			'seo_plugin'          => 'auto',
			'strip_cover'         => 0,
			'import_videos'       => 1,
			'video_storage'       => 'embed',
			'video_quality'       => 'medium',
			'video_max_mb'        => 200,
			'max_per_run'         => 10,
			'backfill_batch'      => 10,
		);
	}

	/**
	 * Текущие настройки, слитые с дефолтами.
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
	 * Санитизирует и сохраняет настройки.
	 *
	 * @param array $input Сырые данные формы.
	 * @return array Сохранённые настройки.
	 */
	public static function update( array $input ) {
		$out = self::defaults();

		$out['channel'] = self::channel_slug( isset( $input['channel'] ) ? $input['channel'] : '' );

		$interval        = isset( $input['interval'] ) ? (int) $input['interval'] : 10;
		$out['interval'] = max( 5, min( 1440, $interval ) );

		$status              = isset( $input['post_status'] ) ? (string) $input['post_status'] : 'publish';
		$out['post_status']  = in_array( $status, array( 'publish', 'draft' ), true ) ? $status : 'publish';
		$out['author_id']    = isset( $input['author_id'] ) ? absint( $input['author_id'] ) : 0;
		$out['category_id']  = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;

		$out['source_link']          = empty( $input['source_link'] ) ? 0 : 1;
		$out['source_link_nofollow'] = empty( $input['source_link_nofollow'] ) ? 0 : 1;

		$text                = isset( $input['source_text'] ) ? sanitize_text_field( (string) $input['source_text'] ) : '';
		$out['source_text']  = '' === $text ? 'Источник: Дзен' : $text;

		$seo                 = isset( $input['seo_plugin'] ) ? (string) $input['seo_plugin'] : 'auto';
		$out['seo_plugin']   = in_array( $seo, self::SEO_PLUGINS, true ) ? $seo : 'auto';

		$out['strip_cover'] = empty( $input['strip_cover'] ) ? 0 : 1;

		$out['import_videos'] = empty( $input['import_videos'] ) ? 0 : 1;

		$storage              = isset( $input['video_storage'] ) ? (string) $input['video_storage'] : 'embed';
		$out['video_storage'] = in_array( $storage, array( 'embed', 'download' ), true ) ? $storage : 'embed';

		$quality              = isset( $input['video_quality'] ) ? (string) $input['video_quality'] : 'medium';
		$out['video_quality'] = in_array( $quality, array( 'lowest', 'low', 'medium', 'high', 'fullhd' ), true ) ? $quality : 'medium';

		$out['video_max_mb'] = max( 1, min( 4096, isset( $input['video_max_mb'] ) ? (int) $input['video_max_mb'] : 200 ) );

		$out['max_per_run']    = max( 1, min( 50, isset( $input['max_per_run'] ) ? (int) $input['max_per_run'] : 10 ) );
		$out['backfill_batch'] = max( 1, min( 50, isset( $input['backfill_batch'] ) ? (int) $input['backfill_batch'] : 10 ) );

		update_option( self::OPTION, $out );

		return $out;
	}

	/**
	 * Достаёт слаг канала из URL, @ника или слага.
	 *
	 * @param string $raw Пользовательский ввод.
	 * @return string
	 */
	public static function channel_slug( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '~^https?://~i', $raw ) ) {
			$parts = wp_parse_url( $raw );
			$raw   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		} else {
			$raw = preg_replace( '~^((www\.)?dzen\.ru|zen\.yandex\.ru)/~i', '', $raw );
		}

		$raw = preg_replace( '~[?#].*$~', '', $raw );
		$raw = trim( (string) $raw, '/' );

		$segments = explode( '/', $raw );
		$raw      = $segments[0];

		$raw = ltrim( $raw, '@' );
		$raw = preg_replace( '~[^A-Za-z0-9._-]~', '', $raw );

		return (string) $raw;
	}

	/**
	 * Поддерживает ли плагин выбранный SEO-плагин.
	 *
	 * @param string $plugin Слаг.
	 * @return bool
	 */
	public static function is_known_seo_plugin( $plugin ) {
		return in_array( $plugin, self::SEO_PLUGINS, true );
	}
}
