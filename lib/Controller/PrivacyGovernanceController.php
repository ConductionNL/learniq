<?php

/**
 * Learniq Privacy Governance Controller
 *
 * One read-only endpoint (privacy-governance-surfaces): composes the eight
 * `rbac-declare-groups` group ids with live Nextcloud member counts
 * (`IGroupManager`), a best-effort two-factor-adoption count across those
 * same members (`\OCP\Authentication\TwoFactorAuth\IRegistry`, degrading to
 * `null` — never a fabricated zero — when unavailable), and `ExchangePartnerApproval`
 * counts by partner-approval status, into one board-facing governance
 * payload. Mirrors ParnasSys's Privacybasis dashboard (2FA use,
 * groepsautorisatie, roles, koppelingen) named in P-new-6/P-new-7.
 *
 * Legitimate PHP per ADR-031: composes Nextcloud-native group/2FA state that
 * is not an OpenRegister object — no declarative `x-openregister-*` widget
 * has anything to query for it. Identical justification
 * `AiProcessingDisclosureController`'s own docblock already gives for its
 * cross-app composition.
 *
 * No write path lives here — this endpoint is read-only. Approving or
 * rejecting an `ExchangePartnerApproval` is a declared lifecycle transition
 * on that schema, per ADR-022 and ADR-031.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-a-board-facing-dashboard-composes-group-2fa-and-integration-approval-state
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\AppInfo\Application;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Composes the board-facing privacy governance dashboard payload.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-a-board-facing-dashboard-composes-group-2fa-and-integration-approval-state
 */
class PrivacyGovernanceController extends Controller {

	/**
	 * The eight canonical group ids `rbac-declare-groups` provisions.
	 *
	 * @var string[]
	 */
	private const GOVERNANCE_GROUPS = [
		'instructors',
		'hr',
		'compliance-officers',
		'team-leads',
		'learners',
		'coordinators',
		'guardians',
		'administration-managers',
	];

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug for the standing partner approvals the exchange gate reads.
	 */
	private const PARTNER_APPROVAL_SCHEMA = 'exchange-partner-approval';

	/**
	 * The most partner approvals the overview counts (ADR-058).
	 */
	private const PARTNER_APPROVAL_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param IUserSession $userSession Current user session.
	 * @param IGroupManager $groupManager Resolves group membership/counts.
	 * @param IRegistry $twoFactorRegistry Resolves per-user 2FA provider state.
	 * @param ObjectService $objectService OR object service for the partner approval read.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IRegistry $twoFactorRegistry,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Compose the governance overview payload.
	 *
	 * Defence-in-depth only (mirrors `AiProcessingDisclosureController`'s
	 * documented posture): the real enforcement layer is this app's own
	 * `visibleIf` navigation gate restricting the dashboard page to
	 * admin/compliance-officer; this endpoint itself only requires an
	 * authenticated session.
	 *
	 * @return JSONResponse `{groups: [{id, memberCount}], twoFactorEnabledCount, twoFactorEligibleCount, dataExchange: {...}}`.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-opens-the-privacy-governance-dashboard
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-two-factor-adoption-degrades-to-unknown-rather-than-a-fabricated-zero
	 */
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			data: [
				'groups' => $this->loadGroupCounts(),
				'twoFactor' => $this->loadTwoFactorAdoption(),
				'dataExchange' => $this->loadDataExchangeApprovalCounts(),
			]
		);

	}//end overview()

	/**
	 * Member count for each of the eight canonical groups. A group that has
	 * not been provisioned yet (`get()` returns null) is reported with a
	 * `null` count and `provisioned: false` — never a fabricated `0`, which
	 * would be indistinguishable from "provisioned, empty".
	 *
	 * @return array<int,array<string,mixed>> `{id, provisioned, memberCount}` per group.
	 */
	private function loadGroupCounts(): array {
		$rows = [];
		foreach (self::GOVERNANCE_GROUPS as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				$rows[] = [
					'id' => $groupId,
					'provisioned' => false,
					'memberCount' => null,
				];
				continue;
			}

			$count = $group->count();
			$memberCount = $count;
			if ($count === false) {
				$memberCount = null;
			}

			$rows[] = [
				'id' => $groupId,
				'provisioned' => true,
				'memberCount' => $memberCount,
			];
		}

		return $rows;

	}//end loadGroupCounts()

	/**
	 * Best-effort two-factor adoption across the members of the eight
	 * governance groups (bounded — not every user on the instance).
	 * Never fabricates a `0`: any failure, or the absence of a 2FA backend,
	 * degrades every field to `null` ("unknown"), same posture
	 * `AiLocalityClassifier` established for an unverifiable locality
	 * verdict.
	 *
	 * @return array<string,mixed> `{eligibleCount, enabledCount}`, either both null or both integers.
	 */
	private function loadTwoFactorAdoption(): array {
		try {
			$members = $this->governanceMembers();

			return [
				'eligibleCount' => count($members),
				'enabledCount' => $this->countWithTwoFactor(users: $members),
			];
		} catch (Throwable $e) {
			return $this->unknownTwoFactorAdoption(exception: $e);
		}//end try

	}//end loadTwoFactorAdoption()

	/**
	 * The members of the governance groups that exist, each user once.
	 *
	 * @return array<string,IUser> The users by uid.
	 */
	private function governanceMembers(): array {
		$seenUids = [];
		foreach (self::GOVERNANCE_GROUPS as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$seenUids[$user->getUID()] = $user;
			}
		}

		return $seenUids;
	}//end governanceMembers()

	/**
	 * How many of these users have any two-factor provider enabled.
	 *
	 * @param array<string,IUser> $users The users.
	 *
	 * @return int
	 */
	private function countWithTwoFactor(array $users): int {
		$enabled = 0;
		foreach ($users as $user) {
			$states = $this->twoFactorRegistry->getProviderStates(user: $user);
			if (in_array(true, $states, true) === true) {
				$enabled++;
			}
		}

		return $enabled;
	}//end countWithTwoFactor()

	/**
	 * Log a two-factor read that failed and answer unknown, never a fabricated zero.
	 *
	 * @param Throwable $exception Why the read failed.
	 *
	 * @return array{eligibleCount: null, enabledCount: null}
	 */
	private function unknownTwoFactorAdoption(Throwable $exception): array {
		$this->logger->info(
			'[PrivacyGovernanceController] Two-factor adoption read failed ({message}); '
			. 'returning unknown rather than a fabricated zero.',
			['message' => $exception->getMessage()]
		);
		return [
			'eligibleCount' => null,
			'enabledCount' => null,
		];
	}//end unknownTwoFactorAdoption()

	/**
	 * Partner approval counts by status: the "sleeping" (pending) versus
	 * active (approved) integrations ParnasSys's koppelverzoek view shows.
	 * Since data-exchange-to-integriq each row is a standing
	 * `ExchangePartnerApproval` for one target. A read failure degrades to
	 * all-null counts rather than erroring the whole dashboard.
	 *
	 * @return array<string,mixed> `{pending, approved, rejected}` counts, or all null on read failure.
	 */
	private function loadDataExchangeApprovalCounts(): array {
		try {
			$approvals = $this->objectService->findAll($this->approvalQuery());
		} catch (Throwable $e) {
			return $this->unknownApprovalCounts(exception: $e);
		}

		return $this->countByStatus(approvals: $approvals);

	}//end loadDataExchangeApprovalCounts()

	/**
	 * The bounded read of every partner approval.
	 *
	 * @return array<string,mixed>
	 */
	private function approvalQuery(): array {
		return [
			'filters' => [
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::PARTNER_APPROVAL_SCHEMA,
			],
			'limit' => self::PARTNER_APPROVAL_LIMIT,
		];
	}//end approvalQuery()

	/**
	 * Log an approval read that failed and answer unknown counts.
	 *
	 * @param Throwable $exception Why the read failed.
	 *
	 * @return array{pending: null, approved: null, rejected: null}
	 */
	private function unknownApprovalCounts(Throwable $exception): array {
		$this->logger->info(
			'[PrivacyGovernanceController] Partner approval read failed ({message}); returning unknown counts.',
			['message' => $exception->getMessage()]
		);
		return [
			'pending' => null,
			'approved' => null,
			'rejected' => null,
		];
	}//end unknownApprovalCounts()

	/**
	 * Approvals counted by their known status.
	 *
	 * @param array<int,mixed> $approvals The approvals read.
	 *
	 * @return array{pending: int, approved: int, rejected: int}
	 */
	private function countByStatus(array $approvals): array {
		$counts = [
			'pending' => 0,
			'approved' => 0,
			'rejected' => 0,
		];

		foreach ($approvals as $approval) {
			$status = $this->approvalStatus(approval: $approval);
			if ($status !== null && isset($counts[$status]) === true) {
				$counts[$status]++;
			}
		}

		return $counts;
	}//end countByStatus()

	/**
	 * An approval's status (pending when it has none), or null for anything
	 * that is not an approval row.
	 *
	 * @param mixed $approval An approval row or entity.
	 *
	 * @return mixed
	 */
	private function approvalStatus(mixed $approval): mixed {
		$row = $approval;
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		return ($row['status'] ?? 'pending');
	}//end approvalStatus()
}//end class
