<?php

/**
 * Unit tests for CredentialMigrationController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CredentialMigrationController;
use OCA\Learniq\Kernel\Contracts\CallerTenantResolverInterface;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CredentialMigrationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Authentication, the `credential.migrate` gate, the row limits, the dry run
 * and the server-side organisation.
 *
 * @covers \OCA\Learniq\Controller\CredentialMigrationController
 */
final class CredentialMigrationControllerTest extends TestCase {

	private const ROW = ['learner' => 'p-100', 'course' => 'VCA', 'legacyNumber' => 'OLD-1', 'issuedAt' => '2024-03-01', 'tenant_id' => 'other-tenant'];

	/**
	 * The migration service.
	 *
	 * @var CredentialMigrationService&MockObject
	 */
	private CredentialMigrationService $migration;

	/**
	 * The action gate.
	 *
	 * @var ActionAuthService&MockObject
	 */
	private ActionAuthService $actionAuth;

	protected function setUp(): void {
		$this->migration = $this->createMock(CredentialMigrationService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
	}

	public function testASignedOutCallerGets401AndNothingRuns(): void {
		$this->migration->expects($this->never())->method('migrate');
		$this->actionAuth->expects($this->never())->method('requireAction');

		$response = $this->controller(signedIn: false)->migrate(rows: [self::ROW]);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testALearnerOutsideTheActionIsRefusedBeforeAnyRowIsRead(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($this->isInstanceOf(IUser::class), 'credential.migrate')
			->willThrowException(new OCSForbiddenException('Not allowed.'));
		$this->migration->expects($this->never())->method('migrate');

		$this->expectException(OCSForbiddenException::class);
		$this->controller()->migrate(rows: [self::ROW], dryRun: false);
	}

	public function testAnEmptyFileIsRefused(): void {
		$this->migration->expects($this->never())->method('migrate');

		$response = $this->controller()->migrate(rows: []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'The file has no rows.'], $response->getData());
	}

	public function testAFileOverTheLimitIsRefused(): void {
		$this->migration->expects($this->never())->method('migrate');

		$response = $this->controller()->migrate(rows: array_fill(0, CredentialMigrationController::MAX_ROWS + 1, self::ROW));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A file can hold at most 5000 rows. Split it and upload the parts.'], $response->getData());
	}

	public function testTheDefaultIsADryRunInTheCallersOrganisation(): void {
		$report = ['commit' => false, 'runId' => null, 'summary' => ['ready' => 1], 'rows' => [['row' => 1, 'status' => 'ready']]];
		$this->migration->expects($this->once())->method('migrate')
			->with([self::ROW], 'tenant-a', 'officer-a', false)
			->willReturn($report);

		$response = $this->controller()->migrate(rows: [self::ROW]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($report, $response->getData());
	}

	public function testAConfirmCommits(): void {
		$this->migration->expects($this->once())->method('migrate')
			->with([self::ROW], 'tenant-a', 'officer-a', true)
			->willReturn(['commit' => true, 'runId' => 'run', 'summary' => [], 'rows' => []]);

		$response = $this->controller()->migrate(rows: [self::ROW], dryRun: false);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['commit']);
	}

	/**
	 * The controller for a signed-in compliance officer of tenant-a.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return CredentialMigrationController
	 */
	private function controller(bool $signedIn = true): CredentialMigrationController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-a');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$tenant = $this->createMock(CallerTenantResolverInterface::class);
		$tenant->method('resolve')->willReturnCallback(static fn (IUser $caller): string => ($caller->getUID() === 'officer-a' ? 'tenant-a' : ''));

		return new CredentialMigrationController($this->createMock(IRequest::class), $session, $this->actionAuth, $tenant, $this->migration);
	}
}
