<?php
/**
 * Тесты импортёра (чистые методы).
 *
 * @package DzenImporter
 */

use DzenImporter\Importer;
use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase {

	public function test_strips_leading_figure_with_cover(): void {
		$html = '<figure><img src="https://site.test/cover.jpg" /></figure><p>Текст</p>';

		$this->assertSame(
			'<p>Текст</p>',
			Importer::strip_leading_image( $html, 'https://site.test/cover.jpg' )
		);
	}

	public function test_strips_leading_img_with_cover(): void {
		$html = '<img src="https://site.test/cover.jpg" loading="lazy" /><p>Текст</p>';

		$this->assertSame(
			'<p>Текст</p>',
			Importer::strip_leading_image( $html, 'https://site.test/cover.jpg' )
		);
	}

	public function test_keeps_other_images(): void {
		$html = '<figure><img src="https://site.test/other.jpg" /></figure><p>Текст</p>';

		$this->assertSame( $html, Importer::strip_leading_image( $html, 'https://site.test/cover.jpg' ) );
	}

	public function test_keeps_image_not_at_start(): void {
		$html = '<p>Текст</p><figure><img src="https://site.test/cover.jpg" /></figure>';

		$this->assertSame( $html, Importer::strip_leading_image( $html, 'https://site.test/cover.jpg' ) );
	}
}
