<?php

/**
 * Learniq AssessmentAttemptGateListener unit tests.
 *
 * Covers the server-side attempt gate on AssessmentResult creation: an
 * attempt before availableFrom or after availableUntil is refused, an attempt
 * inside the window is allowed, an assessment with an access code refuses a
 * missing or wrong code and accepts the right one (and the typed code is
 * cleared before insert), admins and system context bypass the gate, other
 * schemas are ignored, and an assessment that cannot be read fails closed.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use OCA\Learniq\Listener\AssessmentAttemptGateListener;
use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Service\AssessmentAttemptLimits;
use OCA\Learniq\Service\Portal\PortalAttemptClock;
use OCA\Learniq\Service\AssessmentResultAudience;
use OCA\Learniq\Service\AssessmentResultPortalStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for AssessmentAttemptGateListener::handle().
 */
class AssessmentAttemptGateListenerTest extends TestCase {

	private const NOW = '2026-09-27T10:00:00+00:00';

	/**
	 * Assessment rows keyed by id, as the raw (unrendered) OR read returns them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $assessments = [];

	/**
	 * Existing AssessmentResult rows, as the attempt count reads them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $attempts = [];

	/**
	 * Arguments of every ObjectService::find() call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $findCalls = [];

	/**
	 * How many times the audience stamp ran.
	 *
	 * @var int
	 */
	private int $stamps = 0;

	/**
	 * How many times the portal stamp ran.
	 *
	 * @var int
	 */
	private int $portalStamps = 0;

	/**
	 * Build the listener.
	 *
	 * @param string $schemaSlug Slug the resolver returns for the created entity.
	 * @param bool $isAdmin Whether the caller is a Nextcloud admin.
	 * @param bool $hasUser Whether a user session exists.
	 * @param bool $findThrows Whether the assessment lookup throws.
	 *
	 * @return AssessmentAttemptGateListener
	 */
	private function makeListener(
		string $schemaSlug = 'assessment-result',
		bool $isAdmin = false,
		bool $hasUser = true,
		bool $findThrows = false,
	): AssessmentAttemptGateListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schemaSlug);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true) use ($findThrows) {
				$this->findCalls[] = ['id' => $id, 'schema' => $schema, 'rbac' => $_rbac, 'render' => $_render];
				if ($findThrows === true) {
					throw new RuntimeException('database gone');
				}

				$row = ($this->assessments[$id] ?? null);
				if ($row === null) {
					return null;
				}

				return OrEntityFactory::make($row, 'exam');
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? '') !== 'assessment-result') {
					return [];
				}

				return array_values(
					array_filter(
						$this->attempts,
						static fn (array $row): bool => $row['assessmentId'] === ($filters['assessmentId'] ?? null)
							&& $row['learnerId'] === ($filters['learnerId'] ?? null)
					)
				);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('learner1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($hasUser === true ? $user : null);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime(self::NOW));

		$audience = $this->createMock(AssessmentResultAudience::class);
		$audience->method('stamp')->willReturnCallback(
			function (): void {
				$this->stamps++;
			}
		);

		$portalStamp = $this->createMock(AssessmentResultPortalStamp::class);
		$portalStamp->method('stamp')->willReturnCallback(
			function (): void {
				$this->portalStamps++;
			}
		);

		return new AssessmentAttemptGateListener(
			objectService: $objectService,
			schemaResolver: $resolver,
			userSession: $session,
			groupManager: $groups,
			audience: $audience,
			portalStamp: $portalStamp,
			logger: new NullLogger(),
			limits: new AssessmentAttemptLimits(
				attempts: new PortalAttemptReader(objectService: $objectService),
				clock: new PortalAttemptClock(),
				policy: new AssessmentAccessPolicy(),
				timeFactory: $time,
			),
		);
	}//end makeListener()

	/**
	 * Build a creating event for an AssessmentResult.
	 *
	 * @param array<string, mixed> $extra Extra payload fields.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function makeEvent(array $extra = []): ObjectCreatingEvent {
		$payload = array_merge(
			[
				'assessmentId' => 'a1',
				'learnerId' => 'learner1',
				'lifecycle' => 'in-progress',
				'tenant_id' => 't1',
			],
			$extra
		);

		return new ObjectCreatingEvent(OrEntityFactory::make($payload, 'assessment-result'));
	}//end makeEvent()

	/**
	 * An attempt before availableFrom is refused.
	 *
	 * @return void
	 */
	public function testAttemptBeforeWindowOpensIsRefused(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'availableFrom' => '2026-09-28T09:00:00+00:00', 'availableUntil' => null];
		$event = $this->makeEvent();

		$this->makeListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('not open yet', (string)$event->getErrors()['message']);
		$this->assertSame(0, $this->stamps, 'a refused attempt is not stamped');
		$this->assertSame(0, $this->portalStamps, 'a refused attempt gets no portal stamp');
	}//end testAttemptBeforeWindowOpensIsRefused()

	/**
	 * An attempt after availableUntil is refused, even when a stale
	 * materialised isAvailable still says true.
	 *
	 * @return void
	 */
	public function testAttemptAfterWindowClosedIsRefusedEvenWithStaleIsAvailable(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'availableFrom' => null, 'availableUntil' => '2026-09-26T17:00:00+00:00', 'isAvailable' => true];
		$event = $this->makeEvent();

		$this->makeListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('closed', (string)$event->getErrors()['message']);
	}//end testAttemptAfterWindowClosedIsRefusedEvenWithStaleIsAvailable()

	/**
	 * An attempt inside the window with no access code is allowed, and the
	 * assessment is read raw (without render or RBAC) so a write-only access
	 * code is visible to the gate.
	 *
	 * @return void
	 */
	public function testAttemptInsideWindowIsAllowed(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'availableFrom' => '2026-09-27T08:00:00+00:00', 'availableUntil' => '2026-09-27T12:00:00+00:00'];
		$event = $this->makeEvent();

		$this->makeListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(['id' => 'a1', 'schema' => 'exam', 'rbac' => false, 'render' => false], $this->findCalls[0]);
		$this->assertSame(1, $this->stamps, 'an allowed attempt gets its read audience stamped');
		$this->assertSame(1, $this->portalStamps, 'an allowed attempt gets its portal scope and title stamped');
	}//end testAttemptInsideWindowIsAllowed()

	/**
	 * An assessment with an access code refuses an attempt without one.
	 *
	 * @return void
	 */
	public function testMissingAccessCodeIsRefused(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'accessCode' => 'room-12'];
		$event = $this->makeEvent();

		$this->makeListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('access-code-required', $event->getErrors()['reason']);
	}//end testMissingAccessCodeIsRefused()

	/**
	 * A wrong access code is refused.
	 *
	 * @return void
	 */
	public function testWrongAccessCodeIsRefused(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'accessCode' => 'room-12'];
		$event = $this->makeEvent(['accessCode' => 'room-13']);

		$this->makeListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('access-code-invalid', $event->getErrors()['reason']);
	}//end testWrongAccessCodeIsRefused()

	/**
	 * The right access code is accepted and cleared before the result is stored.
	 *
	 * @return void
	 */
	public function testRightAccessCodeIsAcceptedAndCleared(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'accessCode' => 'room-12'];
		$event = $this->makeEvent(['accessCode' => ' room-12 ']);

		$this->makeListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertArrayHasKey('accessCode', $event->getModifiedData());
		$this->assertNull($event->getModifiedData()['accessCode']);
	}//end testRightAccessCodeIsAcceptedAndCleared()

	/**
	 * A second attempt on a one-attempt test is refused on the server; the
	 * screen's own check used to be the only one. Red before the fix.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-a-second-attempt-on-a-one-attempt-test-is-refused
	 */
	public function testASecondAttemptOnAOneAttemptTestIsRefused(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'maxAttempts' => 1];
		$this->attempts = [['assessmentId' => 'a1', 'learnerId' => 'learner1', 'lifecycle' => 'submitted']];
		$event = $this->makeEvent();

		$this->makeListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('attempts-used', $event->getErrors()['reason']);
		$this->assertSame(0, $this->stamps, 'a refused attempt is not stamped');
	}//end testASecondAttemptOnAOneAttemptTestIsRefused()

	/**
	 * Another learner's attempts, or attempts on another test, do not count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-a-second-attempt-on-a-one-attempt-test-is-refused
	 */
	public function testOnlyTheLearnersOwnAttemptsOnThisTestCount(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'maxAttempts' => 1];
		$this->attempts = [
			['assessmentId' => 'a1', 'learnerId' => 'learner2', 'lifecycle' => 'submitted'],
			['assessmentId' => 'a2', 'learnerId' => 'learner1', 'lifecycle' => 'submitted'],
		];
		$event = $this->makeEvent();

		$this->makeListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testOnlyTheLearnersOwnAttemptsOnThisTestCount()

	/**
	 * The server sets when the attempt started and its number: the screen used
	 * to send its own clock and always attempt 1, so a learner could start the
	 * clock in the future. Red before the fix.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-the-server-starts-the-clock
	 */
	public function testTheServerStartsTheClockAndNumbersTheAttempt(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'maxAttempts' => 3, 'timeLimitMinutes' => 30];
		$this->attempts = [['assessmentId' => 'a1', 'learnerId' => 'learner1', 'lifecycle' => 'graded']];
		$event = $this->makeEvent(['startedAt' => '2030-01-01T00:00:00+00:00', 'attemptNumber' => 1]);

		$this->makeListener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame('2026-09-27T10:00:00+00:00', $event->getModifiedData()['startedAt']);
		$this->assertSame(2, $event->getModifiedData()['attemptNumber']);
	}//end testTheServerStartsTheClockAndNumbersTheAttempt()

	/**
	 * An admin creating a result (seeding, a correction) is not gated.
	 *
	 * @return void
	 */
	public function testAdminBypassesTheGate(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'availableUntil' => '2026-01-01T00:00:00+00:00', 'accessCode' => 'x'];
		$event = $this->makeEvent();

		$this->makeListener(isAdmin: true)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(1, $this->stamps, 'an admin-created result is stamped too');
	}//end testAdminBypassesTheGate()

	/**
	 * A create without a user session (occ, a background job) is not gated.
	 *
	 * @return void
	 */
	public function testSystemContextBypassesTheGate(): void {
		$this->assessments['a1'] = ['title' => 'Exam', 'availableUntil' => '2026-01-01T00:00:00+00:00'];
		$event = $this->makeEvent();

		$this->makeListener(hasUser: false)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testSystemContextBypassesTheGate()

	/**
	 * Other schemas are ignored without a lookup.
	 *
	 * @return void
	 */
	public function testOtherSchemaIsIgnored(): void {
		$event = $this->makeEvent();

		$this->makeListener(schemaSlug: 'enrolment')->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $this->findCalls);
		$this->assertSame(0, $this->stamps);
	}//end testOtherSchemaIsIgnored()

	/**
	 * An assessment that cannot be read fails closed: an exam must not open
	 * because the availability check could not run.
	 *
	 * @return void
	 */
	public function testUnreadableAssessmentFailsClosed(): void {
		$event = $this->makeEvent();

		$this->makeListener(findThrows: true)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testUnreadableAssessmentFailsClosed()

	/**
	 * An unknown assessment id fails closed.
	 *
	 * @return void
	 */
	public function testUnknownAssessmentFailsClosed(): void {
		$event = $this->makeEvent(['assessmentId' => 'missing']);

		$this->makeListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testUnknownAssessmentFailsClosed()
}//end class
