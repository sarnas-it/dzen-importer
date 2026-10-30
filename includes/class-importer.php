<?php
/**
 * Создание записей из публикаций Дзена.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Импортирует одну публикацию.
 */
class Importer {

	const META_ID       = '_dzen_publication_id';
	const META_URL      = '_dzen_source_url';
	const META_IMPORTED = '_dzen_imported_at';

	/**
	 * Транспорт для запросов.
	 *
	 * @var callable|null
	 */
	private $http;

	/**
	 * @param callable|null $http Подменяемый транспорт.
	 */
	public function __construct( $http = null ) {
		$this->http = $http;
	}

	/**
	 * Проверяет, импортирована ли публикация.
	 *
	 * @param string $publication_id ID публикации.
	 * @return bool
	 */
	public static function already_imported( $publication_id ) {
		$found = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::META_ID,
						'value' => (string) $publication_id,
					),
				),
			)
		);

		return ! empty( $found );
	}

	/**
	 * Импортирует публикацию.
	 *
	 * @param array $item     Нормализованный элемент фида.
	 * @param array $settings Настройки.
	 * @return int|\WP_Error ID записи или ошибка.
	 */
	public function import_item( array $item, array $settings ) {
		$publication_id = isset( $item['id'] ) ? (string) $item['id'] : '';

		if ( '' === $publication_id ) {
			return new \WP_Error( 'dzen_no_id', 'У публикации нет ID.' );
		}

		if ( self::already_imported( $publication_id ) ) {
			return 0;
		}

		if ( ! empty( $item['is_video'] ) ) {
			Logger::log( 'info', sprintf( 'Пропущено видео/шортс «%s»', $item['title'] ) );

			return 0;
		}

		$html = '';

		try {
			$html = Http::get( $item['url'], Http::CRAWLER_UA, 30, $this->http );
		} catch ( HttpException $e ) {
			return new \WP_Error( 'dzen_http', 'Не удалось загрузить статью: ' . $e->getMessage() );
		}

		$parsed = ArticleParser::parse( $html );

		if ( '' === $parsed['html'] ) {
			return new \WP_Error( 'dzen_empty', 'Дзен вернул страницу без текста статьи (вероятно, сработала защита).' );
		}

		$title = '' !== $parsed['title'] ? $parsed['title'] : $item['title'];
		$desc  = Text::description( $parsed['html'], 160 );

		// Картинки качаем до создания записи: wp_update_post сбрасывает дату у черновика.
		$images_map = $this->import_images( $parsed['images'], 0, $title );

		$content = self::build_content( $parsed['html'], $images_map, $settings, $item );

		// Обложка статьи уходит в миниатюру — убираем её дубль из начала текста.
		if ( ! empty( $parsed['images'][0] ) && ! empty( $images_map[ $parsed['images'][0] ] ) ) {
			$content = self::strip_leading_image( $content, $images_map[ $parsed['images'][0] ] );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => isset( $settings['post_status'] ) && 'publish' === $settings['post_status'] ? 'publish' : 'draft',
				'post_title'    => $title,
				'post_name'     => Text::slug( $title, 'dzen-' . $publication_id ),
				'post_content'  => $content,
				'post_excerpt'  => $desc,
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $item['ts'] ) ),
				'post_author'   => self::author_id( $settings ),
				'post_category' => self::categories( $settings ),
				'meta_input'    => array(
					self::META_ID       => $publication_id,
					self::META_URL      => (string) $item['url'],
					self::META_IMPORTED => time(),
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		$this->attach_images( $images_map, $post_id );
		$this->set_thumbnail( $post_id, $parsed, $item, $title );

		Seo::apply(
			$post_id,
			array(
				'title'       => $title,
				'description' => $desc,
				'keyword'     => $title,
			)
		);

		Logger::log( 'info', sprintf( 'Импортирована «%s» (%s)', $title, $item['url'] ) );

		return $post_id;
	}

	/**
	 * Привязывает уже скачанные вложения к записи.
	 *
	 * @param array $images_map Карта внешний URL => локальный URL.
	 * @param int   $post_id    ID записи.
	 * @return void
	 */
	private function attach_images( array $images_map, $post_id ) {
		foreach ( array_keys( $images_map ) as $remote ) {
			$attachment_id = Media::find_by_url( $remote );

			if ( $attachment_id ) {
				wp_update_post(
					array(
						'ID'          => $attachment_id,
						'post_parent' => $post_id,
					)
				);
			}
		}
	}

	/**
	 * Скачивает картинки статьи.
	 *
	 * @param array  $urls    URL картинок.
	 * @param int    $post_id ID записи.
	 * @param string $alt     Alt-текст.
	 * @return array Карта внешний URL => локальный URL.
	 */
	private function import_images( array $urls, $post_id, $alt ) {
		$map = array();

		foreach ( $urls as $url ) {
			$attachment_id = Media::import( $url, $post_id, $alt );
			if ( ! $attachment_id ) {
				continue;
			}

			$local = wp_get_attachment_url( $attachment_id );
			if ( $local ) {
				$map[ $url ] = $local;
			}

			usleep( 200000 );
		}

		return $map;
	}

	/**
	 * Ставит миниатюру.
	 *
	 * @param int   $post_id ID записи.
	 * @param array $parsed  Разобранная статья.
	 * @param array $item    Элемент фида.
	 * @param string $title  Заголовок.
	 * @return void
	 */
	private function set_thumbnail( $post_id, array $parsed, array $item, $title ) {
		$candidates = array();

		// Сначала настоящая обложка статьи, потом обложка из фида, и только затем
		// сгенерированная OG-картинка Дзена (на ней впечатан заголовок).
		if ( ! empty( $parsed['images'] ) ) {
			$candidates[] = $parsed['images'][0];
		}

		if ( ! empty( $item['image'] ) ) {
			$candidates[] = $item['image'];
		}

		if ( ! empty( $parsed['og_image'] ) ) {
			$candidates[] = $parsed['og_image'];
		}

		foreach ( array_unique( $candidates ) as $url ) {
			$attachment_id = Media::import( $url, $post_id, $title );
			if ( $attachment_id ) {
				set_post_thumbnail( $post_id, $attachment_id );

				return;
			}
		}
	}

	/**
	 * Собирает итоговый HTML записи.
	 *
	 * @param string $html      HTML статьи.
	 * @param array  $images    Карта внешний URL => локальный URL.
	 * @param array  $settings  Настройки.
	 * @param array  $item      Элемент фида.
	 * @return string
	 */
	public static function build_content( $html, array $images, array $settings, array $item ) {
		$content = (string) $html;

		if ( ! empty( $images ) ) {
			foreach ( $images as $remote => $local ) {
				$content = str_replace(
					'src="' . esc_attr_pure( $remote ) . '"',
					'src="' . esc_attr_pure( $local ) . '"',
					$content
				);
			}
		}

		if ( ! empty( $settings['source_link'] ) && ! empty( $item['url'] ) ) {
			$rel  = ! empty( $settings['source_link_nofollow'] ) ? ' rel="nofollow noopener"' : ' rel="noopener"';
			$text = isset( $settings['source_text'] ) && '' !== $settings['source_text'] ? $settings['source_text'] : 'Источник: Дзен';

			$content .= "\n" . '<p class="dzen-importer-source"><a href="' . esc_url( $item['url'] ) . '"'
				. $rel . ' target="_blank">' . esc_html( $text ) . '</a></p>';
		}

		return wp_kses( $content, self::allowed_tags() );
	}

	/**
	 * Убирает ведущую картинку, если она же стала миниатюрой.
	 *
	 * @param string $html HTML записи.
	 * @param string $url  Локальный URL миниатюры.
	 * @return string
	 */
	public static function strip_leading_image( $html, $url ) {
		$html = (string) $html;
		$url  = (string) $url;

		if ( '' === $url ) {
			return $html;
		}

		if ( preg_match( '~^\s*<figure[^>]*>.*?</figure>~s', $html, $match )
			&& false !== strpos( $match[0], 'src="' . esc_attr_pure( $url ) . '"' ) ) {
			return (string) preg_replace( '~^\s*<figure[^>]*>.*?</figure>~s', '', $html, 1 );
		}

		$pattern = '~^\s*<img[^>]*src="' . preg_quote( esc_attr_pure( $url ), '~' ) . '"[^>]*/?>~';

		return (string) preg_replace( $pattern, '', $html, 1 );
	}

	/**
	 * Белый список тегов для тела записи.
	 *
	 * @return array
	 */
	public static function allowed_tags() {
		return array(
			'p'          => array( 'class' => true ),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array(),
			'figure'     => array(),
			'figcaption' => array(),
			'br'         => array(),
			'hr'         => array(),
			'b'          => array(),
			'strong'     => array(),
			'em'         => array(),
			'i'          => array(),
			'a'          => array(
				'href'   => true,
				'rel'    => true,
				'target' => true,
				'title'  => true,
			),
			'img'        => array(
				'src'     => true,
				'alt'     => true,
				'title'   => true,
				'width'   => true,
				'height'  => true,
				'loading' => true,
			),
		);
	}

	/**
	 * Автор записи.
	 *
	 * @param array $settings Настройки.
	 * @return int
	 */
	private static function author_id( array $settings ) {
		$author = isset( $settings['author_id'] ) ? (int) $settings['author_id'] : 0;

		if ( $author && get_userdata( $author ) ) {
			return $author;
		}

		return 1;
	}

	/**
	 * Рубрики записи.
	 *
	 * @param array $settings Настройки.
	 * @return array
	 */
	private static function categories( array $settings ) {
		$category = isset( $settings['category_id'] ) ? (int) $settings['category_id'] : 0;

		return $category ? array( $category ) : array();
	}
}
