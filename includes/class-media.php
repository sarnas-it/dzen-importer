<?php
/**
 * Загрузка картинок в медиатеку.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Скачивает изображения Дзена и заводит вложения.
 */
class Media {

	const META = '_dzen_source_image';
	const META_VIDEO = '_dzen_source_video';

	/**
	 * Импортирует картинку.
	 *
	 * @param string $url     URL картинки.
	 * @param int    $post_id ID записи.
	 * @param string $alt     Alt-текст.
	 * @param string $caption Подпись.
	 * @return int ID вложения или 0.
	 */
	public static function import( $url, $post_id = 0, $alt = '', $caption = '' ) {
		$url = trim( (string) $url );

		if ( '' === $url || ! preg_match( '~^https?://~i', $url ) ) {
			return 0;
		}

		$existing = self::find_by_url( $url );
		if ( $existing ) {
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 60 );

		if ( is_wp_error( $tmp ) ) {
			Logger::log( 'warning', 'Не удалось скачать картинку ' . $url . ': ' . $tmp->get_error_message() );

			return 0;
		}

		$name = self::filename( $url, $tmp );

		$file = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, $post_id, $caption );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			Logger::log( 'warning', 'Не удалось добавить картинку ' . $url . ': ' . $attachment_id->get_error_message() );

			return 0;
		}

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		update_post_meta( $attachment_id, self::META, $url );

		return (int) $attachment_id;
	}

	/**
	 * Скачивает видео в медиатеку.
	 *
	 * @param string $url     Прямая ссылка на MP4.
	 * @param int    $post_id ID записи.
	 * @param string $alt     Подпись.
	 * @param int    $max_mb  Максимальный размер, МБ.
	 * @return int ID вложения или 0.
	 */
	public static function import_video( $url, $post_id, $alt, $max_mb ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return 0;
		}

		$existing = self::find_by_meta( self::META_VIDEO, $url );
		if ( $existing ) {
			return $existing;
		}

		$head = wp_remote_head( $url, array( 'timeout' => 20 ) );

		if ( ! is_wp_error( $head ) ) {
			$length = (int) wp_remote_retrieve_header( $head, 'content-length' );

			if ( $length > 0 && $length > $max_mb * MB_IN_BYTES ) {
				Logger::log(
					'warning',
					sprintf( 'Видео пропущено: %.0f МБ больше лимита %d МБ (%s)', $length / MB_IN_BYTES, $max_mb, $url )
				);

				return 0;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 300 );

		if ( is_wp_error( $tmp ) ) {
			Logger::log( 'warning', 'Не удалось скачать видео ' . $url . ': ' . $tmp->get_error_message() );

			return 0;
		}

		$file = array(
			'name'     => 'dzen-video-' . substr( md5( $url ), 0, 12 ) . '.mp4',
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, $post_id, $alt );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			Logger::log( 'warning', 'Не удалось добавить видео ' . $url . ': ' . $attachment_id->get_error_message() );

			return 0;
		}

		update_post_meta( $attachment_id, self::META_VIDEO, $url );

		return (int) $attachment_id;
	}

	/**
	 * Ищет вложение по произвольному мета-ключу.
	 *
	 * @param string $meta_key Ключ.
	 * @param string $value    Значение.
	 * @return int
	 */
	private static function find_by_meta( $meta_key, $value ) {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $value,
					),
				),
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Ищет уже загруженное вложение по исходному URL.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	public static function find_by_url( $url ) {
		return self::find_by_meta( self::META, $url );
	}

	/**
	 * Имя файла с правильным расширением.
	 *
	 * @param string $url URL.
	 * @param string $tmp Путь к временному файлу.
	 * @return string
	 */
	private static function filename( $url, $tmp ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = sanitize_file_name( basename( $path ) );

		if ( '' === $base ) {
			$base = 'dzen-image';
		}

		if ( ! preg_match( '~\.(jpe?g|png|gif|webp|avif|svg)$~i', $base ) ) {
			$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$ext  = 'jpg';

			if ( is_array( $info ) && ! empty( $info['mime'] ) ) {
				$map = array(
					'image/jpeg' => 'jpg',
					'image/png'  => 'png',
					'image/gif'  => 'gif',
					'image/webp' => 'webp',
					'image/avif' => 'avif',
				);
				$ext = isset( $map[ $info['mime'] ] ) ? $map[ $info['mime'] ] : 'jpg';
			}

			$base .= '.' . $ext;
		}

		return $base;
	}
}
