<?php

/**
 * Learniq CredentialReissueController unit tests.
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
 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\BackgroundJob\CredentialReissueJob;
use OCA\Learniq\Controller\CredentialReissueController;
use OCA\Learniq\Service\CredentialReissueService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Only HR and compliance officers preview and start; a reason is required;
 * the run is queued.
 */
class CredentialReissueControllerTest extends TestCase {

	/**
	 * Jobs queued.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * The controller for one caller.
	 *
	 * @param array<int, string>   $groups The caller's groups.
	 * @param array<string, mixed> $params The body.
	 *
	 * @return CredentialReissueController
	 */
	private function controller(array $groups, array $params = []): CredentialReissueController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr.jansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$reissues = $this->createMock(CredentialReissueService::class);
		$reissues->method('preview')->willReturn(['issued' => 12, 'revoked' => 1, 'expired' => 2]);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument = null): void {
			$this->queued[] = [$job, $argument];
		});
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('run-abc');

		return new CredentialReissueController(
			request: $request,
			userSession: $session,
			groupManager: $groupManager,
			reissues: $reissues,
			jobs: $jobs,
			random: $random,
			config: $this->createMock(IAppConfig::class)
		);
	}//end controller()

	/**
	 * An instructor is refused both routes and nothing is queued.
	 *
	 * @return void
	 */
	public function testAnInstructorIsRefused(): void {
		self::assertSame(403, $this->controller(groups: ['instructors'])->preview(courseId: 'c-1')->getStatus());
		self::assertSame(403, $this->controller(groups: ['instructors'], params: ['reason' => 'x'])->start(courseId: 'c-1')->getStatus());
		self::assertSame([], $this->queued);
	}//end testAnInstructorIsRefused()

	/**
	 * HR sees the counts, needs a reason, and queues one run.
	 *
	 * @return void
	 */
	public function testHrPreviewsAndQueuesARunWithAReason(): void {
		self::assertSame(12, $this->controller(groups: ['hr'])->preview(courseId: 'c-1')->getData()['issued']);
		self::assertSame(422, $this->controller(groups: ['hr'], params: ['reason' => ' '])->start(courseId: 'c-1')->getStatus());

		$response = $this->controller(groups: ['compliance-officers'], params: ['reason' => 'Nieuwe tekst'])->start(courseId: 'c-1');
		self::assertSame(202, $response->getStatus());
		self::assertSame([[CredentialReissueJob::class, ['courseId' => 'c-1', 'runId' => 'run-abc', 'reason' => 'Nieuwe tekst', 'by' => 'hr.jansen']]], $this->queued);
	}//end testHrPreviewsAndQueuesARunWithAReason()
}//end class
