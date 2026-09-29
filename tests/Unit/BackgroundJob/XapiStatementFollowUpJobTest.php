<?php

/**
 * Tests for XapiStatementFollowUpJob.
 *
 * @category Test
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
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\XapiStatementFollowUpJob;
use OCA\Learniq\Service\LessonProgress;
use OCA\Learniq\Service\XapiEnrolmentCompletion;
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
 * The job over mocked work.
 */
class XapiStatementFollowUpJobTest extends TestCase {

	/**
	 * Each entry reaches its work; a malformed one is skipped and a failing one does not stop the next.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
	 */
	public function testRunsEachEntryAndSurvivesAFailure(): void {
		$runs = [];
		$progress = $this->createMock(LessonProgress::class);
		$progress->method('record')->willReturnCallback(
			static function (array $statement, string $completedAt) use (&$runs): void {
				$runs[] = 'progress:' . $statement['id'] . '@' . $completedAt;
				if ($statement['id'] === 'boom') {
					throw new RuntimeException('failed');
				}
			}
		);
		$completion = $this->createMock(XapiEnrolmentCompletion::class);
		$completion->method('complete')->willReturnCallback(
			static function (array $statement) use (&$runs): void {
				$runs[] = 'completion:' . $statement['id'];
			}
		);

		$job = new XapiStatementFollowUpJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			new NullLogger(),
			$progress,
			$completion
		);

		$method = new ReflectionMethod($job, 'runDeferred');
		$method->setAccessible(true);
		$method->invoke(
			$job,
			new DeferredListenerContext(
				userId: 'learner-1',
				orgUuid: null,
				entries: [
					['kind' => XapiStatementFollowUpJob::LESSON_PROGRESS, 'statement' => ['id' => 'boom'], 'completedAt' => 't1'],
					['kind' => 'unknown', 'statement' => ['id' => 'skipped']],
					['kind' => XapiStatementFollowUpJob::ENROLMENT_COMPLETION, 'statement' => 'not an array'],
					['kind' => XapiStatementFollowUpJob::LESSON_PROGRESS, 'statement' => ['id' => 's-2'], 'completedAt' => 't2'],
					['kind' => XapiStatementFollowUpJob::ENROLMENT_COMPLETION, 'statement' => ['id' => 's-2']],
				]
			)
		);

		$this->assertSame(['progress:boom@t1', 'progress:s-2@t2', 'completion:s-2'], $runs);
	}//end testRunsEachEntryAndSurvivesAFailure()
}//end class
