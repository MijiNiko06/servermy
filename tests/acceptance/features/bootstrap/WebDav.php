<?php
/**
 * @author Sergio Bertolin <sbertolin@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Ring\Exception\ConnectException;
use GuzzleHttp\Stream\StreamInterface;
use Guzzle\Http\Exception\BadResponseException;
use TestHelpers\OcsApiHelper;
use TestHelpers\SetupHelper;
use TestHelpers\UploadHelper;
use TestHelpers\WebDavHelper;
use TestHelpers\HttpRequestHelper;
use TestHelpers\Asserts\WebDav as WebDavAssert;

require __DIR__ . '/../../../../lib/composer/autoload.php';

/**
 * WebDav functions
 */
trait WebDav {

	/**
	 * @var string
	 */
	private $davPath = "remote.php/webdav";

	/**
	 * @var boolean
	 */
	private $usingOldDavPath = true;

	/**
	 * @var ResponseInterface[]
	 */
	private $uploadResponses;

	/**
	 * @var integer
	 */
	private $storedFileID = null;

	/**
	 * @var int
	 */
	private $lastUploadDeleteTime = null;

	/**
	 * a variable that contains the dav path without "remote.php/(web)dav"
	 * when setting $this->davPath directly by usingDavPath()
	 *
	 * @var string
	 */
	private $customDavPath = null;

	private $oldAsyncSetting = null;

	private $oldDavSlowdownSetting = null;

	/**
	 * response content parsed from XML to an array
	 *
	 * @var array
	 */
	private $responseXml = [];

	/**
	 * response content parsed into a SimpleXMLElement
	 *
	 * @var SimpleXMLElement
	 */
	private $responseXmlObject;

	private $httpRequestTimeout = 0;

	private $chunkingToUse = null;

	/**
	 * @param number $lastUploadDeleteTime
	 *
	 * @return void
	 */
	public function setLastUploadDeleteTime($lastUploadDeleteTime) {
		$this->lastUploadDeleteTime = $lastUploadDeleteTime;
	}

	/**
	 * @return SimpleXMLElement
	 */
	public function getResponseXmlObject() {
		return $this->responseXmlObject;
	}

	/**
	 * @param SimpleXMLElement $responseXmlObject
	 *
	 * @return void
	 */
	public function setResponseXmlObject($responseXmlObject) {
		$this->responseXmlObject = $responseXmlObject;
	}

	/**
	 *
	 * @return string the etag or an empty string if the getetag property does not exist
	 */
	public function getEtagFromResponseXmlObject() {
		$xmlObject = $this->getResponseXmlObject();
		$xmlPart = $xmlObject->xpath("//d:prop/d:getetag");
		if (!\is_array($xmlPart) || (\count($xmlPart) === 0)) {
			return '';
		}
		return $xmlPart[0]->__toString();
	}

	/**
	 *
	 * @param string|null $eTag if null then get eTag from response XML object
	 *
	 * @return boolean
	 */
	public function isEtagValid($eTag = null) {
		if ($eTag === null) {
			$eTag = $this->getEtagFromResponseXmlObject();
		}
		if (\preg_match("/^\"[a-f0-9]{1,32}\"$/", $eTag)
		) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * @param array $responseXml
	 *
	 * @return void
	 */
	public function setResponseXml($responseXml) {
		$this->responseXml = $responseXml;
	}

	/**
	 * @param ResponseInterface[] $uploadResponses
	 *
	 * @return void
	 */
	public function setUploadResponses($uploadResponses) {
		$this->uploadResponses = $uploadResponses;
	}

	/**
	 * @Given /^using dav path "([^"]*)"$/
	 *
	 * @param string $davPath
	 *
	 * @return void
	 */
	public function usingDavPath($davPath) {
		$this->davPath = $davPath;
		$this->customDavPath = \preg_replace(
			"/remote\.php\/(web)?dav\//", "", $davPath
		);
	}

	/**
	 * @return string
	 */
	public function getOldDavPath() {
		return "remote.php/webdav";
	}

	/**
	 * @return string
	 */
	public function getNewDavPath() {
		return "remote.php/dav";
	}

	/**
	 * @Given /^using (old|new) (?:dav|DAV) path$/
	 *
	 * @param string $oldOrNewDavPath
	 *
	 * @return void
	 */
	public function usingOldOrNewDavPath($oldOrNewDavPath) {
		if ($oldOrNewDavPath === 'old') {
			$this->usingOldDavPath();
		} else {
			$this->usingNewDavPath();
		}
	}

	/**
	 * Select the old DAV path as the default for later scenario steps
	 *
	 * @return void
	 */
	public function usingOldDavPath() {
		$this->davPath = $this->getOldDavPath();
		$this->usingOldDavPath = true;
		$this->customDavPath = null;
	}

	/**
	 * Select the new DAV path as the default for later scenario steps
	 *
	 * @return void
	 */
	public function usingNewDavPath() {
		$this->davPath = $this->getNewDavPath();
		$this->usingOldDavPath = false;
		$this->customDavPath = null;
	}

	/**
	 * @param string $user
	 *
	 * @return string
	 */
	public function getDavFilesPath($user) {
		if ($this->usingOldDavPath === true) {
			return $this->davPath;
		} else {
			return "$this->davPath/files/$user";
		}
	}

	/**
	 * gives the dav path of a file including the subfolder of the webserver
	 * e.g. when the server runs in `http://localhost/owncloud/`
	 * this function will return `owncloud/remote.php/webdav/prueba.txt`
	 *
	 * @param string $user
	 *
	 * @return string
	 */
	public function getFullDavFilesPath($user) {
		return \ltrim(
			$this->getBasePath() . "/" . $this->getDavFilesPath($user), "/"
		);
	}

	/**
	 * Select a suitable dav path version number.
	 * Some endpoints have only existed since a certain point in time, so for
	 * those make sure to return a DAV path version that works for that endpoint.
	 * Otherwise return the currently selected DAV path version.
	 *
	 * @param string $for the category of endpoint that the dav path will be used for
	 *
	 * @return int DAV path version (1 or 2) selected, or appropriate for the endpoint
	 */
	public function getDavPathVersion($for = null) {
		if ($for === 'systemtags') {
			// systemtags only exists since dav v2
			return 2;
		}
		if ($for === 'file_versions') {
			// file_versions only exists since dav v2
			return 2;
		}
		if ($this->usingOldDavPath === true) {
			return 1;
		} else {
			return 2;
		}
	}

	/**
	 * Select a suitable dav path.
	 * Some endpoints have only existed since a certain point in time, so for
	 * those make sure to return a DAV path that works for that endpoint.
	 * Otherwise return the currently selected DAV path.
	 *
	 * @param string $for the category of endpoint that the dav path will be used for
	 *
	 * @return string DAV path selected, or appropriate for the endpoint
	 */
	public function getDavPath($for = null) {
		if ($this->getDavPathVersion($for) === 1) {
			return $this->getOldDavPath();
		}

		return $this->getNewDavPath();
	}

	/**
	 * @param string $user
	 * @param string $method
	 * @param string $path
	 * @param array $headers
	 * @param StreamInterface $body
	 * @param string $type
	 * @param string|null $requestBody
	 * @param string|null $davPathVersion
	 * @param bool $stream Set to true to stream a response rather
	 *                     than download it all up-front.
	 * @param string|null $password
	 *
	 * @return ResponseInterface
	 */
	public function makeDavRequest(
		$user,
		$method,
		$path,
		$headers,
		$body = null,
		$type = "files",
		$requestBody = null,
		$davPathVersion = null,
		$stream = false,
		$password = null
	) {
		if ($this->customDavPath !== null) {
			$path = $this->customDavPath . $path;
		}

		if ($davPathVersion === null) {
			$davPathVersion = $this->getDavPathVersion();
		}

		if ($password === null) {
			$password  = $this->getPasswordForUser($user);
		}
		return WebDavHelper::makeDavRequest(
			$this->getBaseUrl(),
			$user, $password, $method,
			$path, $headers, $body, $requestBody, $davPathVersion,
			$type, null, "basic", $stream, $this->httpRequestTimeout
		);
	}

	/**
	 * @Given /^the administrator has (enabled|disabled) async operations$/
	 *
	 * @param string $enabledOrDisabled
	 *
	 * @return void
	 * @throws Exception
	 */
	public function triggerAsyncUpload($enabledOrDisabled) {
		$switch = ($enabledOrDisabled !== "disabled");
		if ($switch) {
			$value = 'true';
		} else {
			$value = 'false';
		}
		if ($this->oldAsyncSetting === null) {
			$oldAsyncSetting = SetupHelper::runOcc(
				['config:system:get', 'dav.enable.async']
			)['stdOut'];
			$this->oldAsyncSetting = \trim($oldAsyncSetting);
		}
		$this->runOcc(
			[
				'config:system:set',
				'dav.enable.async',
				'--type',
				'boolean',
				'--value',
				$value
			]
		);
	}

	/**
	 * @Given the HTTP-Request-timeout is set to :seconds seconds
	 *
	 * @param int $timeout
	 *
	 * @return void
	 */
	public function setHttpTimeout($timeout) {
		$this->httpRequestTimeout = (int)$timeout;
	}

	/**
	 * @Given the :method dav requests are slowed down by :seconds seconds
	 *
	 * @param string $method
	 * @param int $seconds
	 *
	 * @throws Exception
	 * @return void
	 */
	public function slowdownDavRequests($method, $seconds) {
		if ($this->oldDavSlowdownSetting === null) {
			$oldDavSlowdownSetting = SetupHelper::runOcc(
				['config:system:get', 'dav.slowdown']
			)['stdOut'];
			$this->oldDavSlowdownSetting = \trim($oldDavSlowdownSetting);
		}
		OcsApiHelper::sendRequest(
			$this->getBaseUrl(),
			$this->getAdminUsername(),
			$this->getAdminPassword(),
			"PUT",
			"/apps/testing/api/v1/davslowdown/$method/$seconds"
		);
	}

	/**
	 * @param string $user
	 * @param string $fileDestination
	 *
	 * @return string
	 */
	public function destinationHeaderValue($user, $fileDestination) {
		$fullUrl = $this->getBaseUrl() . '/' . $this->getDavFilesPath($user);
		return $fullUrl . '/' . \ltrim($fileDestination, '/');
	}

	/**
	 * @Given /^user "([^"]*)" has moved (?:file|folder|entry) "([^"]*)" to "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $fileSource
	 * @param string $fileDestination
	 *
	 * @return void
	 */
	public function userHasMovedFile(
		$user, $fileSource, $fileDestination
	) {
		$headers['Destination'] = $this->destinationHeaderValue(
			$user, $fileDestination
		);
		$this->response = $this->makeDavRequest(
			$user, "MOVE", $fileSource, $headers
		);
		PHPUnit\Framework\Assert::assertEquals(
			201, $this->response->getStatusCode()
		);
	}

	/**
	 * @Given /^the user has moved (?:file|folder|entry) "([^"]*)" to "([^"]*)"$/
	 *
	 * @param string $fileSource
	 * @param string $fileDestination
	 *
	 * @return void
	 */
	public function theUserHasMovedFile($fileSource, $fileDestination) {
		$this->userHasMovedFile($this->getCurrentUser(), $fileSource, $fileDestination);
	}

	/**
	 * @When /^user "([^"]*)" moves (?:file|folder|entry) "([^"]*)"\s?(asynchronously|) to "([^"]*)" using the WebDAV API$/
	 *
	 * @param string $user
	 * @param string $fileSource
	 * @param string $type "asynchronously" or empty
	 * @param string $fileDestination
	 *
	 * @return void
	 */
	public function userMovesFileUsingTheAPI(
		$user, $fileSource, $type, $fileDestination
	) {
		$headers['Destination'] = $this->destinationHeaderValue(
			$user, $fileDestination
		);
		$stream = false;
		if ($type === "asynchronously") {
			$headers['OC-LazyOps'] = 'true';
			if ($this->httpRequestTimeout > 0) {
				//LazyOps is set and a request timeout, so we want to use stream
				//to be able to read data from the request before its times out
				//when doing LazyOps the server does not close the connection
				//before its really finished
				//but we want to read JobStatus-Location before the end of the job
				//to see if it reports the correct values
				$stream = true;
			}
		}
		try {
			$this->response = $this->makeDavRequest(
				$user, "MOVE", $fileSource, $headers, null, "files", null, null, $stream
			);
			$this->setResponseXml(
				HttpRequestHelper::parseResponseAsXml($this->response)
			);
		} catch (ConnectException $e) {
		}
	}

	/**
	 * @When /^user "([^"]*)" on "(LOCAL|REMOTE)" moves (?:file|folder|entry) "([^"]*)" to "([^"]*)" using the WebDAV API$/
	 *
	 * @param string $user
	 * @param string $server
	 * @param string $fileSource
	 * @param string $fileDestination
	 *
	 * @return void
	 */
	public function userOnMovesFileUsingTheAPI(
		$user, $server, $fileSource, $fileDestination
	) {
		$previousServer = $this->usingServer($server);
		$this->userMovesFileUsingTheAPI($user, $fileSource, "", $fileDestination);
		$this->usingServer($previousServer);
	}

	/**
	 * @When /^user "([^"]*)" copies file "([^"]*)" to "([^"]*)" using the WebDAV API$/
	 * @Given /^user "([^"]*)" has copied file "([^"]*)" to "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $fileSource
	 * @param string $fileDestination
	 *
	 * @return void
	 */
	public function userCopiesFileUsingTheAPI(
		$user, $fileSource, $fileDestination
	) {
		$headers['Destination'] = $this->destinationHeaderValue(
			$user, $fileDestination
		);
		$this->response = $this->makeDavRequest(
			$user, "COPY", $fileSource, $headers
		);
		$this->setResponseXml(
			HttpRequestHelper::parseResponseAsXml($this->response)
		);
	}

	/**
	 * @When /^the user copies file "([^"]*)" to "([^"]*)" using the WebDAV API$/
	 * @Given /^the user has copied file "([^"]*)" to "([^"]*)"$/
	 *
	 * @param string $fileSource
	 * @param string $fileDestination
	 *
	 * @return void
	 */
	public function theUserCopiesFileUsingTheAPI($fileSource, $fileDestination) {
		$this->userCopiesFileUsingTheAPI($this->getCurrentUser(), $fileSource, $fileDestination);
	}

	/**
	 * @When /^the user downloads file "([^"]*)" with range "([^"]*)" using the WebDAV API$/
	 *
	 * @param string $fileSource
	 * @param string $range
	 *
	 * @return void
	 */
	public function downloadFileWithRange($fileSource, $range) {
		$this->userDownloadsFileWithRange(
			$this->currentUser, $fileSource, $range
		);
	}

	/**
	 * @When /^user "([^"]*)" downloads file "([^"]*)" with range "([^"]*)" using the WebDAV API$/
	 *
	 * @param string $user
	 * @param string $fileSource
	 * @param string $range
	 *
	 * @return void
	 */
	public function userDownloadsFileWithRange($user, $fileSource, $range) {
		$headers['Range'] = $range;
		$this->response = $this->makeDavRequest(
			$user, "GET", $fileSource, $headers
		);
	}

	/**
	 * @Then /^user "([^"]*)" using password "([^"]*)" should not be able to download file "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $password
	 * @param string $fileName
	 *
	 * @return void
	 */
	public function userUsingPasswordShouldNotBeAbleToDownloadFile(
		$user, $password, $fileName
	) {
		$user = $this->getActualUsername($user);
		$password = $this->getActualPassword($password);
		$this->downloadFileAsUserUsingPassword($user, $fileName, $password);
		PHPUnit\Framework\Assert::assertGreaterThanOrEqual(
			400, $this->getResponse()->getStatusCode(), 'download must fail'
		);
		PHPUnit\Framework\Assert::assertLessThanOrEqual(
			499, $this->getResponse()->getStatusCode(), '4xx error expected'
		);
	}

	/**
	 * @Then /^user "([^"]*)" should be able to access a skeleton file$/
	 *
	 * @param string $user
	 *
	 * @return void
	 */
	public function userShouldBeAbleToAccessASkeletonFile($user) {
		$this->contentOfFileForUserShouldBePlusEndOfLine(
			"textfile0.txt", $user, "ownCloud test text file 0"
		);
	}

	/**
	 * @Then /^the downloaded content should be "([^"]*)"$/
	 *
	 * @param string $content
	 *
	 * @return void
	 */
	public function downloadedContentShouldBe($content) {
		PHPUnit\Framework\Assert::assertEquals(
			$content, (string)$this->response->getBody()
		);
	}

	/**
	 * @Then /^the downloaded content should be "([^"]*)" plus end-of-line$/
	 *
	 * @param string $content
	 *
	 * @return void
	 */
	public function downloadedContentShouldBePlusEndOfLine($content) {
		$this->downloadedContentShouldBe("$content\n");
	}

	/**
	 * @Then /^the content of file "([^"]*)" should be "([^"]*)"$/
	 *
	 * @param string $fileName
	 * @param string $content
	 *
	 * @return void
	 */
	public function contentOfFileShouldBe($fileName, $content) {
		$this->theUserDownloadsTheFileUsingTheAPI($fileName);
		$this->downloadedContentShouldBe($content);
	}

	/**
	 * @Then /^the content of file "([^"]*)" should be:$/
	 *
	 * @param string $fileName
	 * @param PyStringNode $content
	 *
	 * @return void
	 */
	public function contentOfFileShouldBePyString(
		$fileName, PyStringNode $content
	) {
		$this->contentOfFileShouldBe($fileName, $content->getRaw());
	}

	/**
	 * @Then /^the content of file "([^"]*)" should be "([^"]*)" plus end-of-line$/
	 *
	 * @param string $fileName
	 * @param string $content
	 *
	 * @return void
	 */
	public function contentOfFileShouldBePlusEndOfLine($fileName, $content) {
		$this->theUserDownloadsTheFileUsingTheAPI($fileName);
		$this->downloadedContentShouldBePlusEndOfLine($content);
	}

	/**
	 * @Then /^the content of file "([^"]*)" for user "([^"]*)" should be "([^"]*)"$/
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param string $content
	 *
	 * @return void
	 */
	public function contentOfFileForUserShouldBe($fileName, $user, $content) {
		$this->downloadFileAsUserUsingPassword($user, $fileName);
		$this->downloadedContentShouldBe($content);
	}

	/**
	 * @Then /^the content of file "([^"]*)" for user "([^"]*)" on server "([^"]*)" should be "([^"]*)"$/
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param string $server
	 * @param string $content
	 *
	 * @return void
	 */
	public function theContentOfFileForUserOnServerShouldBe(
		$fileName, $user, $server, $content
	) {
		$previousServer = $this->usingServer($server);
		$this->contentOfFileForUserShouldBe($fileName, $user, $content);
		$this->usingServer($previousServer);
	}

	/**
	 * @Then /^the content of file "([^"]*)" for user "([^"]*)" using password "([^"]*)" should be "([^"]*)"$/
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param string $password
	 * @param string $content
	 *
	 * @return void
	 */
	public function contentOfFileForUserUsingPasswordShouldBe(
		$fileName, $user, $password, $content
	) {
		$user = $this->getActualUsername($user);
		$password = $this->getActualPassword($password);
		$this->downloadFileAsUserUsingPassword($user, $fileName, $password);
		$this->downloadedContentShouldBe($content);
	}

	/**
	 * @Then /^the content of file "([^"]*)" for user "([^"]*)" should be:$/
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param PyStringNode $content
	 *
	 * @return void
	 */
	public function contentOfFileForUserShouldBePyString(
		$fileName, $user, PyStringNode $content
	) {
		$this->contentOfFileForUserShouldBe($fileName, $user, $content->getRaw());
	}

	/**
	 * @Then /^the content of file "([^"]*)" for user "([^"]*)" using password "([^"]*)" should be:$/
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param string $password
	 * @param PyStringNode $content
	 *
	 * @return void
	 */
	public function contentOfFileForUserUsingPasswordShouldBePyString(
		$fileName, $user, $password, PyStringNode $content
	) {
		$this->contentOfFileForUserUsingPasswordShouldBe(
			$fileName, $user, $password, $content->getRaw()
		);
	}

	/**
	 * @Then /^the content of file "([^"]*)" for user "([^"]*)" should be "([^"]*)" plus end-of-line$/
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param string $content
	 *
	 * @return void
	 */
	public function contentOfFileForUserShouldBePlusEndOfLine($fileName, $user, $content) {
		$this->contentOfFileForUserShouldBe(
			$fileName, $user, "$content\n"
		);
	}

	/**
	 * @Then /^the content of file "([^"]*)" for user "([^"]*)" on server "([^"]*)" should be "([^"]*)" plus end-of-line$/
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param string $server
	 * @param string $content
	 *
	 * @return void
	 */
	public function theContentOfFileForUserOnServerShouldBePlusEndOfLine(
		$fileName, $user, $server, $content
	) {
		$previousServer = $this->usingServer($server);
		$this->contentOfFileForUserShouldBePlusEndOfLine($fileName, $user, $content);
		$this->usingServer($previousServer);
	}

	/**
	 * @Then /^the content of file "([^"]*)" for user "([^"]*)" using password "([^"]*)" should be "([^"]*)" plus end-of-line$/
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param string $password
	 * @param string $content
	 *
	 * @return void
	 */
	public function contentOfFileForUserUsingPasswordShouldBePlusEndOfLine(
		$fileName, $user, $password, $content
	) {
		$this->contentOfFileForUserUsingPasswordShouldBe(
			$fileName, $user, $password, "$content\n"
		);
	}

	/**
	 * @Then /^the downloaded content when downloading file "([^"]*)" with range "([^"]*)" should be "([^"]*)"$/
	 *
	 * @param string $fileSource
	 * @param string $range
	 * @param string $content
	 *
	 * @return void
	 */
	public function downloadedContentWhenDownloadingWithRangeShouldBe(
		$fileSource, $range, $content
	) {
		$this->downloadFileWithRange($fileSource, $range);
		$this->downloadedContentShouldBe($content);
	}

	/**
	 * @Then /^the downloaded content when downloading file "([^"]*)" for user "([^"]*)" with range "([^"]*)" should be "([^"]*)"$/
	 *
	 * @param string $fileSource
	 * @param string $user
	 * @param string $range
	 * @param string $content
	 *
	 * @return void
	 */
	public function downloadedContentWhenDownloadingForUserWithRangeShouldBe(
		$fileSource, $user, $range, $content
	) {
		$this->userDownloadsFileWithRange($user, $fileSource, $range);
		$this->downloadedContentShouldBe($content);
	}

	/**
	 * @When the user downloads the file :fileName using the WebDAV API
	 *
	 * @param string $fileName
	 *
	 * @return void
	 */
	public function theUserDownloadsTheFileUsingTheAPI($fileName) {
		$this->downloadFileAsUserUsingPassword($this->currentUser, $fileName);
	}

	/**
	 * @When user :user downloads file :fileName using the WebDAV API
	 *
	 * @param string $user
	 * @param string $fileName
	 *
	 * @return void
	 */
	public function userDownloadsFileUsingTheAPI(
		$user, $fileName
	) {
		$this->downloadFileAsUserUsingPassword($user, $fileName);
	}

	/**
	 * @When user :user using password :password downloads the file :fileName using the WebDAV API
	 *
	 * @param string $user
	 * @param string|null $password
	 * @param string $fileName
	 *
	 * @return void
	 */
	public function userUsingPasswordDownloadsTheFileUsingTheAPI(
		$user, $password, $fileName
	) {
		$this->downloadFileAsUserUsingPassword($user, $fileName, $password);
	}

	/**
	 * @param string $user
	 * @param string $fileName
	 * @param string|null $password
	 * @param array|null $headers
	 *
	 * @return void
	 */
	public function downloadFileAsUserUsingPassword(
		$user, $fileName, $password = null, $headers = []
	) {
		$password = $this->getActualPassword($password);
		$this->response = $this->makeDavRequest(
			$user,
			'GET',
			$fileName,
			$headers,
			null,
			"files",
			null,
			null,
			false,
			$password
		);
	}

	/**
	 * @Then the following headers should be set
	 *
	 * @param TableNode $table
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function theFollowingHeadersShouldBeSet(TableNode $table) {
		foreach ($table->getTable() as $header) {
			$headerName = $header[0];
			$expectedHeaderValue = $header[1];
			$returnedHeader = $this->response->getHeader($headerName);
			$expectedHeaderValue = $this->substituteInLineCodes($expectedHeaderValue);

			if ($returnedHeader !== $expectedHeaderValue) {
				throw new \Exception(
					\sprintf(
						"Expected value '%s' for header '%s', got '%s'",
						$expectedHeaderValue,
						$headerName,
						$returnedHeader
					)
				);
			}
		}
	}

	/**
	 * @Then the downloaded content should start with :start
	 *
	 * @param string $start
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function downloadedContentShouldStartWith($start) {
		if (\strpos($this->response->getBody()->getContents(), $start) !== 0) {
			throw new \Exception(
				\sprintf(
					"Expected '%s', got '%s'",
					$start,
					$this->response->getBody()->getContents()
				)
			);
		}
	}

	/**
	 * @Then the oc job status values of last request for user :user should match these regular expressions
	 *
	 * @param string $user
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function jobStatusValuesShouldMatchRegEx($user, $table) {
		$url = $this->response->getHeader("OC-JobStatus-Location");
		$url = $this->getBaseUrlWithoutPath() . $url;
		$response = HttpRequestHelper::get($url, $user, $this->getPasswordForUser($user));
		$result = \json_decode($response->getBody()->getContents(), true);
		PHPUnit\Framework\Assert::assertNotNull($result, "'$response' is not valid JSON");
		foreach ($table->getTable() as $row) {
			$expectedKey = $row[0];
			PHPUnit\Framework\Assert::assertArrayHasKey(
				$expectedKey, $result, "response does not have expected key '$expectedKey'"
			);
			$expectedValue = $this->substituteInLineCodes(
				$row[1], ['preg_quote' => ['/'] ]
			);
			PHPUnit\Framework\Assert::assertNotFalse(
				(bool)\preg_match($expectedValue, $result[$expectedKey]),
				"'$expectedValue' does not match '$result[$expectedKey]'"
			);
		}
	}

	/**
	 * @Then /^as "([^"]*)" (file|folder|entry) "([^"]*)" should not exist$/
	 *
	 * @param string $user
	 * @param string $entry
	 * @param string $path
	 *
	 * @return ResponseInterface
	 * @throws \Exception
	 */
	public function asFileOrFolderShouldNotExist($user, $entry, $path) {
		$path = $this->substituteInLineCodes($path);
		$response = WebDavHelper::makeDavRequest(
			$this->getBaseUrl(), $this->getActualUsername($user),
			$this->getPasswordForUser($user), 'GET', $path, []
		);
		if ($response->getStatusCode() < 401 || $response->getStatusCode() > 404) {
			throw new \Exception(
				"$entry '$path' expected to not exist " .
				"(status code {$response->getStatusCode()}, expected 401 - 404)"
			);
		}

		return $response;
	}

	/**
	 * @Then /^as "([^"]*)" (file|folder|entry) "([^"]*)" should exist$/
	 *
	 * @param string $user
	 * @param string $entry
	 * @param string $path
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function asFileOrFolderShouldExist($user, $entry, $path) {
		$path = $this->substituteInLineCodes($path);
		$this->responseXmlObject = $this->listFolder($user, $path, 0);
		PHPUnit\Framework\Assert::assertTrue(
			$this->isEtagValid(),
			"$entry '$path' expected to exist but not found"
		);
	}

	/**
	 * @Then /^as "([^"]*)" exactly one of these (files|folders|entries) should exist$/
	 *
	 * @param string $user
	 * @param string $entries
	 * @param TableNode $table of file, folder or entry paths
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function asExactlyOneOfTheseFilesOrFoldersShouldExist($user, $entries, $table) {
		$numEntriesThatExist = 0;
		foreach ($table->getTable() as $row) {
			$path = $this->substituteInLineCodes($row[0]);
			$this->responseXmlObject = $this->listFolder($user, $path, 0);
			if ($this->isEtagValid()) {
				$numEntriesThatExist = $numEntriesThatExist + 1;
			}
		}
		PHPUnit\Framework\Assert::assertEquals(
			1,
			$numEntriesThatExist,
			"exactly one of these $entries should exist but found $numEntriesThatExist $entries"
		);
	}

	/**
	 *
	 * @param string $user
	 * @param string $path
	 * @param int $folderDepth requires 1 to see elements without children
	 * @param array|null $properties
	 * @param string $type
	 *
	 * @return SimpleXMLElement
	 */
	public function listFolder(
		$user, $path, $folderDepth, $properties = null, $type = "files"
	) {
		if ($this->customDavPath !== null) {
			$path = $this->customDavPath . $path;
		}

		return WebDavHelper::listFolder(
			$this->getBaseUrl(),
			$this->getActualUsername($user),
			$this->getPasswordForUser($user),
			$path, $folderDepth, $properties,
			$type, ($this->usingOldDavPath) ? 1 : 2
		);
	}

	/**
	 * @Then /^user "([^"]*)" should (not|)\s?see the following elements$/
	 *
	 * @param string $user
	 * @param string $shouldOrNot
	 * @param TableNode $elements
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	public function userShouldSeeTheElements($user, $shouldOrNot, $elements) {
		$should = ($shouldOrNot !== "not");
		$this->checkElementList($user, $elements, $should);
	}

	/**
	 * asserts that a the user can or cannot see a list of files/folders by propfind
	 *
	 * @param string $user
	 * @param TableNode $elements
	 * @param boolean $expectedToBeListed
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	public function checkElementList(
		$user, $elements, $expectedToBeListed = true
	) {
		if (!($elements instanceof TableNode)) {
			throw new InvalidArgumentException(
				'$expectedElements has to be an instance of TableNode'
			);
		}
		$responseXmlObject = $this->listFolder($user, "/", 3);
		$elementRows = $elements->getRows();
		$elementsSimplified = $this->simplifyArray($elementRows);
		foreach ($elementsSimplified as $expectedElement) {
			$webdavPath = "/" . $this->getFullDavFilesPath($user) . $expectedElement;
			$element = $responseXmlObject->xpath(
				"//d:response/d:href[text() = \"$webdavPath\"]"
			);
			if ($expectedToBeListed
				&& (!isset($element[0]) || $element[0]->__toString() !== $webdavPath)
			) {
				PHPUnit\Framework\Assert::fail(
					"$webdavPath is not in propfind answer but should"
				);
			} elseif (!$expectedToBeListed && isset($element[0])
			) {
				PHPUnit\Framework\Assert::fail(
					"$webdavPath is in propfind answer but should not be"
				);
			}
		}
	}

	/**
	 * @When user :user uploads file :source to :destination using the WebDAV API
	 * @Given user :user has uploaded file :source to :destination
	 *
	 * @param string $user
	 * @param string $source
	 * @param string $destination
	 *
	 * @return void
	 */
	public function userUploadsAFileTo($user, $source, $destination) {
		$file = \GuzzleHttp\Stream\Stream::factory(
			\fopen(
				$this->acceptanceTestsDirLocation() . $source,
				'r'
			)
		);
		$this->pauseUploadDelete();
		$this->response = $this->makeDavRequest(
			$user, "PUT", $destination, [], $file
		);
		$this->lastUploadDeleteTime = \time();
		$this->setResponseXml(
			HttpRequestHelper::parseResponseAsXml($this->response)
		);
	}

	/**
	 * @When the user uploads file :source to :destination using the WebDAV API
	 * @Given the user has uploaded file :source to :destination
	 *
	 * @param string $source
	 * @param string $destination
	 *
	 * @return void
	 */
	public function theUserUploadsAFileTo($source, $destination) {
		$this->userUploadsAFileTo($this->currentUser, $source, $destination);
	}

	/**
	 * @Given /^user "([^"]*)" on "(LOCAL|REMOTE)" has uploaded file "([^"]*)" to "([^"]*)"$/
	 * @When /^user "([^"]*)" on "(LOCAL|REMOTE)" uploads file "([^"]*)" to "([^"]*)" using the WebDAV API$/
	 *
	 * @param string $user
	 * @param string $server
	 * @param string $source
	 * @param string $destination
	 *
	 * @return void
	 */
	public function userOnUploadsAFileTo($user, $server, $source, $destination) {
		$previousServer = $this->usingServer($server);
		$this->userUploadsAFileTo($user, $source, $destination);
		$this->usingServer($previousServer);
	}

	/**
	 * Upload file as a user with different headers
	 *
	 * @param string $user
	 * @param string $source
	 * @param string $destination
	 * @param array $headers
	 * @param int $noOfChunks Only use for chunked upload when $this->chunkingToUse is not null
	 *
	 * @return void
	 */
	public function uploadFileWithHeaders(
		$user,
		$source,
		$destination,
		$headers=[],
		$noOfChunks = 0
	) {
		$chunkingVersion = $this->chunkingToUse;
		if ($noOfChunks <= 0) {
			$chunkingVersion = null;
		}
		try {
			$this->responseXml = [];
			$this->pauseUploadDelete();
			$this->response = UploadHelper::upload(
				$this->getBaseUrl(),
				$this->getActualUsername($user),
				$this->getUserPassword($user),
				$source,
				$destination,
				$headers,
				($this->usingOldDavPath) ? 1 : 2,
				$chunkingVersion,
				$noOfChunks
			);
			$this->lastUploadDeleteTime = \time();
		} catch (BadResponseException $e) {
			// 4xx and 5xx responses cause an exception
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @When /^user "([^"]*)" uploads file "([^"]*)" to "([^"]*)" in (\d+) chunks (?:with (new|old|v1|v2) chunking and)?\s?using the WebDAV API$/
	 * @When user :user uploads file :source to :destination with chunks using the WebDAV API
	 *
	 * @param string $user
	 * @param string $source
	 * @param string $destination
	 * @param int $noOfChunks
	 * @param string $chunkingVersion old|v1|new|v2 null for autodetect
	 * @param bool $async use asynchronous move at the end or not
	 * @param array $headers
	 *
	 * @return void
	 */
	public function userUploadsAFileToWithChunks(
		$user, $source, $destination, $noOfChunks = 2, $chunkingVersion = null, $async = false, $headers = []
	) {
		PHPUnit\Framework\Assert::assertGreaterThan(
			0, $noOfChunks, "What does it mean to have $noOfChunks chunks?"
		);
		//use the chunking version that works with the set dav version
		if ($chunkingVersion === null) {
			if ($this->usingOldDavPath) {
				$chunkingVersion = "v1";
			} else {
				$chunkingVersion = "v2";
			}
		}
		$this->useSpecificChunking($chunkingVersion);
		PHPUnit\Framework\Assert::assertTrue(
			WebDavHelper::isValidDavChunkingCombination(
				($this->usingOldDavPath) ? 1 : 2,
				$this->chunkingToUse
			),
			"invalid chunking/webdav version combination"
		);

		if ($async === true) {
			$headers['OC-LazyOps'] = 'true';
		}
		$this->uploadFileWithHeaders(
			$user,
			$this->acceptanceTestsDirLocation() . $source,
			$destination,
			$headers,
			$noOfChunks
		);
	}

	/**
	 * @When /^user "([^"]*)" uploads file "([^"]*)" asynchronously to "([^"]*)" in (\d+) chunks (?:with (new|old|v1|v2) chunking and)?\s?using the WebDAV API$/
	 *
	 * @param string $user
	 * @param string $source
	 * @param string $destination
	 * @param int  $noOfChunks
	 * @param string $chunkingVersion old|v1|new|v2 null for autodetect
	 *
	 * @return void
	 */
	public function userUploadsAFileAsyncToWithChunks(
		$user, $source, $destination, $noOfChunks = 2, $chunkingVersion = null
	) {
		$this->userUploadsAFileToWithChunks(
			$user, $source, $destination, $noOfChunks, $chunkingVersion, true
		);
	}

	/**
	 * sets the chunking version from human readable format
	 *
	 * @param string $version (no|v1|v2|new|old)
	 *
	 * @return void
	 */
	public function useSpecificChunking($version) {
		if ($version === "v1" || $version === "old") {
			$this->chunkingToUse = 1;
		} elseif ($version === "v2" || $version === "new") {
			$this->chunkingToUse = 2;
		} elseif ($version === "no") {
			$this->chunkingToUse = null;
		} else {
			throw new InvalidArgumentException(
				"cannot set chunking version to $version"
			);
		}
	}

	/**
	 * Uploading with old/new dav and chunked/non-chunked.
	 *
	 * @When user :user uploads file :source to filenames based on :destination with all mechanisms using the WebDAV API
	 *
	 * @param string $user
	 * @param string $source
	 * @param string $destination
	 *
	 * @return void
	 */
	public function userUploadsAFileToWithAllMechanisms(
		$user, $source, $destination
	) {
		$this->uploadResponses = UploadHelper::uploadWithAllMechanisms(
			$this->getBaseUrl(), $this->getActualUsername($user),
			$this->getUserPassword($user),
			$this->acceptanceTestsDirLocation() . $source, $destination
		);
	}

	/**
	 * Overwriting with old/new dav and chunked/non-chunked.
	 *
	 * @When user :user overwrites file :source to filenames based on :destination with all mechanisms using the WebDAV API
	 *
	 * @param string $user
	 * @param string $source
	 * @param string $destination
	 *
	 * @return void
	 */
	public function userOverwritesAFileToWithAllMechanisms(
		$user, $source, $destination
	) {
		$this->uploadResponses = UploadHelper::uploadWithAllMechanisms(
			$this->getBaseUrl(), $this->getActualUsername($user),
			$this->getUserPassword($user),
			$this->acceptanceTestsDirLocation() . $source, $destination, true
		);
	}

	/**
	 * @Then /^the HTTP status code of all upload responses should be "([^"]*)"$/
	 *
	 * @param int $statusCode
	 *
	 * @return void
	 */
	public function theHTTPStatusCodeOfAllUploadResponsesShouldBe($statusCode) {
		foreach ($this->uploadResponses as $response) {
			PHPUnit\Framework\Assert::assertEquals(
				$statusCode,
				$response->getStatusCode(),
				'Response for ' . $response->getEffectiveUrl() . ' did not return expected status code'
			);
		}
	}

	/**
	 * @Then /^the HTTP reason phrase of all upload responses should be "([^"]*)"$/
	 *
	 * @param string $reasonPhrase
	 *
	 * @return void
	 */
	public function theHTTPReasonPhraseOfAllUploadResponsesShouldBe($reasonPhrase) {
		foreach ($this->uploadResponses as $response) {
			PHPUnit\Framework\Assert::assertEquals(
				$reasonPhrase,
				$response->getReasonPhrase(),
				'Response for ' . $response->getEffectiveUrl() . ' did not return expected reason phrase'
			);
		}
	}

	/**
	 * @Then user :user should be able to upload file :source to :destination
	 *
	 * @param string $user
	 * @param string $source
	 * @param string $destination
	 *
	 * @return void
	 */
	public function userShouldBeAbleToUploadFileTo($user, $source, $destination) {
		$this->userUploadsAFileTo($user, $source, $destination);
		$this->asFileOrFolderShouldExist($user, null, $destination);
	}

	/**
	 * @Then user :user should not be able to upload file :source to :destination
	 *
	 * @param string $user
	 * @param string $source
	 * @param string $destination
	 *
	 * @return void
	 */
	public function theUserShouldNotBeAbleToUploadFileTo($user, $source, $destination) {
		$this->userUploadsAFileTo($user, $source, $destination);
		$this->asFileOrFolderShouldNotExist($user, null, $destination);
	}

	/**
	 * @Then /^the HTTP status code of all upload responses should be between "(\d+)" and "(\d+)"$/
	 *
	 * @param int $minStatusCode
	 * @param int $maxStatusCode
	 *
	 * @return void
	 */
	public function theHTTPStatusCodeOfAllUploadResponsesShouldBeBetween(
		$minStatusCode, $maxStatusCode
	) {
		foreach ($this->uploadResponses as $response) {
			PHPUnit\Framework\Assert::assertGreaterThanOrEqual(
				$minStatusCode,
				$response->getStatusCode(),
				'Response for ' . $response->getEffectiveUrl() . ' did not return expected status code'
			);
			PHPUnit\Framework\Assert::assertLessThanOrEqual(
				$maxStatusCode,
				$response->getStatusCode(),
				'Response for ' . $response->getEffectiveUrl() . ' did not return expected status code'
			);
		}
	}

	/**
	 * Check that all the files uploaded with old/new dav and chunked/non-chunked exist.
	 *
	 * @Then /^as "([^"]*)" the files uploaded to "([^"]*)" with all mechanisms should (not|)\s?exist$/
	 *
	 * @param string $user
	 * @param string $destination
	 * @param string $shouldOrNot
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function filesUploadedToWithAllMechanismsShouldExist(
		$user, $destination, $shouldOrNot
	) {
		if ($shouldOrNot !== "not") {
			foreach (['old', 'new'] as $davVersion) {
				foreach (["{$davVersion}dav-regular", "{$davVersion}dav-{$davVersion}chunking"] as $suffix) {
					$this->asFileOrFolderShouldExist(
						$user, 'file', "$destination-$suffix"
					);
				}
			}
		} else {
			foreach (['old', 'new'] as $davVersion) {
				foreach (["{$davVersion}dav-regular", "{$davVersion}dav-{$davVersion}chunking"] as $suffix) {
					$this->asFileOrFolderShouldNotExist(
						$user, 'file', "$destination-$suffix"
					);
				}
			}
		}
	}

	/**
	 * @Then /^as user "([^"]*)" on server "([^"]*)" the files uploaded to "([^"]*)" with all mechanisms should (not|)\s?exist$/
	 *
	 * @param string $user
	 * @param string $server
	 * @param string $destination
	 * @param string $shouldOrNot
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function asUserOnServerTheFilesUploadedToWithAllMechanismsShouldExit(
		$user, $server, $destination, $shouldOrNot
	) {
		$previousServer = $this->usingServer($server);
		$this->filesUploadedToWithAllMechanismsShouldExist($user, $destination, $shouldOrNot);
		$this->usingServer($previousServer);
	}

	/**
	 * @Given user :user has uploaded file :destination of :bytes bytes
	 *
	 * @param string $user
	 * @param string $destination
	 * @param string $bytes
	 *
	 * @return void
	 */
	public function userHasUploadedFileToOfBytes($user, $destination, $bytes) {
		$this->userUploadsAFileToOfBytes($user, $destination, $bytes);
		$expectedElements = new TableNode([["$destination"]]);
		$this->checkElementList($user, $expectedElements);
	}

	/**
	 * @When user :user uploads file :destination of :bytes bytes
	 *
	 * @param string $user
	 * @param string $destination
	 * @param string $bytes
	 *
	 * @return void
	 */
	public function userUploadsAFileToOfBytes($user, $destination, $bytes) {
		$filename = "filespecificSize.txt";
		$this->createLocalFileOfSpecificSize($filename, $bytes);
		PHPUnit\Framework\Assert::assertFileExists($this->workStorageDirLocation() . $filename);
		$this->userUploadsAFileTo(
			$user,
			$this->temporaryStorageSubfolderName() . "/$filename",
			$destination
		);
		$this->removeFile($this->workStorageDirLocation(), $filename);
	}

	/**
	 * @When user :user uploads file with content :content to :destination using the WebDAV API
	 * @Given user :user has uploaded file with content :content to :destination
	 *
	 * @param string $user
	 * @param string $content
	 * @param string $destination
	 *
	 * @return string
	 */
	public function userUploadsAFileWithContentTo(
		$user, $content, $destination
	) {
		$file = \GuzzleHttp\Stream\Stream::factory($content);
		$this->pauseUploadDelete();
		$this->response = $this->makeDavRequest(
			$user, "PUT", $destination, [], $file
		);
		$this->lastUploadDeleteTime = \time();
		return $this->response->getHeader('oc-fileid');
	}

	/**
	 * @When user :user uploads file with checksum :checksum and content :content to :destination using the WebDAV API
	 * @Given user :user has uploaded file with checksum :checksum and content :content to :destination
	 *
	 * @param string $user
	 * @param string $checksum
	 * @param string $content
	 * @param string $destination
	 *
	 * @return void
	 */
	public function userUploadsAFileWithChecksumAndContentTo(
		$user, $checksum, $content, $destination
	) {
		$file = \GuzzleHttp\Stream\Stream::factory($content);
		$this->pauseUploadDelete();
		$this->response = $this->makeDavRequest(
			$user,
			"PUT",
			$destination,
			['OC-Checksum' => $checksum],
			$file
		);
		$this->lastUploadDeleteTime = \time();
	}

	/**
	 * @Given file :file has been deleted for user :user
	 *
	 * @param string $file
	 * @param string $user
	 *
	 * @return void
	 */
	public function fileHasBeenDeleted($file, $user) {
		$this->userDeletesFile($user, $file);
	}

	/**
	 * @When /^user "([^"]*)" (?:deletes|unshares) (?:file|folder) "([^"]*)" using the WebDAV API$/
	 * @Given /^user "([^"]*)" has (?:deleted|unshared) (?:file|folder) "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $file
	 *
	 * @return void
	 */
	public function userDeletesFile($user, $file) {
		$this->pauseUploadDelete();
		$this->response = $this->makeDavRequest($user, 'DELETE', $file, []);
		$this->lastUploadDeleteTime = \time();
	}

	/**
	 * @When /^the user (?:deletes|unshares) (?:file|folder) "([^"]*)" using the WebDAV API$/
	 * @Given /^the user has (?:deleted|unshared) (?:file|folder) "([^"]*)"$/
	 *
	 * @param string $file
	 *
	 * @return void
	 */
	public function theUserDeletesFile($file) {
		$this->userDeletesFile($this->getCurrentUser(), $file);
	}

	/**
	 * @When /^user "([^"]*)" (?:deletes|unshares) these (?:files|folders|entries) without delays using the WebDAV API$/
	 *
	 * @param string $user
	 * @param TableNode $table of files or folders to delete
	 *
	 * @return void
	 */
	public function userDeletesFilesFoldersWithoutDelays($user, $table) {
		foreach ($table->getTable() as $entry) {
			$entryName = $entry[0];
			$this->response = $this->makeDavRequest($user, 'DELETE', $entryName, []);
		}
		$this->lastUploadDeleteTime = \time();
	}

	/**
	 * @When /^the user (?:deletes|unshares) these (?:files|folders|entries) without delays using the WebDAV API$/
	 *
	 * @param TableNode $table of files or folders to delete
	 *
	 * @return void
	 */
	public function theUserDeletesFilesFoldersWithoutDelays($table) {
		$this->userDeletesFilesFoldersWithoutDelays($this->getCurrentUser(), $table);
	}

	/**
	 * @When /^user "([^"]*)" on "(LOCAL|REMOTE)" (?:deletes|unshares) (?:file|folder) "([^"]*)" using the WebDAV API$/
	 * @Given /^user "([^"]*)" on "(LOCAL|REMOTE)" has (?:deleted|unshared) (?:file|folder) "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $server
	 * @param string $file
	 *
	 * @return void
	 */
	public function userOnDeletesFile($user, $server, $file) {
		$previousServer = $this->usingServer($server);
		$this->userDeletesFile($user, $file);
		$this->usingServer($previousServer);
	}

	/**
	 * @When user :user creates folder :destination using the WebDAV API
	 * @Given user :user has created folder :destination
	 *
	 * @param string $user
	 * @param string $destination
	 *
	 * @return void
	 */
	public function userCreatesFolder($user, $destination) {
		$destination = '/' . \ltrim($destination, '/');
		$this->response = $this->makeDavRequest(
			$user, "MKCOL", $destination, []
		);
		$this->setResponseXml(
			HttpRequestHelper::parseResponseAsXml($this->response)
		);
	}

	/**
	 * @When the user creates folder :destination using the WebDAV API
	 * @Given the user has created folder :destination
	 *
	 * @param string $destination
	 *
	 * @return void
	 */
	public function theUserCreatesFolder($destination) {
		$this->userCreatesFolder($this->getCurrentUser(), $destination);
	}

	/**
	 * Old style chunking upload
	 *
	 * @When user :user uploads the following :total chunks to :file with old chunking and using the WebDAV API
	 * @Given user :user has uploaded the following :total chunks to :file with old chunking
	 *
	 * @param string $user
	 * @param string $total
	 * @param string $file
	 * @param TableNode $chunkDetails table of 2 columns, chunk number and chunk
	 *                                content without column headings, e.g.
	 *                                | 1 | first data              |
	 *                                | 2 | followed by second data |
	 *                                Chunks may be numbered out-of-order if desired.
	 *
	 * @return void
	 */
	public function userUploadsTheFollowingTotalChunksUsingOldChunking(
		$user, $total, $file, TableNode $chunkDetails
	) {
		foreach ($chunkDetails->getTable() as $chunkDetail) {
			$chunkNumber = $chunkDetail[0];
			$chunkContent = $chunkDetail[1];
			$this->userUploadsChunkedFile($user, $chunkNumber, $total, $chunkContent, $file);
		}
	}

	/**
	 * Old style chunking upload
	 *
	 * @When user :user uploads the following chunks to :file with old chunking and using the WebDAV API
	 * @Given user :user has uploaded the following chunks to :file with old chunking
	 *
	 * @param string $user
	 * @param string $file
	 * @param TableNode $chunkDetails table of 2 columns, chunk number and chunk
	 *                                content without column headings, e.g.
	 *                                | 1 | first data              |
	 *                                | 2 | followed by second data |
	 *                                Chunks may be numbered out-of-order if desired.
	 *
	 * @return void
	 */
	public function userUploadsTheFollowingChunksUsingOldChunking(
		$user, $file, TableNode $chunkDetails
	) {
		$total = \count($chunkDetails->getRows());
		$this->userUploadsTheFollowingTotalChunksUsingOldChunking(
			$user, $total, $file, $chunkDetails
		);
	}

	/**
	 * Old style chunking upload
	 *
	 * @When user :user uploads chunk file :num of :total with :data to :destination using the WebDAV API
	 * @Given user :user has uploaded chunk file :num of :total with :data to :destination
	 *
	 * @param string $user
	 * @param int $num
	 * @param int $total
	 * @param string $data
	 * @param string $destination
	 *
	 * @return void
	 */
	public function userUploadsChunkedFile(
		$user, $num, $total, $data, $destination
	) {
		$num -= 1;
		$data = \GuzzleHttp\Stream\Stream::factory($data);
		$file = "$destination-chunking-42-$total-$num";
		$this->pauseUploadDelete();
		$this->response = $this->makeDavRequest(
			$user, 'PUT', $file, ['OC-Chunked' => '1'], $data, "uploads"
		);
		$this->lastUploadDeleteTime = \time();
	}

	/**
	 * New style chunking upload
	 *
	 * @When /^user "([^"]*)" uploads the following chunks\s?(asynchronously|) to "([^"]*)" with new chunking and using the WebDAV API$/
	 * @Given /^user "([^"]*)" has uploaded the following chunks\s?(asynchronously|) to "([^"]*)" with new chunking$/
	 *
	 * @param string $user
	 * @param string $type "asynchronously" or empty
	 * @param string $file
	 * @param TableNode $chunkDetails table of 2 columns, chunk number and chunk
	 *                                content without column headings, e.g.
	 *                                | 1 | first data              |
	 *                                | 2 | followed by second data |
	 *                                Chunks may be numbered out-of-order if desired.
	 *
	 * @return void
	 */
	public function userUploadsTheFollowingChunksUsingNewChunking(
		$user, $type, $file, TableNode $chunkDetails
	) {
		$async = false;
		if ($type === "asynchronously") {
			$async = true;
		}
		$this->userUploadsChunksUsingNewChunking(
			$user, $file, 'chunking-42', $chunkDetails->getTable(), $async
		);
	}

	/**
	 * New style chunking upload
	 *
	 * @param string $user
	 * @param string $file
	 * @param string $chunkingId
	 * @param array $chunkDetails of chunks of the file. Each array entry is
	 *                            itself an array of 2 items:
	 *                            [0] the chunk number
	 *                            [1] data content of the chunk
	 *                            Chunks may be numbered out-of-order if desired.
	 * @param bool $async use asynchronous MOVE at the end or not
	 *
	 * @return void
	 */
	public function userUploadsChunksUsingNewChunking(
		$user, $file, $chunkingId, $chunkDetails, $async = false
	) {
		$this->pauseUploadDelete();
		$this->userCreatesANewChunkingUploadWithId($user, $chunkingId);
		foreach ($chunkDetails as $chunkDetail) {
			$chunkNumber = $chunkDetail[0];
			$chunkContent = $chunkDetail[1];
			$this->userUploadsNewChunkFileOfWithToId($user, $chunkNumber, $chunkContent, $chunkingId);
		}
		$headers = [];
		if ($async === true) {
			$headers = ['OC-LazyOps' => 'true'];
		}
		$this->moveNewDavChunkToFinalFile($user, $chunkingId, $file, $headers);
		$this->lastUploadDeleteTime = \time();
	}

	/**
	 * @When user :user creates a new chunking upload with id :id using the WebDAV API
	 * @Given user :user has created a new chunking upload with id :id
	 *
	 * @param string $user
	 * @param string $id
	 *
	 * @return void
	 */
	public function userCreatesANewChunkingUploadWithId($user, $id) {
		$destination = "/uploads/$user/$id";
		$this->response = $this->makeDavRequest(
			$user, 'MKCOL', $destination, [], null, "uploads"
		);
	}

	/**
	 * @When user :user uploads new chunk file :num with :data to id :id using the WebDAV API
	 * @Given user :user has uploaded new chunk file :num with :data to id :id
	 *
	 * @param string $user
	 * @param int $num
	 * @param string $data
	 * @param string $id
	 *
	 * @return void
	 */
	public function userUploadsNewChunkFileOfWithToId($user, $num, $data, $id) {
		$data = \GuzzleHttp\Stream\Stream::factory($data);
		$destination = "/uploads/$user/$id/$num";
		$this->response = $this->makeDavRequest(
			$user, 'PUT', $destination, [], $data, "uploads"
		);
	}

	/**
	 * @When /^user "([^"]*)" moves new chunk file with id "([^"]*)"\s?(asynchronously|) to "([^"]*)" using the WebDAV API$/
	 * @Given /^user "([^"]*)" has moved new chunk file with id "([^"]*)"\s?(asynchronously|) to "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $id
	 * @param string $type "asynchronously" or empty
	 * @param string $dest
	 *
	 * @return void
	 */
	public function userMovesNewChunkFileWithIdToMychunkedfile(
		$user, $id, $type, $dest
	) {
		$headers = [];
		if ($type === "asynchronously") {
			$headers = ['OC-LazyOps' => 'true'];
		}
		$this->moveNewDavChunkToFinalFile($user, $id, $dest, $headers);
	}

	/**
	 * @When user :user cancels chunking-upload with id :id using the WebDAV API
	 * @Given user :user has canceled new chunking-upload with id :id
	 *
	 * @param string $user
	 * @param string $id
	 *
	 * @return void
	 */
	public function userCancelsUploadWithId(
		$user, $id
	) {
		$this->deleteUpload($user, $id, []);
	}

	/**
	 * @When /^user "([^"]*)" moves new chunk file with id "([^"]*)"\s?(asynchronously|) to "([^"]*)" with size (.*) using the WebDAV API$/
	 * @Given /^user "([^"]*)" has moved new chunk file with id "([^"]*)"\s?(asynchronously|) to "([^"]*)" with size (.*)$/
	 *
	 * @param string $user
	 * @param string $id
	 * @param string $type "asynchronously" or empty
	 * @param string $dest
	 * @param int $size
	 *
	 * @return void
	 */
	public function userMovesNewChunkFileWithIdToMychunkedfileWithSize(
		$user, $id, $type, $dest, $size
	) {
		$headers = ['OC-Total-Length' => $size];
		if ($type === "asynchronously") {
			$headers['OC-LazyOps'] = 'true';
		}
		$this->moveNewDavChunkToFinalFile(
			$user, $id, $dest, $headers
		);
	}

	/**
	 * @When /^user "([^"]*)" moves new chunk file with id "([^"]*)"\s?(asynchronously|) to "([^"]*)" with checksum "([^"]*)" using the WebDAV API$/
	 * @Given /^user "([^"]*)" has moved new chunk file with id "([^"]*)"\s?(asynchronously|) to "([^"]*)" with checksum "([^"]*)"
	 *
	 * @param string $user
	 * @param string $id
	 * @param string $type "asynchronously" or empty
	 * @param string $dest
	 * @param string $checksum
	 *
	 * @return void
	 */
	public function userMovesNewChunkFileWithIdToMychunkedfileWithChecksum(
		$user, $id, $type, $dest, $checksum
	) {
		$headers = ['OC-Checksum' => $checksum];
		if ($type === "asynchronously") {
			$headers['OC-LazyOps'] = 'true';
		}
		$this->moveNewDavChunkToFinalFile(
			$user, $id, $dest, $headers
		);
	}

	/**
	 * Move chunked new dav file to final file
	 *
	 * @param string $user user
	 * @param string $id upload id
	 * @param string $destination destination path
	 * @param array $headers extra headers
	 *
	 * @return void
	 */
	private function moveNewDavChunkToFinalFile($user, $id, $destination, $headers) {
		$source = "/uploads/$user/$id/.file";
		$headers['Destination'] = $this->destinationHeaderValue(
			$user, $destination
		);

		$this->response = $this->makeDavRequest(
			$user, 'MOVE', $source, $headers, null, "uploads"
		);
	}

	/**
	 * Delete chunked-upload directory
	 *
	 * @param string $user user
	 * @param string $id upload id
	 * @param array $headers extra headers
	 *
	 * @return void
	 */
	private function deleteUpload($user, $id, $headers) {
		$source = "/uploads/$user/$id";
		$this->response = $this->makeDavRequest(
			$user, 'DELETE', $source, $headers, null, "uploads"
		);
	}

	/**
	 * URL encodes the given path but keeps the slashes
	 *
	 * @param string $path to encode
	 *
	 * @return string encoded path
	 */
	public function encodePath($path) {
		// slashes need to stay
		return \str_replace('%2F', '/', \rawurlencode($path));
	}

	/**
	 * @When an unauthenticated client connects to the dav endpoint using the WebDAV API
	 * @Given an unauthenticated client has connected to the dav endpoint
	 *
	 * @return void
	 */
	public function connectingToDavEndpoint() {
		$this->response = $this->makeDavRequest(
			null, 'PROPFIND', '', []
		);
	}

	/**
	 * @Then there should be no duplicate headers
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function thereAreNoDuplicateHeaders() {
		$headers = $this->response->getHeaders();
		foreach ($headers as $headerName => $headerValues) {
			// if a header has multiple values, they must be different
			if (\count($headerValues) > 1
				&& \count(\array_unique($headerValues)) < \count($headerValues)
			) {
				throw new \Exception("Duplicate header found: $headerName");
			}
		}
	}

	/**
	 * @Then the following headers should not be set
	 *
	 * @param TableNode $table
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function theFollowingHeadersShouldNotBeSet(TableNode $table) {
		foreach ($table->getTable() as $header) {
			$headerName = $header[0];
			$headerValue = $this->response->getHeader($headerName);
			//Note: according to the documentation of getHeader it must return null
			//if the header does not exist, but its returning an empty string
			PHPUnit\Framework\Assert::assertEmpty(
				$headerValue,
				"header $headerName should not exist " .
				"but does and is set to $headerValue"
			);
		}
	}

	/**
	 * @Then the following headers should match these regular expressions
	 *
	 * @param TableNode $table
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function headersShouldMatchRegularExpressions(TableNode $table) {
		foreach ($table->getTable() as $header) {
			$headerName = $header[0];
			$expectedHeaderValue = $header[1];
			$expectedHeaderValue = $this->substituteInLineCodes(
				$expectedHeaderValue, ['preg_quote' => ['/'] ]
			);

			$returnedHeader = $this->response->getHeader($headerName);
			PHPUnit\Framework\Assert::assertNotFalse(
				(bool)\preg_match($expectedHeaderValue, $returnedHeader),
				"'$expectedHeaderValue' does not match '$returnedHeader'"
			);
		}
	}

	/**
	 * @When /^user "([^"]*)" deletes everything from folder "([^"]*)" using the WebDAV API$/
	 * @Given /^user "([^"]*)" has deleted everything from folder "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $folder
	 *
	 * @return void
	 */
	public function userDeletesEverythingInFolder($user, $folder) {
		$responseXmlObject = $this->listFolder($user, $folder, 1);
		$elementList = $responseXmlObject->xpath("//d:response/d:href");
		if (\is_array($elementList) && \count($elementList)) {
			\array_shift($elementList); //don't delete the folder itself
			$davPrefix = "/" . $this->getFullDavFilesPath($user);
			foreach ($elementList as $element) {
				$element = \substr($element, \strlen($davPrefix));
				$this->userDeletesFile($user, $element);
			}
		}
	}

	/**
	 * @param string $user
	 * @param string $path
	 *
	 * @return int
	 */
	public function getFileIdForPath($user, $path) {
		try {
			return WebDavHelper::getFileIdForPath(
				$this->getBaseUrl(),
				$user,
				$this->getPasswordForUser($user),
				$path
			);
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * @Given /^user "([^"]*)" has stored id of file "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $path
	 *
	 * @return void
	 */
	public function userStoresFileIdForPath($user, $path) {
		$this->storedFileID = $this->getFileIdForPath($user, $path);
	}

	/**
	 * @Then /^user "([^"]*)" file "([^"]*)" should have the previously stored id$/
	 *
	 * @param string $user
	 * @param string $path
	 *
	 * @return void
	 */
	public function userFileShouldHaveStoredId($user, $path) {
		$currentFileID = $this->getFileIdForPath($user, $path);
		PHPUnit\Framework\Assert::assertEquals(
			$currentFileID, $this->storedFileID
		);
	}

	/**
	 * @Then /^the (?:Cal|Card)?DAV (exception|message|reason) should be "([^"]*)"$/
	 *
	 * @param string $element exception|message|reason
	 * @param string $message
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function theDavElementShouldBe($element, $message) {
		WebDavAssert::assertDavResponseElementIs(
			$element, $message, $this->responseXml
		);
	}

	/**
	 * @Then /^the (?:propfind|search) result should (not|)\s?contain these (?:files|entries):$/
	 *
	 * @param string $shouldOrNot (not|)
	 * @param TableNode $expectedFiles
	 *
	 * @return void
	 */
	public function propfindResultShouldContainEntries(
		$shouldOrNot, TableNode $expectedFiles
	) {
		$elementRows = $expectedFiles->getRows();
		$should = ($shouldOrNot !== "not");

		foreach ($elementRows as $expectedFile) {
			$fileFound = $this->findEntryFromPropfindResponse(
				$expectedFile[0]
			);
			if ($should) {
				PHPUnit\Framework\Assert::assertNotEmpty(
					$fileFound,
					"response does not contain the entry '$expectedFile[0]'"
				);
			} else {
				PHPUnit\Framework\Assert::assertFalse(
					$fileFound,
					"response does contain the entry '$expectedFile[0]' but should not"
				);
			}
		}
	}

	/**
	 * @Then the propfind/search result should contain :numFiles files/entries
	 *
	 * @param int $numFiles
	 *
	 * @return void
	 */
	public function propfindResultShouldContainNumEntries($numFiles) {
		//if we are using that step the second time in a scenario e.g. 'But ... should not'
		//then don't parse the result again, because the result in a ResponseInterface
		if (empty($this->responseXml)) {
			$this->setResponseXml(
				HttpRequestHelper::parseResponseAsXml($this->response)
			);
		}
		$multistatusResults = $this->responseXml["value"];
		if ($multistatusResults === null) {
			$multistatusResults = [];
		}
		PHPUnit\Framework\Assert::assertEquals((int)$numFiles, \count($multistatusResults));
	}

	/**
	 * @Then the propfind/search result should contain any :expectedNumber of these files/entries:
	 *
	 * @param integer $expectedNumber
	 * @param TableNode $expectedFiles
	 *
	 * @return void
	 */
	public function theSearchResultOfShouldContainAnyOfTheseEntries(
		$expectedNumber, TableNode $expectedFiles
	) {
		$this->propfindResultShouldContainNumEntries($expectedNumber);
		$elementRows = $expectedFiles->getRowsHash();
		$resultEntries = $this->findEntryFromPropfindResponse();
		foreach ($resultEntries as $resultEntry) {
			PHPUnit\Framework\Assert::assertArrayHasKey($resultEntry, $elementRows);
		}
	}

	/**
	 * parses a PROPFIND response from $this->response into xml
	 * and returns found search results if found else returns false
	 *
	 * @param string $entryNameToSearch
	 *
	 * @return string|array|boolean
	 * string if $entryNameToSearch is given and is found
	 * array if $entryNameToSearch is not given
	 * boolean false if $entryNameToSearch is given and is not found
	 */
	public function findEntryFromPropfindResponse($entryNameToSearch = null) {
		//if we are using that step the second time in a scenario e.g. 'But ... should not'
		//then don't parse the result again, because the result in a ResponseInterface
		if (empty($this->responseXml)) {
			$this->setResponseXml(
				HttpRequestHelper::parseResponseAsXml($this->response)
			);
		}
		$fullWebDavPath = \ltrim(
			\parse_url($this->response->getEffectiveUrl(), PHP_URL_PATH) . "/",
			"/"
		);
		$multistatusResults = $this->responseXml["value"];
		$results = [];
		if ($multistatusResults !== null) {
			foreach ($multistatusResults as $multistatusResult) {
				$entryPath = $multistatusResult['value'][0]['value'];
				$entryName = \str_replace($fullWebDavPath, "", $entryPath);
				$entryName = \rawurldecode($entryName);
				if ($entryNameToSearch === $entryName) {
					return $multistatusResult;
				}
				\array_push($results, $entryName);
			}
		}
		if ($entryNameToSearch === null) {
			return $results;
		}
		return false;
	}

	/**
	 * prevent creating two uploads with the same "stime" which is
	 * based on seconds, this prevents creation of uploads with same etag
	 *
	 * @return void
	 */
	public function pauseUploadDelete() {
		$time = \time();
		if ($this->lastUploadDeleteTime !== null && $time - $this->lastUploadDeleteTime < 1) {
			\sleep(1);
		}
	}

	/**
	 * reset settings if they were set in the scenario
	 *
	 * @AfterScenario
	 *
	 * @return void
	 */
	public function resetOldSettingsAfterScenario() {
		if ($this->oldAsyncSetting === "") {
			SetupHelper::runOcc(['config:system:delete', 'dav.enable.async']);
		} elseif ($this->oldAsyncSetting !== null) {
			SetupHelper::runOcc(
				[
					'config:system:set',
					'dav.enable.async',
					'--type',
					'boolean',
					'--value',
					$this->oldAsyncSetting
				]
			);
		}
		if ($this->oldDavSlowdownSetting === "") {
			SetupHelper::runOcc(['config:system:delete', 'dav.slowdown']);
		} elseif ($this->oldDavSlowdownSetting !== null) {
			SetupHelper::runOcc(
				[
					'config:system:set',
					'dav.slowdown',
					'--value',
					$this->oldDavSlowdownSetting
				]
			);
		}
	}
}
