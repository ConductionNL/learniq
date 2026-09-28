<?php

/**
 * Learniq PortalSubmissionController unit tests.
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
 * @spec openspec/changes/portal-assignment-hand-in-endpoint/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalSubmissionController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalMessages;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\Portal\PortalSubmissionHandIn;
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
 * The hand-in receiver: the same order and answers as the test receiver.
 */
class PortalSubmissionControllerTest extends TestCase {

	/**
	 * Calls that reached the hand-in service.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed>|null $claims What the verifier returns.
	 * @param array<string, mixed> $params The forwarded body.
	 * @param bool $pupilExists Whether learnerRef resolves.
	 * @param PortalOutcome|null $outcome What the hand-in returns.
	 * @param bool $throws Whether the hand-in throws.
	 *
	 * @return PortalSubmissionController
	 */
	private function controller(?array $claims, array $params, bool $pupilExists = true, ?PortalOutcome $outcome = null, bool $throws = false): PortalSubmissionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn($claims);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$learners = $this->createMock(PortalLearnerResolver::class);
		$learners->method('resolve')->willReturnCallback(
			static fn (string $learnerRef): ?PortalLearner => ($pupilExists === true)
				? new PortalLearner(profileRef: $learnerRef, ncUserId: 'pupil-1', tenantId: '', user: $user)
				: null
		);

		$outcome = ($outcome ?? new PortalOutcome(status: 200, body: ['submissionId' => 'sub-1', 'lifecycle' => 'submitted']));
		$handIns = $this->createMock(PortalSubmissionHandIn::class);
		$handIns->method('handIn')->willReturnCallback(
			function (PortalLearner $learner, string $submissionId) use ($outcome, $throws): PortalOutcome {
				$this->calls[] = ['learner' => $learner->profileRef, 'submissionId' => $submissionId];
				if ($throws === true) {
					throw new RuntimeException('SQLSTATE secret table name');
				}

				return $outcome;
			}
		);

		$messages = $this->createMock(PortalMessages::class);
		$messages->method('message')->willReturnCallback(static fn (string $reason, ?IUser $u): string => 'msg:' . $reason . ':' . ($u?->getUID() ?? 'none'));

		return new PortalSubmissionController(
			request: $request,
			verifier: $verifier,
			learners: $learners,
			handIns: $handIns,
			messages: $messages,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * A student assertion.
	 *
	 * @return array<string, mixed>
	 */
	private function student(): array {
		return ['sub' => 'subject-1', 'audience' => 'student', 'use' => 'assertion'];
	}//end student()

	/**
	 * The route is public for Nextcloud's middleware, CSRF-free and rate limited.
	 *
	 * @return void
	 */
	public function testTheHandInIsAPublicCsrfFreeRateLimitedReceiver(): void {
		$method = new ReflectionMethod(PortalSubmissionController::class, 'handIn');

		self::assertNotEmpty($method->getAttributes(PublicPage::class));
		self::assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
		self::assertNotEmpty($method->getAttributes(AnonRateLimit::class));
	}//end testTheHandInIsAPublicCsrfFreeRateLimitedReceiver()

	/**
	 * No valid assertion: 401, throttled, nothing reached.
	 *
	 * @return void
	 */
	public function testNoValidAssertionIsUnauthorizedAndThrottled(): void {
		$response = $this->controller(claims: null, params: ['learnerRef' => 'lp-1', 'submissionId' => 'sub-1'])->handIn();

		self::assertSame(401, $response->getStatus());
		self::assertTrue($response->isThrottled());
		self::assertSame([], $this->calls);
	}//end testNoValidAssertionIsUnauthorizedAndThrottled()

	/**
	 * Another audience, no learnerRef, or no pupil account: 403, nothing reached.
	 *
	 * @return void
	 */
	public function testAnotherAudienceNoLearnerOrNoAccountIsForbidden(): void {
		$parent = $this->controller(claims: ['audience' => 'parent'] + $this->student(), params: ['learnerRef' => 'lp-1', 'submissionId' => 'sub-1'])->handIn();
		$noLearner = $this->controller(claims: $this->student(), params: ['submissionId' => 'sub-1'])->handIn();
		$noAccount = $this->controller(claims: $this->student(), params: ['learnerRef' => 'lp-9', 'submissionId' => 'sub-1'], pupilExists: false)->handIn();

		self::assertSame(403, $parent->getStatus());
		self::assertSame(['error' => 'forbidden'], $parent->getData());
		self::assertSame(403, $noLearner->getStatus());
		self::assertSame(403, $noAccount->getStatus());
		self::assertSame(['error' => 'not_available', 'message' => 'msg:no-account:none'], $noAccount->getData());
		self::assertSame([], $this->calls);
	}//end testAnotherAudienceNoLearnerOrNoAccountIsForbidden()

	/**
	 * The hand-in gets the stamped learner and the submission id; a refusal
	 * carries the pupil's message; a failure is a bare 502.
	 *
	 * @return void
	 */
	public function testTheHandInIsDelegatedAndItsAnswerRelayed(): void {
		$params = ['learnerRef' => 'lp-1', 'submissionId' => ' sub-1 ', 'learnerId' => 'someone-else'];

		$ok = $this->controller(claims: $this->student(), params: $params)->handIn();
		self::assertSame(200, $ok->getStatus());
		self::assertSame(['submissionId' => 'sub-1', 'lifecycle' => 'submitted'], $ok->getData());
		self::assertSame([['learner' => 'lp-1', 'submissionId' => 'sub-1']], $this->calls);

		$late = new PortalOutcome(status: 422, body: ['error' => 'late_not_accepted'], reason: 'late-not-accepted');
		$refused = $this->controller(claims: $this->student(), params: $params, outcome: $late)->handIn();
		self::assertSame(422, $refused->getStatus());
		self::assertSame(['error' => 'late_not_accepted', 'message' => 'msg:late-not-accepted:pupil-1'], $refused->getData());

		$broken = $this->controller(claims: $this->student(), params: $params, throws: true)->handIn();
		self::assertSame(502, $broken->getStatus());
		self::assertSame(['error' => 'downstream_error'], $broken->getData());
	}//end testTheHandInIsDelegatedAndItsAnswerRelayed()
}//end class
