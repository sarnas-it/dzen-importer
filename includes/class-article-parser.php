<?php
/**
 * Разбор страницы статьи Дзена.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Превращает SSR-HTML статьи в чистый HTML и список картинок.
 */
class ArticleParser {

	const ALLOWED = array(
		'p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote',
		'figure', 'figcaption', 'a', 'b', 'strong', 'em', 'i',
		'br', 'hr', 'img',
	);

	const DROPPED = array(
		'script', 'style', 'svg', 'button', 'iframe', 'form', 'noscript',
		'template', 'input', 'select', 'textarea', 'video', 'audio', 'source',
	);

	/**
	 * Разбирает страницу.
	 *
	 * @param string $html HTML страницы.
	 * @return array{title: string, html: string, images: array, description: string, og_image: string}
	 */
	public static function parse( $html ) {
		$result = array(
			'title'       => '',
			'html'        => '',
			'images'      => array(),
			'description' => '',
			'og_image'    => '',
		);

		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return $result;
		}

		$doc = new \DOMDocument();
		$use_errors = libxml_use_internal_errors( true );
		$loaded     = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $use_errors );

		if ( ! $loaded ) {
			return $result;
		}

		$xpath = new \DOMXPath( $doc );

		$result['title']    = self::text_of( $xpath, '//h1[@data-testid="article-title"]' );
		$result['og_image'] = self::attr_of( $xpath, '//meta[@property="og:image"]', 'content' );

		if ( '' === $result['title'] ) {
			$result['title'] = self::attr_of( $xpath, '//meta[@property="og:title"]', 'content' );
		}

		$body = $xpath->query( '//*[@itemprop="articleBody"]' );
		if ( ! $body || 0 === $body->length ) {
			$body = $xpath->query( '//*[@data-testid="article-body"]' );
		}

		if ( ! $body || 0 === $body->length ) {
			return $result;
		}

		$images = array();
		$html   = self::render_children( $body->item( 0 ), $images );

		$result['html']   = trim( $html );
		$result['images'] = array_values( array_unique( $images ) );

		return $result;
	}

	/**
	 * Рендерит дочерние узлы.
	 *
	 * @param \DOMNode $node   Узел.
	 * @param array    $images Сюда собираются URL картинок.
	 * @return string
	 */
	private static function render_children( \DOMNode $node, array &$images ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::render_node( $child, $images );
		}

		return $out;
	}

	/**
	 * Рендерит один узел.
	 *
	 * @param \DOMNode $node   Узел.
	 * @param array    $images Сюда собираются URL картинок.
	 * @return string
	 */
	private static function render_node( \DOMNode $node, array &$images ) {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return htmlspecialchars( (string) $node->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( (string) $node->nodeName );

		if ( in_array( $tag, self::DROPPED, true ) ) {
			return '';
		}

		if ( self::is_junk( $node ) ) {
			return '';
		}

		if ( 'img' === $tag ) {
			$src = trim( (string) $node->getAttribute( 'src' ) );
			if ( '' === $src || ! preg_match( '~^https?://~i', $src ) ) {
				return '';
			}

			$images[] = $src;

			$attrs  = ' src="' . esc_attr_pure( $src ) . '"';
			$alt    = trim( (string) $node->getAttribute( 'alt' ) );
			$title  = trim( (string) $node->getAttribute( 'title' ) );
			$width  = (int) $node->getAttribute( 'width' );
			$height = (int) $node->getAttribute( 'height' );

			if ( '' !== $alt ) {
				$attrs .= ' alt="' . esc_attr_pure( $alt ) . '"';
			}
			if ( '' !== $title ) {
				$attrs .= ' title="' . esc_attr_pure( $title ) . '"';
			}
			if ( $width > 0 && $height > 0 ) {
				$attrs .= ' width="' . $width . '" height="' . $height . '"';
			}

			return '<img' . $attrs . ' loading="lazy" />';
		}

		if ( 'br' === $tag ) {
			return '<br />';
		}

		if ( 'hr' === $tag ) {
			return '<hr />';
		}

		if ( 'figcaption' === $tag ) {
			$caption = trim( wp_strip_all_tags_pure( self::render_children( $node, $images ) ) );
			if ( '' === $caption || preg_match( '~^[\d\s.,/-]{1,10}$~', $caption ) ) {
				return '';
			}
		}

		$inner = self::render_children( $node, $images );

		if ( ! in_array( $tag, self::ALLOWED, true ) ) {
			return $inner;
		}

		$attrs = '';

		if ( 'a' === $tag ) {
			$href = trim( (string) $node->getAttribute( 'href' ) );
			if ( '' === $href || ! preg_match( '~^(https?://|/)~i', $href ) ) {
				return $inner;
			}

			$attrs = ' href="' . esc_attr_pure( $href ) . '"';

			if ( preg_match( '~^https?://~i', $href ) ) {
				$attrs .= ' rel="nofollow noopener" target="_blank"';
			}
		}

		if ( ! in_array( $tag, array( 'figure', 'img' ), true ) && '' === trim( wp_strip_all_tags_pure( $inner ) ) ) {
			return '';
		}

		return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
	}

	/**
	 * Мусорные блоки: карточки «читайте также», плашки и т.п.
	 *
	 * @param \DOMNode $node Узел.
	 * @return bool
	 */
	private static function is_junk( \DOMNode $node ) {
		$testid = (string) $node->getAttribute( 'data-testid' );
		if ( 'd2d-card' === $testid ) {
			return true;
		}

		$class = (string) $node->getAttribute( 'class' );
		if ( '' !== $class && preg_match( '~(^|\s)(d2d-card|card-part|card-horizontal|card-author)~', $class ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Разбирает страницу видео: заголовок, описание, обложка.
	 *
	 * @param string $html HTML страницы.
	 * @return array{title: string, description: string, image: string}
	 */
	public static function parse_video( $html ) {
		$result = array(
			'title'       => '',
			'description' => '',
			'image'       => '',
		);

		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return $result;
		}

		$title = self::meta_content( $html, 'og:title' );
		$title = preg_replace( '~\s*\|\s*Дзен\s*$~u', '', (string) $title );
		$title = preg_replace( '~\s*\|[^|]*$~u', '', (string) $title );

		$desc = self::meta_content( $html, 'og:description' );
		if ( '' === $desc ) {
			$desc = self::meta_content( $html, 'description' );
		}

		$desc = preg_replace( '~^Видео автора\s+«[^»]*»\s+в Дзене\s*\S*:?\s*~u', '', $desc );

		$result['title']       = trim( (string) $title );
		$result['description'] = trim( (string) $desc );
		$result['image']       = self::meta_content( $html, 'og:image' );

		return $result;
	}

	/**
	 * Достаёт content мета-тега без DOM-разбора.
	 *
	 * @param string $html  HTML.
	 * @param string $name  Имя свойства/тега.
	 * @return string
	 */
	private static function meta_content( $html, $name ) {
		$name = preg_quote( $name, '~' );

		if ( preg_match( '~<meta[^>]+(?:property|name)="' . $name . '"[^>]+content="([^"]*)"~i', $html, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		if ( preg_match( '~<meta[^>]+content="([^"]*)"[^>]+(?:property|name)="' . $name . '"~i', $html, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return '';
	}

	/**
	 * Текст первого найденного узла.
	 *
	 * @param \DOMXPath $xpath XPath.
	 * @param string    $query Запрос.
	 * @return string
	 */
	private static function text_of( \DOMXPath $xpath, $query ) {
		$nodes = $xpath->query( $query );
		if ( ! $nodes || 0 === $nodes->length ) {
			return '';
		}

		return trim( preg_replace( '~\s+~u', ' ', (string) $nodes->item( 0 )->textContent ) );
	}

	/**
	 * Атрибут первого найденного узла.
	 *
	 * @param \DOMXPath $xpath XPath.
	 * @param string    $query Запрос.
	 * @param string    $attr  Имя атрибута.
	 * @return string
	 */
	private static function attr_of( \DOMXPath $xpath, $query, $attr ) {
		$nodes = $xpath->query( $query );
		if ( ! $nodes || 0 === $nodes->length ) {
			return '';
		}

		return trim( (string) $nodes->item( 0 )->getAttribute( $attr ) );
	}
}

/**
 * Экранирование атрибута без зависимости от WordPress (нужно для unit-тестов).
 *
 * @param string $value Значение.
 * @return string
 */
function esc_attr_pure( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Удаление тегов без WordPress.
 *
 * @param string $value HTML.
 * @return string
 */
function wp_strip_all_tags_pure( $value ) {
	return trim( html_entity_decode( strip_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}
