<?php

/**
 * Unit tests for CompetencyAttainmentRollupJob (grading-rollup-followups).
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\CompetencyAttainmentRollupJob;
use OCA\Learniq\Service\CompetencyAttainmentRollup;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The job runs each queued entry and survives a failing one.
 */
class CompetencyAttainmentRollupJobTest extends TestCase {

	/**
	 * Each well-formed entry reaches the roll-up; a malformed one is skipped
	 * and a failing one does not stop the next.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
	 */
	public function testRunsEachEntryAndSurvivesAFailure(): void {
		$runs = [];
		$rollup = $this->createMock(CompetencyAttainmentRollup::class);
		$rollup->method('run')->willReturnCallback(
			static function (string $kind, array $object) use (&$runs): void {
				$runs[] = $kind . ':' . $object['id'];
				if ($object['id'] === 'boom') {
					throw new RuntimeException('failed');
				}
			}
		);

		$job = new CompetencyAttainmentRollupJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			new NullLogger(),
			$rollup
		);

		$method = new ReflectionMethod($job, 'runDeferred');
		$method->setAccessible(true);
		$method->invoke(
			$job,
			new DeferredListenerContext(
				userId: 'teacher-1',
				orgUuid: null,
				entries: [
					['kind' => CompetencyAttainmentRollup::WERKPROCES_CREATED, 'object' => ['id' => 'boom']],
					['kind' => '', 'object' => ['id' => 'skipped']],
					['kind' => CompetencyAttainmentRollup::GRADE_ENTRY_PUBLISHED, 'object' => 'not an array'],
					['kind' => CompetencyAttainmentRollup::WERKPROCES_CONFIRMED, 'object' => ['id' => 'wpa-2']],
				]
			)
		);

		self::assertSame(['werkproces-created:boom', 'werkproces-confirmed:wpa-2'], $runs);
	}//end testRunsEachEntryAndSurvivesAFailure()
}//end class
