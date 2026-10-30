<?php
/**
 * Тесты импорта видео.
 *
 * @package DzenImporter
 */

use DzenImporter\ArticleParser;
use DzenImporter\FeedClient;
use DzenImporter\Importer;
use PHPUnit\Framework\TestCase;

final class VideoTest extends TestCase {

	private function item(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/video-item.json' ), true );
	}

	public function test_feed_url_has_tab(): void {
		$this->assertStringContainsString( 'tab=longs', FeedClient::feed_url( 'radar-c.ru', 'longs' ) );
		$this->assertStringNotContainsString( 'tab=', FeedClient::feed_url( 'radar-c.ru' ) );
	}

	public function test_normalize_video_item(): void {
		$item = FeedClient::normalize( $this->item() );

		$this->assertTrue( $item['is_video'] );
		$this->assertSame( 'gif', $item['type'] );
		$this->assertSame( 'oVkZ7nvkKAAA', $item['video']['embed_id'] );
		$this->assertSame( 569, $item['video']['duration'] );
		$this->assertSame( 'https://vd407.okcdn.ru/medium.mp4', $item['video']['streams']['medium'] );
		$this->assertSame( 'https://dzen.ru/video/watch/698f307f6c7fce219ef17553', $item['url'] );
	}

	public function test_article_item_is_not_video(): void {
		$item = FeedClient::normalize(
			array(
				'publication_object_id' => '1',
				'title'                 => 'Статья',
				'share_link'            => 'https://dzen.ru/a/abc',
				'publication_date'      => '5',
			)
		);

		$this->assertFalse( $item['is_video'] );
		$this->assertSame( array(), $item['video'] );
	}

	public function test_parse_video_page(): void {
		$parsed = ArticleParser::parse_video(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/video-page.html' )
		);

		$this->assertSame( 'Часть 6. Аутсорсинг функций ревизора СНТ', $parsed['title'] );
		$this->assertSame(
			'Добрый день дорогие подписчики! Сегодня расскажу об аутсорсинге функций ревизора СНТ.',
			$parsed['description']
		);
		$this->assertStringContainsString( 'avatars.dzeninfra.ru', $parsed['image'] );
	}

	public function test_pick_stream_prefers_requested_quality(): void {
		$streams = array(
			'low'    => 'low.mp4',
			'medium' => 'medium.mp4',
			'high'   => 'high.mp4',
		);

		$this->assertSame( 'high.mp4', Importer::pick_stream( $streams, 'high' ) );
		$this->assertSame( 'medium.mp4', Importer::pick_stream( $streams, 'medium' ) );
	}

	public function test_pick_stream_falls_back_when_quality_missing(): void {
		$streams = array( 'low' => 'low.mp4' );

		$this->assertSame( 'low.mp4', Importer::pick_stream( $streams, 'fullhd' ) );
		$this->assertSame( '', Importer::pick_stream( array(), 'medium' ) );
	}
}
