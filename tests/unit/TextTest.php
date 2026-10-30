<?php
/**
 * Тесты транслитерации и описаний.
 *
 * @package DzenImporter
 */

use DzenImporter\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase {

	public function test_slug_transliterates_russian_and_keeps_numbers(): void {
		$this->assertSame(
			'audit-ucheta-zapasov-po-fsbu-5-2019',
			Text::slug( 'Аудит учёта запасов по ФСБУ 5/2019' )
		);
	}

	public function test_slug_drops_punctuation_and_emoji(): void {
		$this->assertSame( 'elka-100', Text::slug( '«Ёлка» — 100% 🎄' ) );
	}

	public function test_slug_never_empty(): void {
		$this->assertMatchesRegularExpression( '~^dzen-\d{14}$~', Text::slug( '🎄🎄🎄' ) );
	}

	public function test_slug_uses_fallback(): void {
		$this->assertSame( 'privet', Text::slug( '🎄', 'Привет' ) );
	}

	public function test_description_strips_tags_and_collapses_spaces(): void {
		$this->assertSame( 'Привет, мир!', Text::description( '<p>Привет,   <b>мир</b>!</p>' ) );
	}

	public function test_description_is_limited_and_ends_with_ellipsis(): void {
		$text = Text::description( str_repeat( 'слово ', 100 ), 60 );

		$this->assertLessThanOrEqual( 61, mb_strlen( $text, 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $text );
	}

	public function test_description_of_empty_html_is_empty(): void {
		$this->assertSame( '', Text::description( '<p> </p>' ) );
	}
}
