<?php

/**
 * Learniq learner group label restamp job unit tests.
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

use OCA\Learniq\BackgroundJob\LearnerGroupLabelRestampJob;
use OCA\Learniq\Service\LearnerGroupLabelRestamp;
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
 * The job restamps each learner's group label once.
 *
 * @covers \OCA\Learniq\BackgroundJob\LearnerGroupLabelRestampJob
 * @uses   \OCA\Learniq\BackgroundJob\ForwardedJob
 * @uses   \OCA\Learniq\BackgroundJob\ActorForwarding
 */
class LearnerGroupLabelRestampJobTest extends TestCase {

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
	 * Each distinct learner ref is restamped once, in first-seen order; empty and non-string refs are skipped.
	 *
	 * @return void
	 */
	public function testRestampsEachLearnerOnce(): void {
		$calls = [];
		$restamp = $this->createMock(LearnerGroupLabelRestamp::class);
		$restamp->expects(self::exactly(2))->method('restamp')->willReturnCallback(
			static function (string $learnerRef) use (&$calls): bool {
				$calls[] = $learnerRef;
				return true;
			}
		);

		$this->runDeferred(
			job: new LearnerGroupLabelRestampJob($this->forwarding(), new NullLogger(), $restamp),
			entries: [
				['learnerRef' => 'lp-2'],
				['learnerRef' => 'lp-1'],
				['learnerRef' => 'lp-2'],
				['learnerRef' => ''],
				['learnerRef' => 42],
				['other' => 'x'],
			]
		);

		self::assertSame(['lp-2', 'lp-1'], $calls);
	}//end testRestampsEachLearnerOnce()

	/**
	 * Only unusable entries: nothing is restamped.
	 *
	 * @return void
	 */
	public function testUnusableEntriesRestampNothing(): void {
		$restamp = $this->createMock(LearnerGroupLabelRestamp::class);
		$restamp->expects(self::never())->method('restamp');

		$this->runDeferred(job: new LearnerGroupLabelRestampJob($this->forwarding(), new NullLogger(), $restamp), entries: [['learnerRef' => ''], []]);
	}//end testUnusableEntriesRestampNothing()
}//end class
