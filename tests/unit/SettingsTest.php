<?php
/**
 * Тесты настроек.
 *
 * @package DzenImporter
 */

use DzenImporter\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['dzen_test_options'] = array();
	}

	/**
	 * @dataProvider channel_provider
	 */
	public function test_channel_slug( string $input, string $expected ): void {
		$this->assertSame( $expected, Settings::channel_slug( $input ) );
	}

	public function channel_provider(): array {
		return array(
			'url'        => array( 'https://dzen.ru/radar-c.ru', 'radar-c.ru' ),
			'url query'  => array( 'https://dzen.ru/radar-c.ru?utm=1', 'radar-c.ru' ),
			'url slash'  => array( 'https://dzen.ru/radar-c.ru/', 'radar-c.ru' ),
			'at sign'    => array( '@radar-c.ru', 'radar-c.ru' ),
			'bare host'  => array( 'dzen.ru/radar-c.ru', 'radar-c.ru' ),
			'zen host'   => array( 'https://zen.yandex.ru/radar-c.ru', 'radar-c.ru' ),
			'slug'       => array( 'radar-c.ru', 'radar-c.ru' ),
			'empty'      => array( '   ', '' ),
			'with space' => array( ' https://dzen.ru/radar-c.ru ', 'radar-c.ru' ),
		);
	}

	public function test_defaults_are_merged_with_saved_values(): void {
		update_option( Settings::OPTION, array( 'channel' => 'radar-c.ru' ) );

		$settings = Settings::get();

		$this->assertSame( 'radar-c.ru', $settings['channel'] );
		$this->assertSame( 10, $settings['interval'] );
		$this->assertSame( 'auto', $settings['seo_plugin'] );
	}

	public function test_update_clamps_interval(): void {
		$this->assertSame( 5, Settings::update( array( 'interval' => 1 ) )['interval'] );
		$this->assertSame( 1440, Settings::update( array( 'interval' => 99999 ) )['interval'] );
	}

	public function test_update_rejects_unknown_seo_plugin(): void {
		$this->assertSame( 'auto', Settings::update( array( 'seo_plugin' => 'evil' ) )['seo_plugin'] );
	}

	public function test_update_rejects_unknown_status(): void {
		$this->assertSame( 'publish', Settings::update( array( 'post_status' => 'private' ) )['post_status'] );
	}

	public function test_update_clamps_batches(): void {
		$settings = Settings::update(
			array(
				'max_per_run'    => 0,
				'backfill_batch' => 500,
			)
		);

		$this->assertSame( 1, $settings['max_per_run'] );
		$this->assertSame( 50, $settings['backfill_batch'] );
	}

	public function test_update_keeps_source_link_toggles(): void {
		$on = Settings::update( array( 'source_link' => '1', 'source_link_nofollow' => '1' ) );
		$this->assertSame( 1, $on['source_link'] );
		$this->assertSame( 1, $on['source_link_nofollow'] );

		$off = Settings::update( array() );
		$this->assertSame( 0, $off['source_link'] );
		$this->assertSame( 0, $off['source_link_nofollow'] );
	}

	public function test_update_keeps_default_source_text(): void {
		$this->assertSame( 'Источник: Дзен', Settings::update( array( 'source_text' => '' ) )['source_text'] );
	}
}
