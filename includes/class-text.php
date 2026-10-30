<?php
/**
 * Работа с текстом: слаги и описания.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Транслитерация, слаги, мета-описания.
 */
class Text {

	/**
	 * Таблица транслитерации.
	 *
	 * @var array
	 */
	private static $map = array(
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
		'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
		'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
		'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
		'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
		'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g',
	);

	/**
	 * Строит слаг из заголовка.
	 *
	 * @param string $title    Заголовок.
	 * @param string $fallback Запасной вариант.
	 * @return string
	 */
	public static function slug( $title, $fallback = '' ) {
		$slug = self::translit( (string) $title );
		$slug = strtolower( $slug );
		$slug = preg_replace( '~[^a-z0-9]+~', '-', $slug );
		$slug = trim( (string) $slug, '-' );

		if ( '' === $slug && '' !== $fallback ) {
			$slug = self::slug( $fallback );
		}

		if ( '' === $slug ) {
			$slug = 'dzen-' . gmdate( 'YmdHis' );
		}

		return $slug;
	}

	/**
	 * Транслитерация кириллицы.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	public static function translit( $text ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strtolower' ) ) {
			$text = mb_strtolower( $text, 'UTF-8' );
		} else {
			$text = strtolower( $text );
		}

		return strtr( $text, self::$map );
	}

	/**
	 * Короткое текстовое описание из HTML.
	 *
	 * @param string $html  HTML.
	 * @param int    $limit Максимум символов.
	 * @return string
	 */
	public static function description( $html, $limit = 160 ) {
		$text = preg_replace( '~<(script|style)[^>]*>.*?</\1>~is', ' ', (string) $html );
		$text = preg_replace( '~<figcaption[^>]*>.*?</figcaption>~is', ' ', (string) $text );
		$text = preg_replace( '~<[^>]+>~', ' ', (string) $text );
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '~\s+~u', ' ', $text );
		$text = preg_replace( '~\s+([.,;:!?…])~u', '$1', (string) $text );
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) <= $limit ) {
			return $text;
		}

		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
		$pos = strrpos( $cut, ' ' );

		if ( false !== $pos && $pos > (int) ( $limit * 0.6 ) ) {
			$cut = substr( $cut, 0, $pos );
		}

		return rtrim( $cut, " \t\n\r\0\x0B.,;:!?-—" ) . '…';
	}
}
