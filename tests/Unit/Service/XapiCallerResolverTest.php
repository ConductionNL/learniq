<?php

/**
 * Tests for the shared LRS caller resolver.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\Cmi5LaunchTokenService;
use OCA\Learniq\Service\XapiCallerResolver;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Token callers carry their launch; session callers need CSRF; a bad token never falls back to the session.
 */
class XapiCallerResolverTest extends TestCase {

	/**
	 * Resolve one request.
	 *
	 * @param string                    $auth   Authorization header.
	 * @param array<string, mixed>|null $claims What the token verifies to.
	 * @param bool                      $csrfOk Whether the request token passes.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve(string $auth, ?array $claims, bool $csrfOk): ?array {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'Authorization' ? $auth : '');
		$request->method('passesCSRFCheck')->willReturn($csrfOk);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil2');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$tokens = $this->createMock(Cmi5LaunchTokenService::class);
		$tokens->method('verifyAuthToken')->willReturn($claims);

		return (new XapiCallerResolver(userSession: $session, tokens: $tokens))->resolve(request: $request);
	}//end resolve()

	/**
	 * A valid token gives its subject and the launch claims.
	 *
	 * @return void
	 */
	public function testTokenCallerCarriesTheLaunch(): void {
		$caller = $this->resolve(
			auth: 'Basic d3JhcHBlZA==',
			claims: ['sub' => 'pupil1', 'aud' => 'lesson-1', 'activityId' => 'https://a', 'registration' => 'reg-1'],
			csrfOk: false
		);

		self::assertSame(
			['actorId' => 'pupil1', 'launch' => ['lessonId' => 'lesson-1', 'courseId' => '', 'activityId' => 'https://a', 'registration' => 'reg-1']],
			$caller
		);
	}//end testTokenCallerCarriesTheLaunch()

	/**
	 * An invalid token is refused even with a valid session; a session needs CSRF.
	 *
	 * @return void
	 */
	public function testRefusalsAndSessionCaller(): void {
		self::assertNull($this->resolve(auth: 'Bearer forged', claims: null, csrfOk: true), 'invalid token, no fallback');
		self::assertNull($this->resolve(auth: '', claims: null, csrfOk: false), 'session without CSRF');
		self::assertSame(['actorId' => 'pupil2', 'launch' => []], $this->resolve(auth: '', claims: null, csrfOk: true));
	}//end testRefusalsAndSessionCaller()
}//end class
