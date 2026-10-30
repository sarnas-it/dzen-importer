<?php
/**
 * Клиент JSON-фида Дзена.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ошибка обращения к фиду.
 */
class FeedException extends \Exception {
}

/**
 * Читает публичный фид канала.
 *
 * Дзен не отдаёт RSS, поэтому используется внутренний JSON-фид,
 * который открывается без авторизации.
 */
class FeedClient {

	const FEED_URL = 'https://dzen.ru/api/v3/launcher/export';

	/**
	 * Обычный браузерный UA: с ним фид корректно листается.
	 */
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

	/**
	 * HTTP-транспорт.
	 *
	 * @var callable|null
	 */
	private $http;

	/**
	 * @param callable|null $http Функция вида fn(string $url): string, бросает исключение при ошибке.
	 */
	public function __construct( $http = null ) {
		$this->http = $http;
	}

	/**
	 * Загружает страницу фида.
	 *
	 * @param string      $channel Слаг канала.
	 * @param string|null $more_url Ссылка на следующую страницу.
	 * @return array{items: array, more: string|null, channel_title: string}
	 * @throws FeedException Если фид недоступен или вернул не JSON.
	 */
	public function page( $channel, $more_url = null, $tab = '' ) {
		$url = $more_url ? $more_url : self::feed_url( $channel, $tab );

		$body = $this->request( $url );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			throw new FeedException( 'Фид вернул не JSON или не содержит публикаций.' );
		}

		$items = array();
		foreach ( $data['items'] as $raw ) {
			$item = self::normalize( $raw );
			if ( $item ) {
				$items[] = $item;
			}
		}

		$more = null;
		if ( isset( $data['more']['link'] ) && is_string( $data['more']['link'] ) && '' !== $data['more']['link'] ) {
			$more = $data['more']['link'];
		}

		return array(
			'items'         => $items,
			'more'          => $more,
			'channel_title' => self::channel_title( $data, $items ),
		);
	}

	/**
	 * Собирает URL первой страницы.
	 *
	 * @param string $channel Слаг.
	 * @return string
	 */
	public static function feed_url( $channel, $tab = '' ) {
		$url = self::FEED_URL . '?channel_name=' . rawurlencode( (string) $channel ) . '&country_code=ru&lang=ru';

		if ( '' !== $tab ) {
			$url .= '&tab=' . rawurlencode( (string) $tab );
		}

		return $url;
	}

	/**
	 * Приводит элемент фида к внутреннему виду.
	 *
	 * @param mixed $raw Элемент из JSON.
	 * @return array|null
	 */
	public static function normalize( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$id = '';
		if ( isset( $raw['publication_object_id'] ) ) {
			$id = (string) $raw['publication_object_id'];
		} elseif ( isset( $raw['id'] ) ) {
			$id = (string) $raw['id'];
		}

		$title = isset( $raw['title'] ) ? trim( (string) $raw['title'] ) : '';
		$url   = isset( $raw['share_link'] ) ? (string) $raw['share_link'] : '';

		if ( '' === $url && isset( $raw['link'] ) ) {
			$url = (string) $raw['link'];
		}

		$url = preg_replace( '~\?.*$~', '', $url );

		if ( '' === $id || '' === $title || '' === $url ) {
			return null;
		}

		$type = isset( $raw['item_type'] ) ? strtolower( (string) $raw['item_type'] ) : '';
		$card = isset( $raw['card_type'] ) ? strtolower( (string) $raw['card_type'] ) : '';
		$is_video = 'gif' === $type || false !== strpos( $type, 'video' ) || false !== strpos( $type, 'short' )
			|| false !== strpos( $card, 'video' ) || false !== strpos( $card, 'short' )
			|| ! empty( $raw['video'] );

		$video = array();

		if ( $is_video && isset( $raw['video'] ) && is_array( $raw['video'] ) ) {
			$raw_video = $raw['video'];

			$streams = array();
			if ( isset( $raw_video['oneVideoStreams'] ) && is_array( $raw_video['oneVideoStreams'] ) ) {
				foreach ( $raw_video['oneVideoStreams'] as $stream ) {
					if ( ! empty( $stream['type'] ) && ! empty( $stream['url'] ) ) {
						$streams[ (string) $stream['type'] ] = (string) $stream['url'];
					}
				}
			}

			$video = array(
				'embed_id' => isset( $raw_video['video_info']['video_content_id'] ) ? (string) $raw_video['video_info']['video_content_id'] : '',
				'duration' => isset( $raw_video['duration'] ) ? (int) $raw_video['duration'] : 0,
				'width'    => isset( $raw_video['width'] ) ? (int) $raw_video['width'] : 0,
				'height'   => isset( $raw_video['height'] ) ? (int) $raw_video['height'] : 0,
				'poster'   => isset( $raw_video['previews']['852'] ) ? (string) $raw_video['previews']['852'] : '',
				'streams'  => $streams,
			);
		}

		return array(
			'id'       => $id,
			'title'    => $title,
			'teaser'   => isset( $raw['text'] ) ? (string) $raw['text'] : '',
			'url'      => $url,
			'image'    => isset( $raw['image'] ) ? (string) $raw['image'] : '',
			'ts'       => isset( $raw['publication_date'] ) ? (int) $raw['publication_date'] : 0,
			'is_video' => $is_video,
			'video'    => $video,
			'type'     => $type,
		);
	}

	/**
	 * Название канала из ответа.
	 *
	 * @param array $data  JSON фида.
	 * @param array $items Нормализованные публикации.
	 * @return string
	 */
	private static function channel_title( array $data, array $items ) {
		$candidates = array(
			array( 'channel', 'source', 'title' ),
			array( 'channel', 'title' ),
			array( 'merged_channel_profile', 'title' ),
		);

		foreach ( $candidates as $path ) {
			$value = $data;

			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					$value = null;
					break;
				}

				$value = $value[ $key ];
			}

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		if ( isset( $data['items'][0]['domain_title'] ) && is_string( $data['items'][0]['domain_title'] ) ) {
			return $data['items'][0]['domain_title'];
		}

		return '';
	}

	/**
	 * Выполняет запрос.
	 *
	 * @param string $url URL.
	 * @return string Тело ответа.
	 * @throws FeedException При сетевой ошибке.
	 */
	private function request( $url ) {
		if ( is_callable( $this->http ) ) {
			try {
				return (string) call_user_func( $this->http, $url );
			} catch ( \Exception $e ) {
				throw new FeedException( 'Не удалось загрузить фид: ' . $e->getMessage() );
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'headers'     => array(
					'User-Agent'      => self::USER_AGENT,
					'Accept'          => 'application/json, text/plain, */*',
					'Accept-Language' => 'ru-RU,ru;q=0.9',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new FeedException( 'Не удалось загрузить фид: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new FeedException( 'Фид ответил HTTP ' . $code );
		}

		return (string) wp_remote_retrieve_body( $response );
	}
}
