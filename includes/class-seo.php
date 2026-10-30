<?php
/**
 * Интеграция с SEO-плагинами.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Пишет SEO-поля в выбранный плагин или выводит свои мета-теги.
 */
class Seo {

	const META_KEY = '_dzen_seo_description';

	/**
	 * Определяет активный SEO-плагин.
	 *
	 * @return string yoast|rankmath|aioseo|seopress|slimseo|none
	 */
	public static function detect() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}

		if ( class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}

		if ( defined( 'SLIM_SEO_VERSION' ) ) {
			return 'slimseo';
		}

		return 'none';
	}

	/**
	 * Плагин, который реально используется.
	 *
	 * @param array $settings Настройки.
	 * @return string
	 */
	public static function active( array $settings ) {
		$choice = isset( $settings['seo_plugin'] ) ? $settings['seo_plugin'] : 'auto';

		if ( 'auto' === $choice ) {
			return self::detect();
		}

		return Settings::is_known_seo_plugin( $choice ) ? $choice : 'none';
	}

	/**
	 * Пишет SEO-поля.
	 *
	 * @param int   $post_id ID записи.
	 * @param array $data    title, description, keyword.
	 * @return void
	 */
	public static function apply( $post_id, array $data ) {
		$title       = isset( $data['title'] ) ? (string) $data['title'] : '';
		$description = isset( $data['description'] ) ? (string) $data['description'] : '';
		$keyword     = isset( $data['keyword'] ) ? (string) $data['keyword'] : '';

		$plugin = self::active( Settings::get() );

		switch ( $plugin ) {
			case 'yoast':
				if ( '' !== $title ) {
					update_post_meta( $post_id, '_yoast_wpseo_title', $title );
				}
				if ( '' !== $description ) {
					update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
				}
				if ( '' !== $keyword ) {
					update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
				}
				break;

			case 'rankmath':
				if ( '' !== $title ) {
					update_post_meta( $post_id, 'rank_math_title', $title );
				}
				if ( '' !== $description ) {
					update_post_meta( $post_id, 'rank_math_description', $description );
				}
				if ( '' !== $keyword ) {
					update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
				}
				break;

			case 'aioseo':
				if ( '' !== $title ) {
					update_post_meta( $post_id, '_aioseo_title', $title );
				}
				if ( '' !== $description ) {
					update_post_meta( $post_id, '_aioseo_description', $description );
				}
				break;

			case 'seopress':
				if ( '' !== $title ) {
					update_post_meta( $post_id, '_seopress_titles_title', $title );
				}
				if ( '' !== $description ) {
					update_post_meta( $post_id, '_seopress_titles_desc', $description );
				}
				break;

			case 'slimseo':
				$meta = get_post_meta( $post_id, 'slim_seo', true );
				$meta = is_array( $meta ) ? $meta : array();

				if ( '' !== $title ) {
					$meta['title'] = $title;
				}
				if ( '' !== $description ) {
					$meta['description'] = $description;
				}

				update_post_meta( $post_id, 'slim_seo', $meta );
				break;

			default:
				if ( '' !== $description ) {
					update_post_meta( $post_id, self::META_KEY, $description );
				}
				break;
		}
	}

	/**
	 * Свои мета-теги, если SEO-плагина нет.
	 *
	 * @return void
	 */
	public static function render_fallback_meta() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$settings = Settings::get();
		if ( 'none' !== self::active( $settings ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id || '' === (string) get_post_meta( $post_id, '_dzen_publication_id', true ) ) {
			return;
		}

		$description = (string) get_post_meta( $post_id, self::META_KEY, true );
		if ( '' === $description ) {
			return;
		}

		printf(
			'<meta name="description" content="%s" />' . "\n",
			esc_attr( $description )
		);
		printf(
			'<meta property="og:description" content="%s" />' . "\n",
			esc_attr( $description )
		);
		printf(
			'<meta property="og:type" content="article" />' . "\n"
		);

		$image = get_the_post_thumbnail_url( $post_id, 'large' );
		if ( $image ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
		}
	}
}
