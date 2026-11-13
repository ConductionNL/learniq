<?php

/**
 * Unit tests for AssessmentScoringService::autoScore(): admin only, a missing
 * result is refused, and the result is saved scored or as it was.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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

namespace OCA\Learniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Learniq\Lifecycle\AssessmentScoringHandler;
use OCA\Learniq\Service\AssessmentScoringService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\AssessmentScoringService
 * @uses   \OCA\Learniq\Lifecycle\AssessmentScoringHandler
 * @uses   \OCA\Learniq\Lifecycle\TenantScopedQuery
 */
class AssessmentScoringServiceTest extends TestCase {

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private AssessmentScoringService $service;

	/**
	 * Build the service with a real scoring handler over the same mocked store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new AssessmentScoringService(
			$this->objectService,
			new AssessmentScoringHandler($this->objectService, $this->createMock(LoggerInterface::class)),
			$this->logger,
			$this->groupManager,
			$this->userSession
		);
	}//end setUp()

	/**
	 * Sign in a user, admin or not.
	 *
	 * @param bool $admin Whether the user is in the admin group.
	 *
	 * @return void
	 */
	private function signIn(bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('docent');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')->with('docent', 'admin')->willReturn($admin);
	}//end signIn()

	/**
	 * Without a user, or as a non-admin, nothing is read or saved.
	 *
	 * @return void
	 */
	public function testOnlyAnAdminMayAutoScore(): void {
		$this->objectService->expects(self::never())->method('findAll');
		$this->objectService->expects(self::never())->method('saveObject');

		try {
			$this->service->autoScore('r-1');
			self::fail('no user must be refused');
		} catch (RuntimeException $exception) {
			self::assertSame('autoScore may only be called by an admin user.', $exception->getMessage());
		}

		$this->signIn(admin: false);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('autoScore may only be called by an admin user.');
		$this->service->autoScore('r-1');
	}//end testOnlyAnAdminMayAutoScore()

	/**
	 * A result that is not found is refused by id.
	 *
	 * @return void
	 */
	public function testAMissingResultIsRefused(): void {
		$this->signIn(admin: true);
		$this->objectService->expects(self::once())->method('findAll')->with(
			['ids' => ['r-404'], 'filters' => ['register' => 'learniq', 'schema' => 'assessment-result'], 'limit' => 1]
		)->willReturn([]);
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("AssessmentResult with UUID 'r-404' not found.");
		$this->service->autoScore('r-404');
	}//end testAMissingResultIsRefused()

	/**
	 * A result with nothing to score is saved as it is, and the run is logged.
	 *
	 * @return void
	 */
	public function testAResultWithoutResponsesIsSavedAsItIs(): void {
		$this->signIn(admin: true);
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 'r-1', 'assessmentId' => 'a-1', 'responses' => []]);
		$this->objectService->method('findAll')->willReturn([$entity]);
		$this->objectService->expects(self::once())->method('saveObject')->with(
			['id' => 'r-1', 'assessmentId' => 'a-1', 'responses' => []],
			self::anything(),
			'learniq',
			'assessment-result'
		);
		$this->logger->expects(self::once())->method('info')->with(self::stringContains('Auto-scoring complete'), ['id' => 'r-1']);

		$this->service->autoScore('r-1');
	}//end testAResultWithoutResponsesIsSavedAsItIs()

	/**
	 * When the parent assessment cannot be found the result is saved unchanged.
	 *
	 * @return void
	 */
	public function testAnUnresolvableAssessmentSavesTheResultUnchanged(): void {
		$this->signIn(admin: true);
		$result = ['id' => 'r-2', 'assessmentId' => 'a-gone', 'tenant_id' => 't-1', 'responses' => [['itemId' => 'i-1', 'value' => 'b']]];
		$this->objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => ($config['filters']['schema'] ?? '') === 'assessment-result' ? [$result] : []
		);
		$this->objectService->expects(self::once())->method('saveObject')->with($result, self::anything(), 'learniq', 'assessment-result');

		$this->service->autoScore('r-2');
	}//end testAnUnresolvableAssessmentSavesTheResultUnchanged()
}//end class
