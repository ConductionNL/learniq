<?php

/**
 * Tests for PortalHourDayController.
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
 * @spec openspec/changes/bpv-hours-per-day/specs/portal-contribution/spec.md#requirement-a-student-writes-hours-per-day-and-keeps-a-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalHourDayController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalHourDayWrite;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalMessages;
use OCA\Learniq\Service\Portal\PortalOutcome;
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
 * The day-of-hours receiver: the pupil receivers' order and answers, and only the whitelisted fields.
 */
class PortalHourDayControllerTest extends TestCase {

	/**
	 * Calls that reached the day writer.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed>|null $claims      What the verifier returns.
	 * @param array<string, mixed>      $params      The forwarded body.
	 * @param bool                      $pupilExists Whether learnerRef resolves.
	 * @param PortalOutcome|null        $outcome     What the writer returns.
	 * @param bool                      $throws      Whether the writer throws.
	 *
	 * @return PortalHourDayController
	 */
	private function controller(?array $claims, array $params, bool $pupilExists = true, ?PortalOutcome $outcome = null, bool $throws = false): PortalHourDayController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn($claims);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('milan');
		$learners = $this->createMock(PortalLearnerResolver::class);
		$learners->method('resolve')->willReturnCallback(
			static fn (string $learnerRef): ?PortalLearner => ($pupilExists === true)
				? new PortalLearner(profileRef: 'profile-of-' . $learnerRef, ncUserId: 'milan', tenantId: '', user: $user)
				: null
		);

		$outcome = ($outcome ?? new PortalOutcome(status: 200, body: ['hourWeekId' => 'w-1', 'lifecycle' => 'draft']));
		$days = $this->createMock(PortalHourDayWrite::class);
		$days->method('write')->willReturnCallback(
			function (string $learnerRef, array $body) use ($outcome, $throws): PortalOutcome {
				$this->calls[] = ['learnerRef' => $learnerRef, 'body' => $body];
				if ($throws === true) {
					throw new RuntimeException('SQLSTATE secret table name');
				}

				return $outcome;
			}
		);

		$messages = $this->createMock(PortalMessages::class);
		$messages->method('message')->willReturnCallback(static fn (string $reason, ?IUser $u): string => 'msg:' . $reason . ':' . ($u?->getUID() ?? 'none'));

		return new PortalHourDayController(
			request: $request,
			verifier: $verifier,
			learners: $learners,
			days: $days,
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
	public function testTheReceiverIsPublicCsrfFreeAndRateLimited(): void {
		$method = new ReflectionMethod(PortalHourDayController::class, 'write');

		self::assertNotEmpty($method->getAttributes(PublicPage::class));
		self::assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
		self::assertNotEmpty($method->getAttributes(AnonRateLimit::class));
	}//end testTheReceiverIsPublicCsrfFreeAndRateLimited()

	/**
	 * No valid assertion is 401 and throttled; another audience, no learnerRef
	 * or no account is 403; none of them reaches the writer.
	 *
	 * @return void
	 */
	public function testUnverifiedOrForeignCallersNeverReachTheWriter(): void {
		$none = $this->controller(claims: null, params: ['learnerRef' => 'lp-1'])->write();
		$trainer = $this->controller(claims: ['audience' => 'praktijkopleider'] + $this->student(), params: ['learnerRef' => 'lp-1'])->write();
		$noLearner = $this->controller(claims: $this->student(), params: [])->write();
		$noAccount = $this->controller(claims: $this->student(), params: ['learnerRef' => 'lp-9'], pupilExists: false)->write();

		self::assertSame(401, $none->getStatus());
		self::assertTrue($none->isThrottled());
		self::assertSame(403, $trainer->getStatus());
		self::assertSame(['error' => 'forbidden'], $trainer->getData());
		self::assertSame(403, $noLearner->getStatus());
		self::assertSame(['error' => 'not_available', 'message' => 'msg:no-account:none'], $noAccount->getData());
		self::assertSame([], $this->calls);
	}//end testUnverifiedOrForeignCallersNeverReachTheWriter()

	/**
	 * The writer gets the resolved profile and only the fields the action
	 * declares; a refusal carries the student's message, a failure is a bare 502.
	 *
	 * @return void
	 */
	public function testTheDayIsDelegatedWithTheWhitelistedFields(): void {
		$params = [
			'learnerRef' => 'lp-1',
			'bpvPlacementId' => 'pl-1',
			'date' => '2026-10-05',
			'startTime' => '08:00',
			'endTime' => '16:30',
			'breakMinutes' => '30',
			'send' => 'keep',
			'lifecycle' => 'approved',
			'hoursSubmitted' => 40,
			'photoRef' => 'someone-elses-file',
		];

		$ok = $this->controller(claims: $this->student(), params: $params)->write();
		self::assertSame(200, $ok->getStatus());
		self::assertSame(['hourWeekId' => 'w-1', 'lifecycle' => 'draft'], $ok->getData());
		self::assertSame(
			[
				[
					'learnerRef' => 'profile-of-lp-1',
					'body' => [
						'bpvPlacementId' => 'pl-1',
						'date' => '2026-10-05',
						'startTime' => '08:00',
						'endTime' => '16:30',
						'breakMinutes' => '30',
						'send' => 'keep',
					],
				],
			],
			$this->calls
		);

		$locked = new PortalOutcome(status: 409, body: ['error' => 'week_with_trainer'], reason: 'hour-week-with-trainer');
		$refused = $this->controller(claims: $this->student(), params: $params, outcome: $locked)->write();
		self::assertSame(409, $refused->getStatus());
		self::assertSame(['error' => 'week_with_trainer', 'message' => 'msg:hour-week-with-trainer:milan'], $refused->getData());

		$broken = $this->controller(claims: $this->student(), params: $params, throws: true)->write();
		self::assertSame(502, $broken->getStatus());
		self::assertSame(['error' => 'downstream_error'], $broken->getData());
	}//end testTheDayIsDelegatedWithTheWhitelistedFields()
}//end class
