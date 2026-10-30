<?php
/**
 * Удаление плагина: чистит только свои настройки, записи остаются.
 *
 * @package DzenImporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dzen_importer_settings' );
delete_option( 'dzen_importer_state' );
delete_option( 'dzen_importer_log' );

delete_transient( 'dzen_importer_lock' );
delete_transient( 'dzen_importer_backfill_lock' );
delete_transient( 'dzen_importer_notice' );
delete_transient( 'dzen_importer_activation_redirect' );

wp_clear_scheduled_hook( 'dzen_importer_run' );
