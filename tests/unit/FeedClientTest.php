<?php
/**
 * Тесты клиента фида.
 *
 * @package DzenImporter
 */

use DzenImporter\FeedClient;
use DzenImporter\FeedException;
use PHPUnit\Framework\TestCase;

final class FeedClientTest extends TestCase {

	private function fixture(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/feed-page1.json' );
	}

	public function test_parses_fixture(): void {
		$client = new FeedClient(
			function () {
				return $this->fixture();
			}
		);

		$page = $client->page( 'radar-c.ru' );

		$this->assertCount( 20, $page['items'] );
		$this->assertNotEmpty( $page['more'] );
		$this->assertSame( 'Аудиторская компания Радар-Консалтинг', $page['channel_title'] );

		$first = $page['items'][0];
		$this->assertSame( '6ad9ba128872795b738da2cc', $first['id'] );
		$this->assertSame( 'Аудит учёта запасов по ФСБУ 5/2019 в торговой компании', $first['title'] );
		$this->assertSame( 'https://dzen.ru/a/atm6EohyeVtzjaLM', $first['url'] );
		$this->assertSame( 1792653654, $first['ts'] );
		$this->assertStringStartsWith( 'https://avatars.dzeninfra.ru/', $first['image'] );
		$this->assertFalse( $first['is_video'] );
	}

	public function test_url_contains_channel_and_lang(): void {
		$url = FeedClient::feed_url( 'radar-c.ru' );

		$this->assertStringContainsString( 'channel_name=radar-c.ru', $url );
		$this->assertStringContainsString( 'lang=ru', $url );
	}

	public function test_html_instead_of_json_throws(): void {
		$client = new FeedClient(
			function () {
				return '<html><body>captcha</body></html>';
			}
		);

		$this->expectException( FeedException::class );
		$client->page( 'radar-c.ru' );
	}

	public function test_items_without_title_are_skipped(): void {
		$data = wp_json_encode_stub(
			array(
				'items' => array(
					array( 'publication_object_id' => '1', 'share_link' => 'https://dzen.ru/a/x' ),
					array( 'publication_object_id' => '2', 'title' => 'Есть', 'share_link' => 'https://dzen.ru/a/y', 'publication_date' => '10' ),
				),
				'more'  => array( 'link' => '' ),
			)
		);

		$client = new FeedClient(
			function () use ( $data ) {
				return $data;
			}
		);

		$page = $client->page( 'radar-c.ru' );

		$this->assertCount( 1, $page['items'] );
		$this->assertSame( '2', $page['items'][0]['id'] );
		$this->assertNull( $page['more'] );
	}

	public function test_video_is_flagged(): void {
		$item = FeedClient::normalize(
			array(
				'publication_object_id' => '3',
				'title'                 => 'Шортс',
				'share_link'            => 'https://dzen.ru/video/x',
				'item_type'             => 'short',
				'publication_date'      => '5',
			)
		);

		$this->assertTrue( $item['is_video'] );
	}
}

/**
 * json_encode без WordPress.
 *
 * @param array $data Данные.
 * @return string
 */
function wp_json_encode_stub( array $data ) {
	return (string) json_encode( $data );
}
