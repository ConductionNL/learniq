<?php

/**
 * Tests for PortalPermissionController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/permission-slips
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalPermissionController;
use OCA\Learniq\Kernel\Contracts\PortalPermissionAnswerInterface;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The receiver passes only a verified guardian's whitelisted answer on.
 *
 * @covers \OCA\Learniq\Controller\PortalPermissionController
 * @uses   \OCA\Learniq\Portal\PortalAssertionVerifier
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
 */
class PortalPermissionControllerTest extends TestCase {

	private const SECRET = 'a-test-secret-of-sufficient-length';

	private const GUARDIAN = 'ee050010-0000-4000-8000-000000000001';

	private const REQUEST = 'ee050030-0000-4000-8000-000000000001';

	private array $calls = [];

	/**
	 * A signed portal assertion.
	 *
	 * @param array<string, mixed> $claims Extra claims.
	 *
	 * @return string
	 */
	private static function assertion(array $claims): string {
		$encode = static fn (array $part): string => rtrim(strtr(base64_encode((string)json_encode($part)), '+/', '-_'), '=');
		$head = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
		$body = $encode(
			array_merge(
				['iss' => 'portaliq', 'use' => 'assertion', 'iat' => time(), 'exp' => (time() + 60), 'sub' => self::GUARDIAN],
				$claims
			)
		);
		$signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $head . '.' . $body, self::SECRET, true)), '+/', '-_'), '=');

		return $head . '.' . $body . '.' . $signature;
	}//end assertion()

	/**
	 * The controller over a recording answer service.
	 *
	 * @param string               $jwt    The assertion header.
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $throws Whether the service throws.
	 *
	 * @return PortalPermissionController
	 */
	private function controller(string $jwt, array $params, bool $throws = false): PortalPermissionController {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($jwt);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$answers = $this->createMock(PortalPermissionAnswerInterface::class);
		$answers->method('answer')->willReturnCallback(
			function (string $guardianRef, string $trust, array $body) use ($throws): PortalOutcome {
				$this->calls[] = ['guardianRef' => $guardianRef, 'trust' => $trust, 'body' => $body];
				if ($throws === true) {
					throw new RuntimeException('SQLSTATE secret');
				}

				return new PortalOutcome(status: 409, body: ['error' => 'request_closed']);
			}
		);

		return new PortalPermissionController(
			request: $request,
			verifier: new PortalAssertionVerifier(secretOverride: self::SECRET),
			answers: $answers,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * A guardian's assertion reaches the service with only the answer fields.
	 *
	 * @return void
	 */
	public function testAGuardianAnswerReachesTheService(): void {
		$response = $this->controller(
			jwt: self::assertion(['audience' => 'parent', 'trust' => 'substantial']),
			params: [
				'guardianRef' => self::GUARDIAN,
				'permissionRequestId' => self::REQUEST,
				'learnerRef' => 'lp-vera',
				'answer' => 'yes',
				'assuranceLevel' => 'high',
				'answeredByRef' => 'someone-else',
			]
		)->answer();

		self::assertSame(409, $response->getStatus());
		self::assertSame(['error' => 'request_closed'], $response->getData());
		self::assertSame(
			[['guardianRef' => self::GUARDIAN, 'trust' => 'substantial', 'body' => ['permissionRequestId' => self::REQUEST, 'learnerRef' => 'lp-vera', 'answer' => 'yes']]],
			$this->calls
		);
	}//end testAGuardianAnswerReachesTheService()

	/**
	 * Without a valid assertion nothing is called.
	 *
	 * @return void
	 */
	public function testAnUnsignedCallIsUnauthorized(): void {
		$response = $this->controller(jwt: 'not-a-jwt', params: ['guardianRef' => self::GUARDIAN])->answer();

		self::assertSame(401, $response->getStatus());
		self::assertSame([], $this->calls);
	}//end testAnUnsignedCallIsUnauthorized()

	/**
	 * Another audience, or no guardian subject, is forbidden.
	 *
	 * @return void
	 */
	public function testAnotherAudienceOrNoGuardianIsForbidden(): void {
		self::assertSame(403, $this->controller(jwt: self::assertion(['audience' => 'student', 'trust' => 'low']), params: ['guardianRef' => self::GUARDIAN])->answer()->getStatus());
		self::assertSame(403, $this->controller(jwt: self::assertion(['audience' => 'parent', 'trust' => 'low']), params: [])->answer()->getStatus());
		self::assertSame([], $this->calls);
	}//end testAnotherAudienceOrNoGuardianIsForbidden()

	/**
	 * A failing service is a 502 without its message.
	 *
	 * @return void
	 */
	public function testAFailingServiceLeaksNothing(): void {
		$response = $this->controller(jwt: self::assertion(['audience' => 'parent', 'trust' => 'low']), params: ['guardianRef' => self::GUARDIAN], throws: true)->answer();

		self::assertSame(502, $response->getStatus());
		self::assertSame(['error' => 'downstream_error'], $response->getData());
	}//end testAFailingServiceLeaksNothing()
}//end class
