<?php

/**
 * Learniq PortalAssessmentController unit tests.
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalAssessmentController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalAttemptService;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalMessages;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\Portal\PortalResultReader;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for PortalAssessmentController.
 */
class PortalAssessmentControllerTest extends TestCase {

	/**
	 * The five routed methods.
	 */
	private const STEPS = ['available', 'start', 'answer', 'submit', 'result'];

	/**
	 * Calls that reached the services: method and named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed>|null $claims What the verifier returns.
	 * @param array<string, mixed> $params The forwarded body.
	 * @param bool $pupilExists Whether learnerRef resolves.
	 * @param PortalOutcome|null $outcome What each step returns.
	 * @param bool $stepThrows Whether each step throws.
	 *
	 * @return PortalAssessmentController
	 */
	private function controller(?array $claims, array $params, bool $pupilExists = true, ?PortalOutcome $outcome = null, bool $stepThrows = false): PortalAssessmentController {
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

		$outcome = ($outcome ?? new PortalOutcome(status: 200, body: ['ok' => true]));
		$record = function (string $method, array $args) use ($outcome, $stepThrows): PortalOutcome {
			$this->calls[] = ['method' => $method] + $args;
			if ($stepThrows === true) {
				throw new RuntimeException('SQLSTATE secret table name');
			}

			return $outcome;
		};

		$attempts = $this->createMock(PortalAttemptService::class);
		$attempts->method('available')->willReturnCallback(fn (PortalLearner $learner) => $record('available', ['learner' => $learner->profileRef]));
		$attempts->method('start')->willReturnCallback(fn (PortalLearner $learner, string $taskId, mixed $accessCode) => $record('start', ['taskId' => $taskId, 'accessCode' => $accessCode]));
		$attempts->method('answer')->willReturnCallback(fn (PortalLearner $learner, string $attemptId, string $itemId, mixed $response) => $record('answer', ['attemptId' => $attemptId, 'itemId' => $itemId, 'response' => $response]));
		$attempts->method('submit')->willReturnCallback(fn (PortalLearner $learner, string $attemptId) => $record('submit', ['attemptId' => $attemptId]));
		$results = $this->createMock(PortalResultReader::class);
		$results->method('result')->willReturnCallback(fn (PortalLearner $learner, string $attemptId) => $record('result', ['attemptId' => $attemptId]));

		$messages = $this->createMock(PortalMessages::class);
		$messages->method('message')->willReturnCallback(static fn (string $reason, ?IUser $u): string => 'msg:' . $reason . ':' . ($u?->getUID() ?? 'none'));

		return new PortalAssessmentController(
			request: $request,
			verifier: $verifier,
			learners: $learners,
			attempts: $attempts,
			results: $results,
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
	 * Every step is public for Nextcloud's middleware (no session on a forward)
	 * and CSRF-free; the assertion is checked inside.
	 *
	 * @return void
	 */
	public function testEveryStepIsAPublicCsrfFreeReceiver(): void {
		foreach (self::STEPS as $step) {
			$method = new ReflectionMethod(PortalAssessmentController::class, $step);
			self::assertNotEmpty($method->getAttributes(PublicPage::class), $step);
			self::assertNotEmpty($method->getAttributes(NoCSRFRequired::class), $step);
		}
	}//end testEveryStepIsAPublicCsrfFreeReceiver()

	/**
	 * No valid assertion: 401, throttled, and no service is touched.
	 *
	 * @return void
	 */
	public function testNoValidAssertionIsUnauthorizedAndThrottled(): void {
		foreach (self::STEPS as $step) {
			$response = $this->controller(claims: null, params: ['learnerRef' => 'lp-1'])->$step();

			self::assertSame(401, $response->getStatus(), $step);
			self::assertSame(['error' => 'unauthorized'], $response->getData());
			self::assertTrue($response->isThrottled());
		}

		self::assertSame([], $this->calls);
	}//end testNoValidAssertionIsUnauthorizedAndThrottled()

	/**
	 * Another audience, or no learnerRef, is forbidden.
	 *
	 * @return void
	 */
	public function testAnotherAudienceOrNoLearnerIsForbidden(): void {
		$parent = $this->controller(claims: ['audience' => 'parent'] + $this->student(), params: ['learnerRef' => 'lp-1'])->available();
		$noLearner = $this->controller(claims: $this->student(), params: [])->available();

		self::assertSame(403, $parent->getStatus());
		self::assertSame(['error' => 'forbidden'], $parent->getData());
		self::assertSame(403, $noLearner->getStatus());
		self::assertSame([], $this->calls);
	}//end testAnotherAudienceOrNoLearnerIsForbidden()

	/**
	 * A learnerRef without an active profile or account is not available.
	 *
	 * @return void
	 */
	public function testAPupilWithoutAnAccountIsNotAvailable(): void {
		$response = $this->controller(claims: $this->student(), params: ['learnerRef' => 'lp-9'], pupilExists: false)->start();

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'not_available', 'message' => 'msg:no-account:none'], $response->getData());
		self::assertSame([], $this->calls);
	}//end testAPupilWithoutAnAccountIsNotAvailable()

	/**
	 * Each step reaches its service with the forwarded fields, and nothing
	 * from the body decides the learner but learnerRef.
	 *
	 * @return void
	 */
	public function testEachStepDelegatesWithTheForwardedFields(): void {
		$params = [
			'learnerRef' => 'lp-1',
			'taskId' => 'exam-1',
			'accessCode' => 'ROOM-12',
			'attemptId' => 'att-1',
			'itemId' => 'item-1',
			'response' => ['K1' => 'T2'],
			'learnerId' => 'someone-else',
		];
		foreach (self::STEPS as $step) {
			self::assertSame(['ok' => true], $this->controller(claims: $this->student(), params: $params)->$step()->getData());
		}

		self::assertSame(
			[
				['method' => 'available', 'learner' => 'lp-1'],
				['method' => 'start', 'taskId' => 'exam-1', 'accessCode' => 'ROOM-12'],
				['method' => 'answer', 'attemptId' => 'att-1', 'itemId' => 'item-1', 'response' => ['K1' => 'T2']],
				['method' => 'submit', 'attemptId' => 'att-1'],
				['method' => 'result', 'attemptId' => 'att-1'],
			],
			$this->calls
		);
	}//end testEachStepDelegatesWithTheForwardedFields()

	/**
	 * A refusal carries its status and the message in the pupil's language.
	 *
	 * @return void
	 */
	public function testARefusalCarriesThePupilsMessage(): void {
		$outcome = new PortalOutcome(status: 409, body: ['error' => 'attempt_closed'], reason: 'attempt-closed');

		$response = $this->controller(claims: $this->student(), params: ['learnerRef' => 'lp-1', 'attemptId' => 'a'], outcome: $outcome)->submit();

		self::assertSame(409, $response->getStatus());
		self::assertSame(['error' => 'attempt_closed', 'message' => 'msg:attempt-closed:pupil-1'], $response->getData());
	}//end testARefusalCarriesThePupilsMessage()

	/**
	 * A failure below answers 502 without leaking what failed.
	 *
	 * @return void
	 */
	public function testAFailureIsABadGatewayWithoutInternals(): void {
		$response = $this->controller(claims: $this->student(), params: ['learnerRef' => 'lp-1'], stepThrows: true)->available();

		self::assertSame(502, $response->getStatus());
		self::assertSame(['error' => 'downstream_error'], $response->getData());
	}//end testAFailureIsABadGatewayWithoutInternals()
}//end class
