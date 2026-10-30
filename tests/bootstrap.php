<?php
/**
 * Bootstrap для unit-тестов: минимальные заглушки WordPress.
 *
 * @package DzenImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['dzen_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['dzen_test_options'] )
			? $GLOBALS['dzen_test_options'][ $name ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['dzen_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['dzen_test_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = strip_tags( (string) $str );

		return trim( preg_replace( '~\s+~u', ' ', $str ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-state.php';
require_once dirname( __DIR__ ) . '/includes/class-text.php';
require_once dirname( __DIR__ ) . '/includes/class-http.php';
require_once dirname( __DIR__ ) . '/includes/class-feed-client.php';
require_once dirname( __DIR__ ) . '/includes/class-article-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-importer.php';
