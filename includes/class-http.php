<?php
/**
 * HTTP-запросы к страницам статей.
 *
 * @package DzenImporter
 */

namespace DzenImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ошибка HTTP-запроса.
 */
class HttpException extends \Exception {
}

/**
 * Тонкая обёртка над wp_remote_get с подменяемым транспортом.
 */
class Http {

	/**
	 * UA поискового бота: только с ним Дзен отдаёт полный HTML статьи.
	 */
	const CRAWLER_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	/**
	 * Загружает страницу.
	 *
	 * @param string        $url     URL.
	 * @param string        $ua      User-Agent.
	 * @param int           $timeout Таймаут, секунды.
	 * @param callable|null $http    Подменяемый транспорт.
	 * @return string Тело ответа.
	 * @throws HttpException При ошибке.
	 */
	public static function get( $url, $ua = self::CRAWLER_UA, $timeout = 30, $http = null ) {
		if ( is_callable( $http ) ) {
			try {
				return (string) call_user_func( $http, $url );
			} catch ( \Exception $e ) {
				throw new HttpException( $e->getMessage() );
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 3,
				'headers'     => array(
					'User-Agent'      => $ua,
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'ru-RU,ru;q=0.9',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new HttpException( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new HttpException( 'HTTP ' . $code );
		}

		return (string) wp_remote_retrieve_body( $response );
	}
}
