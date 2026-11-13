<?php

/**
 * Learniq employer booking restamp job unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\BackgroundJob
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\EmployerBookingRestampJob;
use OCA\Learniq\Service\Portal\EmployerBookingProjection;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\BackgroundJob\ActorForwarding;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

// The unit bootstrap does not load every support helper.
require_once __DIR__ . '/../../Support/CapturingLogger.php';

/**
 * The job re-derives every booking an entry touches: directly by booking ref,
 * or through a learner's enrolments.
 *
 * @covers \OCA\Learniq\BackgroundJob\EmployerBookingRestampJob
 * @uses   \OCA\Learniq\BackgroundJob\ForwardedJob
 * @uses   \OCA\Learniq\BackgroundJob\ActorForwarding
 */
class EmployerBookingRestampJobTest extends TestCase {

	/**
	 * The forwarding bundle over mocked Nextcloud services.
	 *
	 * @return ActorForwarding
	 */
	private function forwarding(): ActorForwarding {
		return new ActorForwarding(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			organisation: $this->createMock(OrganisationService::class)
		);
	}//end forwarding()

	/**
	 * Run the job's protected runDeferred() over entries.
	 *
	 * @param object                          $job     The job.
	 * @param list<array<string, mixed>|null> $entries The deferred entries.
	 *
	 * @return void
	 */
	private function runDeferred(object $job, array $entries): void {
		(new ReflectionMethod($job, 'runDeferred'))->invoke($job, new DeferredListenerContext(userId: 'teacher-1', orgUuid: null, entries: $entries));
	}//end runDeferred()

	/**
	 * Direct booking refs and the bookings of a learner's enrolments are each projected once.
	 *
	 * @return void
	 */
	public function testProjectsEveryTouchedBookingOnce(): void {
		$projected = [];
		$projection = $this->createMock(EmployerBookingProjection::class);
		$projection->expects(self::exactly(2))->method('many')->willReturnCallback(
			static fn (string $schema, array $filters): array => match ($filters['learnerRef']) {
				'lp-1' => [['bookingRef' => 'b-2'], ['bookingRef' => ''], ['bookingRef' => 'b-1'], []],
				default => [],
			}
		);
		$projection->method('project')->willReturnCallback(
			static function (string $bookingId) use (&$projected): ?array {
				$projected[] = $bookingId;
				return null;
			}
		);
		$logger = new CapturingLogger();

		$this->runDeferred(
			job: new EmployerBookingRestampJob($this->forwarding(), $logger, $projection),
			entries: [
				['bookingRef' => 'b-1', 'learnerRef' => 'ignored-when-booking-given'],
				['learnerRef' => 'lp-1'],
				['learnerRef' => 'lp-none'],
				['learnerRef' => ''],
				[],
			]
		);

		self::assertSame(['b-1', 'b-2'], $projected);
		self::assertSame([], $logger->records);
	}//end testProjectsEveryTouchedBookingOnce()

	/**
	 * The learner lookup asks for that learner's enrolments.
	 *
	 * @return void
	 */
	public function testLooksUpTheLearnersEnrolments(): void {
		$projection = $this->createMock(EmployerBookingProjection::class);
		$projection->expects(self::once())->method('many')->with('enrolment', ['learnerRef' => 'lp-9'])->willReturn([]);
		$projection->expects(self::never())->method('project');

		$this->runDeferred(job: new EmployerBookingRestampJob($this->forwarding(), new CapturingLogger(), $projection), entries: [['learnerRef' => 'lp-9']]);
	}//end testLooksUpTheLearnersEnrolments()

	/**
	 * A failing projection is logged as a warning and stops the run without throwing.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$projection = $this->createMock(EmployerBookingProjection::class);
		$projection->expects(self::once())->method('project')->with('b-1')->willThrowException(new RuntimeException('register down'));
		$logger = new CapturingLogger();

		$this->runDeferred(job: new EmployerBookingRestampJob($this->forwarding(), $logger, $projection), entries: [['bookingRef' => 'b-1'], ['bookingRef' => 'b-2']]);

		self::assertCount(1, $logger->records);
		self::assertSame('warning', $logger->records[0]['level']);
		self::assertSame(['msg' => 'register down'], $logger->records[0]['context']);
	}//end testAFailureIsLoggedNotThrown()
}//end class
