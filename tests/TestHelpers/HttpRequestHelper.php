<?php
/**
 * ownCloud
 *
 * @author Artur Neumann <artur@jankaritech.com>
 * @copyright Copyright (c) 2017 Artur Neumann artur@jankaritech.com
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License,
 * as published by the Free Software Foundation;
 * either version 3 of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace TestHelpers;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use SimpleXMLElement;
use Sabre\Xml\LibXMLException;
use Sabre\Xml\Reader;

/**
 * Helper for HTTP requests
 */
class HttpRequestHelper {
	/**
	 *
	 * @param string $url
	 * @param string $method
	 * @param string $user
	 * @param string $password
	 * @param array $headers ['X-MyHeader' => 'value']
	 * @param mixed $body
	 * @param array $config
	 * @param CookieJar $cookies
	 * @param bool $stream Set to true to stream a response rather
	 *                     than download it all up-front.
	 * @param int $timeout
	 * @param Client|null $client
	 *
	 * @throws BadResponseException
	 * @return ResponseInterface
	 */
	public static function sendRequest(
		$url,
		$method = 'GET',
		$user = null,
		$password = null,
		$headers = null,
		$body = null,
		$config = null,
		$cookies = null,
		$stream = false,
		$timeout = 0,
		$client =  null
	) {
		if ($client === null) {
			$client = new Client();
		}

		$options = [];
		if ($user !== null) {
			$options['auth'] = [$user, $password];
		}
		if ($body !== null) {
			if (\is_array($body)) {
				// the array of 'form_params' get converted into a body by the
				// client, which uses http_build_query to do it.
				$options['form_params'] = $body;
			} else {
				// just use the ordinary body provided as-is.
				// e.g. the caller might have already built a JSON-encoded string
				$options['body'] = $body;
			}
		}
		if ($config !== null) {
			$options['config'] = $config;
		}
		if ($cookies !== null) {
			$options['cookies'] = $cookies;
		}
		$options['stream'] = $stream;
		$options['verify'] = false;
		$options['timeout'] = $timeout;

		if ($headers === null) {
			$headers = [];
		}

		$request = new Request(
			$method,
			$url,
			$headers
		);

		try {
			$response = $client->send($request, $options);
		} catch (BadResponseException $ex) {
			$response = $ex->getResponse();
			
			//if the response was null for some reason do not return it but re-throw
			if ($response === null) {
				throw $ex;
			}
		}
		return $response;
	}

	/**
	 * same as HttpRequestHelper::sendRequest() but with "GET" as method
	 *
	 * @see HttpRequestHelper::sendRequest()
	 *
	 * @param string $url
	 * @param string $user
	 * @param string $password
	 * @param array $headers ['X-MyHeader' => 'value']
	 * @param mixed $body
	 * @param array $config
	 * @param CookieJar $cookies
	 * @param boolean $stream
	 *
	 * @throws BadResponseException
	 * @return ResponseInterface
	 */
	public static function get(
		$url,
		$user = null,
		$password = null,
		$headers = null,
		$body = null,
		$config = null,
		$cookies = null,
		$stream = false
	) {
		return self::sendRequest(
			$url, 'GET', $user, $password, $headers, $body, $config, $cookies, $stream
		);
	}

	/**
	 * same as HttpRequestHelper::sendRequest() but with "POST" as method
	 *
	 * @see HttpRequestHelper::sendRequest()
	 *
	 * @param string $url
	 * @param string $user
	 * @param string $password
	 * @param array $headers ['X-MyHeader' => 'value']
	 * @param mixed $body
	 * @param array $config
	 * @param CookieJar $cookies
	 * @param boolean $stream
	 *
	 * @throws BadResponseException
	 * @return ResponseInterface
	 */
	public static function post(
		$url,
		$user = null,
		$password = null,
		$headers = null,
		$body = null,
		$config = null,
		$cookies = null,
		$stream = false
	) {
		return self::sendRequest(
			$url, 'POST', $user, $password, $headers, $body, $config, $cookies, $stream
		);
	}

	/**
	 * same as HttpRequestHelper::sendRequest() but with "PUT" as method
	 *
	 * @see HttpRequestHelper::sendRequest()
	 *
	 * @param string $url
	 * @param string $user
	 * @param string $password
	 * @param array $headers ['X-MyHeader' => 'value']
	 * @param mixed $body
	 * @param array $config
	 * @param CookieJar $cookies
	 * @param boolean $stream
	 *
	 * @throws BadResponseException
	 * @return ResponseInterface
	 */
	public static function put(
		$url,
		$user = null,
		$password = null,
		$headers = null,
		$body = null,
		$config = null,
		$cookies = null,
		$stream = false
	) {
		return self::sendRequest(
			$url, 'PUT', $user, $password, $headers, $body, $config, $cookies, $stream
		);
	}

	/**
	 * same as HttpRequestHelper::sendRequest() but with "DELETE" as method
	 *
	 * @see HttpRequestHelper::sendRequest()
	 *
	 * @param string $url
	 * @param string $user
	 * @param string $password
	 * @param array $headers ['X-MyHeader' => 'value']
	 * @param mixed $body
	 * @param array $config
	 * @param CookieJar $cookies
	 * @param boolean $stream
	 *
	 * @throws BadResponseException
	 * @return ResponseInterface
	 */
	public static function delete(
		$url,
		$user = null,
		$password = null,
		$headers = null,
		$body = null,
		$config = null,
		$cookies = null,
		$stream = false
	) {
		return self::sendRequest(
			$url, 'DELETE', $user, $password, $headers, $body, $config, $cookies, $stream
		);
	}

	/**
	 * Parses the response as XML
	 *
	 * @param ResponseInterface $response
	 *
	 * @return SimpleXMLElement
	 */
	public static function getResponseXml($response) {
		// rewind just to make sure we can re-parse it in case it was parsed already...
		$response->getBody()->rewind();
		return new SimpleXMLElement($response->getBody()->getContents());
	}

	/**
	 * parses the body content of $response and returns an array representing the XML
	 * This function returns an array with the following three elements:
	 *    * name - The root element name.
	 *    * value - The value for the root element.
	 *    * attributes - An array of attributes.
	 *
	 * @param ResponseInterface $response
	 *
	 * @return array
	 */
	public static function parseResponseAsXml($response) {
		$body = $response->getBody()->getContents();
		$parsedResponse = [];
		if ($body && \substr($body, 0, 1) === '<') {
			try {
				$reader = new Reader();
				$reader->xml($body);
				$parsedResponse = $reader->parse();
			} catch (LibXMLException $e) {
				// Sometimes the body can be a real page of HTML and text.
				// So it may not be a complete ordinary piece of XML.
				// The XML parse might fail with an exception message like:
				// Opening and ending tag mismatch: link line 31 and head.
			}
		}
		return $parsedResponse;
	}
}
