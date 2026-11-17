<?php

/**
 * The proof-of-enrolment receiver: refused before the service without a
 * student assertion or a known learner, and run as the proved learner.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalProofOfEnrolmentController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\Portal\ProofOfEnrolment;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for PortalProofOfEnrolmentController.
 */
class PortalProofOfEnrolmentControllerTest extends TestCase {

	/**
	 * The learners the service was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * The receiver.
	 *
	 * @param array<string, mixed>|null $claims      What the verifier returns.
	 * @param bool                      $pupilExists Whether learnerRef resolves.
	 * @param PortalOutcome|null        $outcome     What the service answers; null: it throws.
	 *
	 * @return PortalProofOfEnrolmentController
	 */
	private function receiver(?array $claims, bool $pupilExists=true, ?PortalOutcome $outcome=null): PortalProofOfEnrolmentController {
		$params  = ['learnerRef' => 'lp-1'];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default=null) => ($params[$key] ?? $default));

		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn($claims);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$learners = $this->createMock(PortalLearnerResolver::class);
		$learners->method('resolve')->willReturn(
			($pupilExists === true) ? new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: '', user: $user) : null
		);

		$proofs = $this->createMock(ProofOfEnrolment::class);
		$proofs->method('request')->willReturnCallback(
			function (PortalLearner $learner) use ($outcome): PortalOutcome {
				$this->asked[] = $learner->profileRef;
				if ($outcome === null) {
					throw new RuntimeException('SQLSTATE secret');
				}

				return $outcome;
			}
		);
		$proofs->method('message')->willReturnCallback(static fn (string $reason, ?IUser $u): string => 'msg:'.$reason);

		return new PortalProofOfEnrolmentController(request: $request, verifier: $verifier, learners: $learners, proofs: $proofs, logger: new NullLogger());
	}//end receiver()

	/**
	 * The receiver is public for the middleware, CSRF-free and rate limited.
	 *
	 * @return void
	 */
	public function testTheReceiverIsAPublicRateLimitedRoute(): void {
		$method = new ReflectionMethod(PortalProofOfEnrolmentController::class, 'request');

		self::assertNotEmpty($method->getAttributes(PublicPage::class));
		self::assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
		self::assertNotEmpty($method->getAttributes(AnonRateLimit::class));
	}//end testTheReceiverIsAPublicRateLimitedRoute()

	/**
	 * No assertion, a parent's assertion or an unknown learner never reach the service.
	 *
	 * @return void
	 */
	public function testTheReceiverRefusesBeforeTheService(): void {
		$response = $this->receiver(claims: null)->request();
		self::assertSame(401, $response->getStatus());
		self::assertSame(403, $this->receiver(claims: ['audience' => 'parent'])->request()->getStatus());
		self::assertSame(403, $this->receiver(claims: ['audience' => 'student'], pupilExists: false)->request()->getStatus());
		self::assertSame([], $this->asked);
	}//end testTheReceiverRefusesBeforeTheService()

	/**
	 * The proof is asked for the proved learner; a refusal is worded, and a
	 * failure answers 502 without internals.
	 *
	 * @return void
	 */
	public function testTheProofIsAskedForTheProvedLearner(): void {
		$made = $this->receiver(claims: ['audience' => 'student'], outcome: new PortalOutcome(status: 201, body: ['status' => 'created', 'title' => 'T']))->request();
		self::assertSame(201, $made->getStatus());
		self::assertSame(['status' => 'created', 'title' => 'T'], $made->getData());

		$refused = $this->receiver(
			claims: ['audience' => 'student'],
			outcome: new PortalOutcome(status: 409, body: ['error' => 'no_active_enrolment'], reason: 'no-active-enrolment')
		)->request();
		self::assertSame(409, $refused->getStatus());
		self::assertSame('msg:no-active-enrolment', $refused->getData()['message']);
		self::assertSame(['lp-1', 'lp-1'], $this->asked);

		$failed = $this->receiver(claims: ['audience' => 'student'])->request();
		self::assertSame(502, $failed->getStatus());
		self::assertSame(['error' => 'downstream_error'], $failed->getData());
	}//end testTheProofIsAskedForTheProvedLearner()
}//end class
