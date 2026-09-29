<?php

/**
 * Learniq CredentialReissueJob unit tests.
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
 *
 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\CredentialReissueJob;
use OCA\Learniq\Service\CredentialReissueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The job runs the reissue as the account that started it, and refuses an
 * argument without a course, run or account.
 */
class CredentialReissueJobTest extends TestCase {

	/**
	 * Run the job's protected run() with an argument.
	 *
	 * @param mixed                    $argument The job argument.
	 * @param CredentialReissueService $reissues The service double.
	 *
	 * @return void
	 */
	private function runJob(mixed $argument, CredentialReissueService $reissues): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr.jansen');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'hr.jansen' ? $user : null);

		$job = new CredentialReissueJob(time: $this->createMock(ITimeFactory::class), reissues: $reissues, users: $users, logger: new NullLogger());
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * A complete argument runs the reissue as the staff member.
	 *
	 * @return void
	 */
	public function testRunsTheReissueAsTheStaffMember(): void {
		$reissues = $this->createMock(CredentialReissueService::class);
		$reissues->expects(self::once())->method('run')->with(
			'c-1',
			'run-1',
			'Nieuwe tekst',
			self::callback(static fn (IUser $user): bool => $user->getUID() === 'hr.jansen')
		)->willReturn(['processed' => 0, 'skipped' => 0, 'failed' => 0]);

		$this->runJob(argument: ['courseId' => 'c-1', 'runId' => 'run-1', 'reason' => 'Nieuwe tekst', 'by' => 'hr.jansen'], reissues: $reissues);
	}//end testRunsTheReissueAsTheStaffMember()

	/**
	 * An unknown account or a missing course never runs.
	 *
	 * @return void
	 */
	public function testAnIncompleteArgumentNeverRuns(): void {
		$reissues = $this->createMock(CredentialReissueService::class);
		$reissues->expects(self::never())->method('run');

		$this->runJob(argument: ['courseId' => 'c-1', 'runId' => 'run-1', 'by' => 'nobody'], reissues: $reissues);
		$this->runJob(argument: ['runId' => 'run-1', 'by' => 'hr.jansen'], reissues: $reissues);
		$this->runJob(argument: 'garbage', reissues: $reissues);
	}//end testAnIncompleteArgumentNeverRuns()
}//end class
