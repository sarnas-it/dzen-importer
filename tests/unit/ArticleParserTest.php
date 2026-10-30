<?php
/**
 * Тесты парсера статьи.
 *
 * @package DzenImporter
 */

use DzenImporter\ArticleParser;
use PHPUnit\Framework\TestCase;

final class ArticleParserTest extends TestCase {

	private function fixture(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/article.html' );
	}

	public function test_extracts_title(): void {
		$parsed = ArticleParser::parse( $this->fixture() );

		$this->assertSame( 'Аудит учёта запасов по ФСБУ 5/2019 в торговой компании', $parsed['title'] );
	}

	public function test_extracts_clean_body(): void {
		$parsed = ArticleParser::parse( $this->fixture() );

		$this->assertStringContainsString( '<p>', $parsed['html'] );
		$this->assertStringContainsString( '<h2>', $parsed['html'] );
		$this->assertStringContainsString( '<li>', $parsed['html'] );
		$this->assertGreaterThan( 10000, strlen( $parsed['html'] ) );
	}

	public function test_drops_attributes_and_scripts(): void {
		$parsed = ArticleParser::parse( $this->fixture() );

		$this->assertStringNotContainsString( 'class="', $parsed['html'] );
		$this->assertStringNotContainsString( 'style="', $parsed['html'] );
		$this->assertStringNotContainsString( '<script', $parsed['html'] );
		$this->assertStringNotContainsString( '<svg', $parsed['html'] );
		$this->assertStringNotContainsString( '<button', $parsed['html'] );
	}

	public function test_collects_images_in_order(): void {
		$parsed = ArticleParser::parse( $this->fixture() );

		$this->assertNotEmpty( $parsed['images'] );
		$this->assertStringStartsWith( 'https://avatars.dzeninfra.ru/', $parsed['images'][0] );
	}

	public function test_og_image(): void {
		$parsed = ArticleParser::parse( $this->fixture() );

		$this->assertStringContainsString( 'avatars.dzeninfra.ru', $parsed['og_image'] );
	}

	public function test_stub_page_returns_empty(): void {
		$parsed = ArticleParser::parse( '<html><body><script>window.location="sso"</script></body></html>' );

		$this->assertSame( '', $parsed['html'] );
		$this->assertSame( '', $parsed['title'] );
	}

	public function test_junk_cards_are_dropped(): void {
		$html = '<html><body><h1 data-testid="article-title">T</h1>'
			. '<div itemprop="articleBody">'
			. '<p>Текст</p>'
			. '<article data-testid="d2d-card"><a href="https://dzen.ru/a/other">Реклама</a></article>'
			. '</div></body></html>';

		$parsed = ArticleParser::parse( $html );

		$this->assertStringContainsString( 'Текст', $parsed['html'] );
		$this->assertStringNotContainsString( 'Реклама', $parsed['html'] );
	}
}
