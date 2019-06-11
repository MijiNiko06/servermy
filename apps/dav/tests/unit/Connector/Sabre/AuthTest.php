<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Christoph Wurst <christoph@owncloud.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
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

namespace OCA\DAV\Tests\unit\Connector\Sabre;

use OC\Authentication\Exceptions\PasswordLoginForbiddenException;
use OC\Authentication\TwoFactorAuth\Manager;
use OC\Authentication\AccountModule\Manager as AccountModuleManager;
use OC\User\LoginException;
use OC\User\Session;
use OCA\DAV\Connector\Sabre\Auth;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Test\TestCase;

/**
 * Class AuthTest
 *
 * @package OCA\DAV\Tests\unit\Connector\Sabre
 * @group DB
 */
class AuthTest extends TestCase {
	/** @var ISession | \PHPUnit\Framework\MockObject\MockObject */
	private $session;
	/** @var Auth */
	private $auth;
	/** @var Session | \PHPUnit\Framework\MockObject\MockObject */
	private $userSession;
	/** @var IRequest | \PHPUnit\Framework\MockObject\MockObject */
	private $request;
	/** @var Manager | \PHPUnit\Framework\MockObject\MockObject */
	private $twoFactorManager;
	/** @var AccountModuleManager | \PHPUnit\Framework\MockObject\MockObject */
	private $accountModuleManager;

	public function setUp(): void {
		parent::setUp();
		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(Session::class);
		$this->request = $this->createMock(IRequest::class);
		$this->twoFactorManager = $this->createMock(Manager::class);
		$this->accountModuleManager = $this->createMock(AccountModuleManager::class);
		$this->auth = new Auth(
			$this->session,
			$this->userSession,
			$this->request,
			$this->twoFactorManager,
			$this->accountModuleManager
		);
	}

	public function testIsDavAuthenticatedWithoutDavSession() {
		$this->session
			->expects($this->once())
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue(null));

		$this->assertFalse($this->invokePrivate($this->auth, 'isDavAuthenticated', ['MyTestUser']));
	}

	public function testIsDavAuthenticatedWithWrongDavSession() {
		$this->session
			->expects($this->exactly(2))
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue('AnotherUser'));

		$this->assertFalse($this->invokePrivate($this->auth, 'isDavAuthenticated', ['MyTestUser']));
	}

	public function testIsDavAuthenticatedWithCorrectDavSession() {
		$this->session
			->expects($this->exactly(2))
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue('MyTestUser'));

		$this->assertTrue($this->invokePrivate($this->auth, 'isDavAuthenticated', ['MyTestUser']));
	}

	public function testValidateUserPassOfAlreadyDAVAuthenticatedUser() {
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->exactly(2))
			->method('getUID')
			->will($this->returnValue('MyTestUser'));
		$this->userSession
			->expects($this->once())
			->method('isLoggedIn')
			->will($this->returnValue(true));
		$this->userSession
			->expects($this->once())
			->method('verifyAuthHeaders')
			->will($this->returnValue(true));
		$this->userSession
			->expects($this->exactly(2))
			->method('getUser')
			->will($this->returnValue($user));
		$this->session
			->expects($this->exactly(2))
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue('MyTestUser'));
		$this->session
			->expects($this->once())
			->method('close');

		$this->assertTrue($this->invokePrivate($this->auth, 'validateUserPass', ['MyTestUser', 'MyTestPassword']));
	}

	public function testValidateUserPassOfInvalidDAVAuthenticatedUser() {
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->once())
			->method('getUID')
			->will($this->returnValue('MyTestUser'));
		$this->userSession
			->expects($this->once())
			->method('isLoggedIn')
			->will($this->returnValue(true));
		$this->userSession
			->expects($this->once())
			->method('verifyAuthHeaders')
			->will($this->returnValue(true));
		$this->userSession
			->expects($this->once())
			->method('getUser')
			->will($this->returnValue($user));
		$this->session
			->expects($this->exactly(2))
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue('AnotherUser'));
		$this->session
			->expects($this->once())
			->method('close');

		$this->assertFalse($this->invokePrivate($this->auth, 'validateUserPass', ['MyTestUser', 'MyTestPassword']));
	}

	public function testValidateUserPassOfInvalidDAVAuthenticatedUserWithValidPassword() {
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->exactly(3))
			->method('getUID')
			->will($this->returnValue('MyTestUser'));
		$this->userSession
			->expects($this->once())
			->method('isLoggedIn')
			->will($this->returnValue(true));
		$this->userSession
			->expects($this->once())
			->method('verifyAuthHeaders')
			->will($this->returnValue(true));
		$this->userSession
			->expects($this->exactly(3))
			->method('getUser')
			->will($this->returnValue($user));
		$this->session
			->expects($this->exactly(2))
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue('AnotherUser'));
		$this->userSession
			->expects($this->once())
			->method('logClientIn')
			->with('MyTestUser', 'MyTestPassword', $this->request)
			->will($this->returnValue(true));
		$this->session
			->expects($this->once())
			->method('set')
			->with('AUTHENTICATED_TO_DAV_BACKEND', 'MyTestUser');
		$this->session
			->expects($this->once())
			->method('close');

		$this->assertTrue($this->invokePrivate($this->auth, 'validateUserPass', ['MyTestUser', 'MyTestPassword']));
	}

	public function testValidateUserPassWithInvalidPassword() {
		$this->userSession
			->expects($this->once())
			->method('isLoggedIn')
			->will($this->returnValue(false));
		$this->userSession
			->expects($this->once())
			->method('logClientIn')
			->with('MyTestUser', 'MyTestPassword')
			->will($this->returnValue(false));
		$this->session
			->expects($this->once())
			->method('close');

		$this->assertFalse($this->invokePrivate($this->auth, 'validateUserPass', ['MyTestUser', 'MyTestPassword']));
	}

	/**
	 * @expectedException \OCA\DAV\Connector\Sabre\Exception\PasswordLoginForbidden
	 */
	public function testValidateUserPassWithPasswordLoginForbidden() {
		$this->userSession
			->expects($this->once())
			->method('isLoggedIn')
			->will($this->returnValue(false));
		$this->userSession
			->expects($this->once())
			->method('logClientIn')
			->with('MyTestUser', 'MyTestPassword')
			->will($this->throwException(new PasswordLoginForbiddenException()));
		$this->session
			->expects($this->once())
			->method('close');

		$this->invokePrivate($this->auth, 'validateUserPass', ['MyTestUser', 'MyTestPassword']);
	}

	public function testAuthenticateAlreadyLoggedInWithoutCsrfTokenForNonGet() {
		/** @var RequestInterface | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->getMockBuilder(RequestInterface::class)
				->disableOriginalConstructor()
				->getMock();
		/** @var ResponseInterface | \PHPUnit\Framework\MockObject\MockObject $response */
		$response = $this->getMockBuilder(ResponseInterface::class)
				->disableOriginalConstructor()
				->getMock();
		$this->userSession
			->expects($this->any())
			->method('isLoggedIn')
			->will($this->returnValue(true));
		$this->request
			->expects($this->any())
			->method('getMethod')
			->willReturn('POST');
		$this->session
			->expects($this->any())
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue(null));
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('MyWrongDavUser'));
		$this->userSession
			->expects($this->any())
			->method('getUser')
			->will($this->returnValue($user));
		$this->request
			->expects($this->once())
			->method('passesCSRFCheck')
			->willReturn(false);

		$expectedResponse = [
			false,
			"No 'Authorization: Basic' header found. Either the client didn't send one, or the server is misconfigured",
		];
		$response = $this->auth->check($request, $response);
		$this->assertSame($expectedResponse, $response);
	}

	public function testAuthenticateAlreadyLoggedInWithoutCsrfTokenAndCorrectlyDavAuthenticated() {
		/** @var RequestInterface | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ResponseInterface | \PHPUnit\Framework\MockObject\MockObject $response */
		$response = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession
			->expects($this->any())
			->method('isLoggedIn')
			->willReturn(true);
		$this->request
			->expects($this->any())
			->method('getMethod')
			->willReturn('POST');
		$this->request
			->expects($this->any())
			->method('isUserAgent')
			->with([
				'/^Mozilla\/5\.0 \([A-Za-z ]+\) (mirall|csyncoC)\/.*$/',
				'/^Mozilla\/5\.0 \(Android\) ownCloud\-android.*$/',
				'/^Mozilla\/5\.0 \(iOS\) ownCloud\-iOS.*$/',
			])
			->willReturn(false);
		$this->session
			->expects($this->any())
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue('LoggedInUser'));
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('LoggedInUser'));
		$this->userSession
			->expects($this->any())
			->method('getUser')
			->will($this->returnValue($user));
		$this->request
			->expects($this->once())
			->method('passesCSRFCheck')
			->willReturn(false);
		$this->auth->check($request, $response);
	}

	/**
	 * @expectedException \Sabre\DAV\Exception\NotAuthenticated
	 * @expectedExceptionMessage 2FA challenge not passed.
	 */
	public function testAuthenticateAlreadyLoggedInWithoutTwoFactorChallengePassed() {
		/** @var RequestInterface | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ResponseInterface | \PHPUnit\Framework\MockObject\MockObject $response */
		$response = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession
			->expects($this->any())
			->method('isLoggedIn')
			->willReturn(true);
		$this->request
			->expects($this->any())
			->method('getMethod')
			->willReturn('POST');
		$this->request
			->expects($this->any())
			->method('isUserAgent')
			->with([
				'/^Mozilla\/5\.0 \([A-Za-z ]+\) (mirall|csyncoC)\/.*$/',
				'/^Mozilla\/5\.0 \(Android\) ownCloud\-android.*$/',
				'/^Mozilla\/5\.0 \(iOS\) ownCloud\-iOS.*$/',
			])
			->willReturn(false);
		$this->session
			->expects($this->any())
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue('LoggedInUser'));
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('LoggedInUser'));
		$this->userSession
			->expects($this->any())
			->method('getUser')
			->will($this->returnValue($user));
		$this->request
			->expects($this->once())
			->method('passesCSRFCheck')
			->willReturn(true);
		$this->twoFactorManager->expects($this->once())
			->method('needsSecondFactor')
			->will($this->returnValue(true));
		$this->auth->check($request, $response);
	}

	public function testAuthenticateAlreadyLoggedInWithoutCsrfTokenForNonGetAndDesktopClient() {
		/** @var RequestInterface | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ResponseInterface | \PHPUnit\Framework\MockObject\MockObject $response */
		$response = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession
			->expects($this->any())
			->method('isLoggedIn')
			->will($this->returnValue(true));
		$this->request
			->expects($this->any())
			->method('getMethod')
			->willReturn('POST');
		$this->request
			->expects($this->any())
			->method('isUserAgent')
			->with([
				'/^Mozilla\/5\.0 \([A-Za-z ]+\) (mirall|csyncoC)\/.*$/',
				'/^Mozilla\/5\.0 \(Android\) ownCloud\-android.*$/',
				'/^Mozilla\/5\.0 \(iOS\) ownCloud\-iOS.*$/',
			])
			->willReturn(true);
		$this->session
			->expects($this->any())
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue(null));
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('MyWrongDavUser'));
		$this->userSession
			->expects($this->any())
			->method('getUser')
			->will($this->returnValue($user));
		$this->request
			->expects($this->once())
			->method('passesCSRFCheck')
			->willReturn(false);

		$this->auth->check($request, $response);
	}

	public function testAuthenticateAlreadyLoggedInWithoutCsrfTokenForGet() {
		/** @var RequestInterface | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ResponseInterface | \PHPUnit\Framework\MockObject\MockObject $response */
		$response = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession
			->expects($this->any())
			->method('isLoggedIn')
			->will($this->returnValue(true));
		$this->session
			->expects($this->any())
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue(null));
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('MyWrongDavUser'));
		$this->userSession
			->expects($this->any())
			->method('getUser')
			->will($this->returnValue($user));
		$this->request
			->expects($this->any())
			->method('getMethod')
			->willReturn('GET');

		$response = $this->auth->check($request, $response);
		$this->assertEquals([true, 'principals/users/MyWrongDavUser'], $response);
	}

	public function testAuthenticateAlreadyLoggedInWithCsrfTokenForGet() {
		/** @var RequestInterface | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ResponseInterface | \PHPUnit\Framework\MockObject\MockObject $response */
		$response = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession
			->expects($this->any())
			->method('isLoggedIn')
			->will($this->returnValue(true));
		$this->session
			->expects($this->any())
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue(null));
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('MyWrongDavUser'));
		$this->userSession
			->expects($this->any())
			->method('getUser')
			->will($this->returnValue($user));
		$this->request
			->expects($this->once())
			->method('passesCSRFCheck')
			->willReturn(true);

		$response = $this->auth->check($request, $response);
		$this->assertEquals([true, 'principals/users/MyWrongDavUser'], $response);
	}

	/**
	 * @expectedException Sabre\DAV\Exception\NotAuthenticated
	 */
	public function testAutenticateWithLoggedInUserButLoginExceptionThrown() {
		/** @var RequestInterface | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ResponseInterface | \PHPUnit\Framework\MockObject\MockObject $response */
		$response = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession
			->expects($this->any())
			->method('isLoggedIn')
			->willThrowException(new LoginException());
		$response = $this->auth->check($request, $response);
	}

	public function testAuthenticateNoBasicAuthenticateHeadersProvided() {
		$server = $this->getMockBuilder('\Sabre\DAV\Server')
			->disableOriginalConstructor()
			->getMock();
		$server->httpRequest = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$server->httpResponse = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$response = $this->auth->check($server->httpRequest, $server->httpResponse);
		$this->assertEquals([false, 'No \'Authorization: Basic\' header found. Either the client didn\'t send one, or the server is misconfigured'], $response);
	}

	/**
	 * @expectedException \Sabre\DAV\Exception\NotAuthenticated
	 * @expectedExceptionMessage Cannot authenticate over ajax calls
	 */
	public function testAuthenticateNoBasicAuthenticateHeadersProvidedWithAjax() {
		/** @var RequestInterface | \PHPUnit\Framework\MockObject\MockObject $httpRequest */
		$httpRequest = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ResponseInterface $httpResponse */
		$httpResponse = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession
			->expects($this->any())
			->method('isLoggedIn')
			->will($this->returnValue(false));
		$httpRequest
			->expects($this->once())
			->method('getHeader')
			->with('X-Requested-With')
			->will($this->returnValue('XMLHttpRequest'));
		$this->auth->check($httpRequest, $httpResponse);
	}

	public function testAuthenticateNoBasicAuthenticateHeadersProvidedWithAjaxButUserIsStillLoggedIn() {
		/** @var RequestInterface | \PHPUnit\Framework\MockObject\MockObject $httpRequest */
		$httpRequest = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ResponseInterface $httpResponse */
		$httpResponse = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var IUser */
		$user = $this->createMock('OCP\IUser');
		$user->method('getUID')->willReturn('MyTestUser');
		$this->userSession
			->expects($this->any())
			->method('isLoggedIn')
			->will($this->returnValue(true));
		$this->userSession
			->expects($this->any())
			->method('getUser')
			->willReturn($user);
		$this->session
			->expects($this->atLeastOnce())
			->method('get')
			->with('AUTHENTICATED_TO_DAV_BACKEND')
			->will($this->returnValue('MyTestUser'));
		$this->request
			->expects($this->once())
			->method('getMethod')
			->willReturn('GET');
		$httpRequest
			->expects($this->atLeastOnce())
			->method('getHeader')
			->with('Authorization')
			->will($this->returnValue(null));
		$this->assertEquals(
			[true, 'principals/users/MyTestUser'],
			$this->auth->check($httpRequest, $httpResponse)
		);
	}

	public function testAuthenticateValidCredentials() {
		$server = $this->getMockBuilder('\Sabre\DAV\Server')
			->disableOriginalConstructor()
			->getMock();
		$server->httpRequest = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$server->httpRequest
			->expects($this->at(0))
			->method('getHeader')
			->with('X-Requested-With')
			->will($this->returnValue(null));
		$server->httpRequest
			->expects($this->at(1))
			->method('getHeader')
			->with('Authorization')
			->will($this->returnValue('basic dXNlcm5hbWU6cGFzc3dvcmQ='));
		$server->httpResponse = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession
			->expects($this->once())
			->method('logClientIn')
			->with('username', 'password')
			->will($this->returnValue(true));
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->exactly(3))
			->method('getUID')
			->will($this->returnValue('MyTestUser'));
		$this->userSession
			->expects($this->exactly(3))
			->method('getUser')
			->will($this->returnValue($user));
		$response = $this->auth->check($server->httpRequest, $server->httpResponse);
		$this->assertEquals([true, 'principals/users/MyTestUser'], $response);
	}

	public function testAuthenticateInvalidCredentials() {
		$server = $this->getMockBuilder('\Sabre\DAV\Server')
			->disableOriginalConstructor()
			->getMock();
		$server->httpRequest = $this->getMockBuilder(RequestInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$server->httpRequest
			->expects($this->at(0))
			->method('getHeader')
			->with('X-Requested-With')
			->will($this->returnValue(null));
		$server->httpRequest
			->expects($this->at(1))
			->method('getHeader')
			->with('Authorization')
			->will($this->returnValue('basic dXNlcm5hbWU6cGFzc3dvcmQ='));
		$server->httpResponse = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userSession
			->expects($this->once())
			->method('logClientIn')
			->with('username', 'password')
			->will($this->returnValue(false));
		$response = $this->auth->check($server->httpRequest, $server->httpResponse);
		$this->assertEquals([false, 'Username or password was incorrect'], $response);
	}
}
