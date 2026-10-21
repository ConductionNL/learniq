<?php

/**
 * Learniq SigningController unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\SigningController;
use OCA\Learniq\Exception\SigningRefusedException;
use OCA\Learniq\Service\Signing\SigningService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The controller passes the session user, never a user from the body, and
 * answers a refusal with its status.
 */
class SigningControllerTest extends TestCase {

	/**
	 * A session for `co`, or none.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return IUserSession
	 */
	private function session(bool $signedIn = true): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($signedIn === false) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('co');
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A request with a body.
	 *
	 * @param array<string, mixed> $body The body.
	 *
	 * @return IRequest
	 */
	private function request(array $body): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $body[$key] ?? $default);

		return $request;
	}//end request()

	/**
	 * Signing passes the session user and the body's role and capture; a signerId in the body is ignored.
	 *
	 * @return void
	 */
	public function testSigningUsesTheSessionUser(): void {
		$capture = ['mode' => 'typed', 'value' => 'C', 'affirmed' => true];
		$service = $this->createMock(SigningService::class);
		$service->expects(self::once())->method('sign')
			->with('learning-plan', 'plan-1', 'co', 'coordinator', $capture)
			->willReturn(['id' => 'sig-1', 'signerRole' => 'coordinator', 'signedAt' => '2026-10-21T08:30:00Z', 'subjectVersion' => 3]);
		$controller = new SigningController(
			request: $this->request(['role' => 'coordinator', 'capture' => $capture, 'signerId' => 'someone-else']),
			userSession: $this->session(),
			signing: $service
		);

		$response = $controller->sign(subject: 'learning-plan', id: 'plan-1');

		self::assertSame(201, $response->getStatus());
		self::assertSame('sig-1', $response->getData()['id']);
	}//end testSigningUsesTheSessionUser()

	/**
	 * A refusal keeps its status and reason; no session answers 401.
	 *
	 * @return void
	 */
	public function testARefusalAndAMissingSession(): void {
		$service = $this->createMock(SigningService::class);
		$service->method('sign')->willThrowException(new SigningRefusedException(status: 403, reason: 'role-not-held', message: 'No.'));
		$service->method('rolesFor')->willReturn(['roles' => ['coordinator'], 'version' => 3, 'signable' => true]);

		$controller = new SigningController(request: $this->request([]), userSession: $this->session(), signing: $service);
		$refused = $controller->sign(subject: 'learning-plan', id: 'plan-1');
		self::assertSame(403, $refused->getStatus());
		self::assertSame('role-not-held', $refused->getData()['reason']);
		self::assertSame(['coordinator'], $controller->roles(subject: 'learning-plan', id: 'plan-1')->getData()['roles']);

		$anonymous = new SigningController(request: $this->request([]), userSession: $this->session(signedIn: false), signing: $service);
		self::assertSame(401, $anonymous->sign(subject: 'learning-plan', id: 'plan-1')->getStatus());
		self::assertSame(401, $anonymous->roles(subject: 'learning-plan', id: 'plan-1')->getStatus());
	}//end testARefusalAndAMissingSession()
}//end class
