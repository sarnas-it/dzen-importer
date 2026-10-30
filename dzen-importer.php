<?php
/**
 * Plugin Name:       Dzen Importer
 * Plugin URI:        https://github.com/sarnas-it/dzen-importer
 * Description:       Импорт публикаций из канала Дзен в WordPress: полный текст, картинки, SEO-поля.
 * Version:           0.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Sarnas
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dzen-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DZEN_IMPORTER_VERSION', '0.2.0' );
define( 'DZEN_IMPORTER_FILE', __FILE__ );
define( 'DZEN_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'DZEN_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once DZEN_IMPORTER_DIR . 'includes/class-settings.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-logger.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-state.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-text.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-http.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-feed-client.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-article-parser.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-media.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-seo.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-importer.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-sync.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-backfill.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-cron.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-admin.php';
require_once DZEN_IMPORTER_DIR . 'includes/class-cli.php';

register_activation_hook( __FILE__, array( 'DzenImporter\\Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DzenImporter\\Cron', 'deactivate' ) );

// Регистрируем на уровне файла: при активации плагина `plugins_loaded` уже прошёл.
add_filter( 'cron_schedules', array( 'DzenImporter\\Cron', 'add_schedule' ) );
add_action( 'dzen_importer_run', array( 'DzenImporter\\Cron', 'run' ) );

add_action( 'wp_head', array( 'DzenImporter\\Seo', 'render_fallback_meta' ) );

/**
 * Плеер Дзена: шорткод [dzen_video id="..."], для локального файла — src/poster.
 *
 * @param array $atts Атрибуты.
 * @return string
 */
function dzen_importer_video_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'     => '',
			'src'    => '',
			'poster' => '',
		),
		$atts,
		'dzen_video'
	);

	if ( '' !== $atts['src'] ) {
		$poster = '' !== $atts['poster'] ? ' poster="' . esc_url( $atts['poster'] ) . '"' : '';

		return '<figure class="wp-block-video"><video controls preload="metadata"' . $poster . '>'
			. '<source src="' . esc_url( $atts['src'] ) . '" type="video/mp4" /></video></figure>';
	}

	if ( '' === $atts['id'] ) {
		return '';
	}

	return '<figure class="wp-block-embed is-type-video is-provider-dzen"><div class="wp-block-embed__wrapper">'
		. '<iframe src="https://dzen.ru/embed/' . esc_attr( $atts['id'] ) . '" width="640" height="360"'
		. ' frameborder="0" allowfullscreen allow="autoplay; fullscreen; encrypted-media; picture-in-picture"></iframe>'
		. '</div></figure>';
}

add_shortcode( 'dzen_video', 'dzen_importer_video_shortcode' );

add_action(
	'plugins_loaded',
	function () {
		\DzenImporter\Admin::init();
	}
);
