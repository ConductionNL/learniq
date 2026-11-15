<?php

/**
 * Learniq cohort name restamp job unit tests.
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

use OCA\Learniq\BackgroundJob\CohortNameRestampJob;
use OCA\Learniq\Service\CohortNameRestamp;
use OCA\Learniq\BackgroundJob\ActorForwarding;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Psr\Log\NullLogger;

/**
 * The job restamps each renamed cohort once, with its latest name.
 *
 * @covers \OCA\Learniq\BackgroundJob\CohortNameRestampJob
 * @uses   \OCA\Learniq\BackgroundJob\ForwardedJob
 * @uses   \OCA\Learniq\BackgroundJob\ActorForwarding
 */
class CohortNameRestampJobTest extends TestCase {

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
	 * Valid entries restamp once per cohort with the last name seen; invalid ones are skipped.
	 *
	 * @return void
	 */
	public function testRestampsEachCohortOnceWithItsLatestName(): void {
		$calls = [];
		$restamp = $this->createMock(CohortNameRestamp::class);
		$restamp->expects(self::exactly(2))->method('restamp')->willReturnCallback(
			static function (string $cohortId, string $name) use (&$calls): void {
				$calls[] = $cohortId . '=' . $name;
			}
		);

		$this->runDeferred(
			job: new CohortNameRestampJob($this->forwarding(), new NullLogger(), $restamp),
			entries: [
				['cohortId' => 'coh-1', 'name' => 'MV2A'],
				['cohortId' => 'coh-2', 'name' => 'HV3B'],
				['cohortId' => 'coh-1', 'name' => 'MV2A-nieuw'],
				['cohortId' => '', 'name' => 'leeg'],
				['cohortId' => 'coh-3'],
				['cohortId' => 7, 'name' => 'getal'],
				['name' => 'zonder cohort'],
			]
		);

		self::assertSame(['coh-1=MV2A-nieuw', 'coh-2=HV3B'], $calls);
	}//end testRestampsEachCohortOnceWithItsLatestName()

	/**
	 * An empty name is still a name and is restamped.
	 *
	 * @return void
	 */
	public function testAnEmptyNameIsRestamped(): void {
		$restamp = $this->createMock(CohortNameRestamp::class);
		$restamp->expects(self::once())->method('restamp')->with('coh-1', '');

		$this->runDeferred(job: new CohortNameRestampJob($this->forwarding(), new NullLogger(), $restamp), entries: [['cohortId' => 'coh-1', 'name' => '']]);
	}//end testAnEmptyNameIsRestamped()

	/**
	 * No entries, no restamp.
	 *
	 * @return void
	 */
	public function testNoEntriesRestampNothing(): void {
		$restamp = $this->createMock(CohortNameRestamp::class);
		$restamp->expects(self::never())->method('restamp');

		$this->runDeferred(job: new CohortNameRestampJob($this->forwarding(), new NullLogger(), $restamp), entries: []);
	}//end testNoEntriesRestampNothing()
}//end class
