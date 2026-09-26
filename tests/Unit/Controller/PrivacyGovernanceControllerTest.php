<?php

/**
 * Learniq PrivacyGovernanceController unit tests.
 *
 * Coverage for privacy-governance-surfaces: the overview endpoint composes
 * the eight rbac-declare-groups group ids' member counts, best-effort 2FA
 * adoption, and DataExchangeJob partner-approval counts — and degrades to
 * `null` (never a fabricated zero) on any read failure.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-a-board-facing-dashboard-composes-group-2fa-and-integration-approval-state
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Controller\PrivacyGovernanceController;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PrivacyGovernanceController::overview().
 */
class PrivacyGovernanceControllerTest extends TestCase {

	/**
	 * An unauthenticated caller is rejected before any composition happens.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallerIsRejected(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->never())->method('get');

		$controller = $this->buildController(userSession: $userSession, groupManager: $groupManager);
		$response = $controller->overview();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testUnauthenticatedCallerIsRejected()

	/**
	 * An unprovisioned group is reported with `provisioned: false` and a
	 * `null` member count — never a fabricated `0`.
	 *
	 * @return void
	 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-opens-the-privacy-governance-dashboard
	 */
	public function testUnprovisionedGroupReportsNullNotZero(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$controller = $this->buildController(groupManager: $groupManager, objectService: $objectService);
		$data = $controller->overview()->getData();

		foreach ($data['groups'] as $row) {
			self::assertFalse($row['provisioned']);
			self::assertNull($row['memberCount']);
		}

	}//end testUnprovisionedGroupReportsNullNotZero()

	/**
	 * A provisioned group reports its real member count.
	 *
	 * @return void
	 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-opens-the-privacy-governance-dashboard
	 */
	public function testProvisionedGroupReportsMemberCount(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn(3);
		$group->method('getUsers')->willReturn([]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn($group);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$controller = $this->buildController(groupManager: $groupManager, objectService: $objectService);
		$data = $controller->overview()->getData();

		$instructors = current(array_filter($data['groups'], static fn ($row) => $row['id'] === 'instructors'));
		self::assertTrue($instructors['provisioned']);
		self::assertSame(3, $instructors['memberCount']);

	}//end testProvisionedGroupReportsMemberCount()

	/**
	 * `IGroup::count()` is typed `int|bool` — when it returns `false` (Nextcloud
	 * could not resolve the count), the controller MUST still report `null`
	 * ("unknown"), never a fabricated `0` or the literal `false`.
	 *
	 * @return void
	 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-opens-the-privacy-governance-dashboard
	 */
	public function testProvisionedGroupWithUncountableMembersReportsNull(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn(false);
		$group->method('getUsers')->willReturn([]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn($group);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$controller = $this->buildController(groupManager: $groupManager, objectService: $objectService);
		$data = $controller->overview()->getData();

		$instructors = current(array_filter($data['groups'], static fn ($row) => $row['id'] === 'instructors'));
		self::assertTrue($instructors['provisioned']);
		self::assertNull($instructors['memberCount']);

	}//end testProvisionedGroupWithUncountableMembersReportsNull()

	/**
	 * Two-factor adoption is counted only across the governance groups'
	 * members, and only a user with at least one enabled provider counts.
	 *
	 * @return void
	 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-opens-the-privacy-governance-dashboard
	 */
	public function testTwoFactorAdoptionCountsOnlyEnabledUsers(): void {
		$userWith2fa = $this->createMock(IUser::class);
		$userWith2fa->method('getUID')->willReturn('alice');
		$userWithout2fa = $this->createMock(IUser::class);
		$userWithout2fa->method('getUID')->willReturn('bob');

		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn(2);
		$group->method('getUsers')->willReturn([$userWith2fa, $userWithout2fa]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn($group);

		$registry = $this->createMock(IRegistry::class);
		$registry->method('getProviderStates')->willReturnCallback(
			static function (IUser $user): array {
				if ($user->getUID() === 'alice') {
					return ['totp' => true];
				}

				return ['totp' => false];
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$controller = $this->buildController(groupManager: $groupManager, registry: $registry, objectService: $objectService);
		$data = $controller->overview()->getData();

		// 8 groups all resolving to the same 2 users (deduplicated by uid).
		self::assertSame(2, $data['twoFactor']['eligibleCount']);
		self::assertSame(1, $data['twoFactor']['enabledCount']);

	}//end testTwoFactorAdoptionCountsOnlyEnabledUsers()

	/**
	 * A 2FA registry failure degrades to unknown (`null`), never a fabricated
	 * zero.
	 *
	 * @return void
	 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#scenario-two-factor-adoption-degrades-to-unknown-rather-than-a-fabricated-zero
	 */
	public function testTwoFactorRegistryFailureDegradesToUnknown(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn(1);
		$group->method('getUsers')->willReturn([$user]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn($group);

		$registry = $this->createMock(IRegistry::class);
		$registry->method('getProviderStates')->willThrowException(new \RuntimeException(message: 'boom'));

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$controller = $this->buildController(groupManager: $groupManager, registry: $registry, objectService: $objectService);
		$data = $controller->overview()->getData();

		self::assertNull($data['twoFactor']['eligibleCount']);
		self::assertNull($data['twoFactor']['enabledCount']);

	}//end testTwoFactorRegistryFailureDegradesToUnknown()

	/**
	 * DataExchangeJob counts are grouped by partnerApprovalStatus, restricted
	 * to jobs with requiresPartnerApproval: true.
	 *
	 * @return void
	 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-opens-the-privacy-governance-dashboard
	 */
	public function testDataExchangeJobCountsGroupedByApprovalStatus(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[
				['requiresPartnerApproval' => true, 'partnerApprovalStatus' => 'pending'],
				['requiresPartnerApproval' => true, 'partnerApprovalStatus' => 'approved'],
				['requiresPartnerApproval' => true, 'partnerApprovalStatus' => 'approved'],
				['requiresPartnerApproval' => false, 'partnerApprovalStatus' => 'not-required'],
			]
		);

		$controller = $this->buildController(groupManager: $groupManager, objectService: $objectService);
		$data = $controller->overview()->getData();

		self::assertSame(1, $data['dataExchange']['pending']);
		self::assertSame(2, $data['dataExchange']['approved']);
		self::assertSame(0, $data['dataExchange']['rejected']);

	}//end testDataExchangeJobCountsGroupedByApprovalStatus()

	/**
	 * `ObjectService::findAll()` may hand back OpenRegister entity objects
	 * rather than plain arrays; the controller MUST normalise a
	 * `jsonSerialize()`-carrying object the same way it already handles a
	 * plain array, mirroring `AiProcessingDisclosureController::fetchHermiqFeatures()`'s
	 * own normalisation.
	 *
	 * @return void
	 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-opens-the-privacy-governance-dashboard
	 */
	public function testDataExchangeJobCountsNormaliseJsonSerializableObjects(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		$job = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['requiresPartnerApproval' => true, 'partnerApprovalStatus' => 'approved'];
			}
		};

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([$job]);

		$controller = $this->buildController(groupManager: $groupManager, objectService: $objectService);
		$data = $controller->overview()->getData();

		self::assertSame(1, $data['dataExchange']['approved']);

	}//end testDataExchangeJobCountsNormaliseJsonSerializableObjects()

	/**
	 * A DataExchangeJob read failure degrades to unknown counts rather than
	 * erroring the whole dashboard.
	 *
	 * @return void
	 */
	public function testDataExchangeJobReadFailureDegradesToUnknownCounts(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willThrowException(new \RuntimeException(message: 'boom'));

		$controller = $this->buildController(groupManager: $groupManager, objectService: $objectService);
		$data = $controller->overview()->getData();

		self::assertNull($data['dataExchange']['pending']);
		self::assertNull($data['dataExchange']['approved']);
		self::assertNull($data['dataExchange']['rejected']);

	}//end testDataExchangeJobReadFailureDegradesToUnknownCounts()

	/**
	 * Builds a controller with sane default mocks, overridable per test.
	 *
	 * @param IUserSession|null  $userSession   Session mock; defaults to an authenticated user.
	 * @param IGroupManager|null $groupManager  Group manager mock; defaults to an empty mock.
	 * @param IRegistry|null     $registry      2FA registry mock; defaults to an empty mock.
	 * @param ObjectService|null $objectService OR object service mock; defaults to an empty mock.
	 *
	 * @return PrivacyGovernanceController
	 */
	private function buildController(
		?IUserSession $userSession = null,
		?IGroupManager $groupManager = null,
		?IRegistry $registry = null,
		?ObjectService $objectService = null,
	): PrivacyGovernanceController {
		if ($userSession === null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('compliance-officer-1');

			$userSession = $this->createMock(IUserSession::class);
			$userSession->method('getUser')->willReturn($user);
		}

		$groupManager ??= $this->createMock(IGroupManager::class);
		$registry ??= $this->createMock(IRegistry::class);
		$objectService ??= $this->createMock(ObjectService::class);

		return new PrivacyGovernanceController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			groupManager: $groupManager,
			twoFactorRegistry: $registry,
			objectService: $objectService,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end buildController()
}//end class
