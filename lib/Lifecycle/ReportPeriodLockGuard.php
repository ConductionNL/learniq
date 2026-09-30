<?php

/**
 * Learniq Report Period Lock Guard
 *
 * Lifecycle guard for the GradeEntry schema's `publish` and `republish`
 * transitions (grading spec, report-card-composer delta). Blocks ordinary
 * grade-publishing once a matching `ReportPeriod` is locked (`isLocked`
 * true), unless a DataCorrectionRequest for the entry was approved by a
 * second person (governance-four-eyes). The former lone override for
 * admin, team leads and administration managers is gone: those groups now
 * approve a correction, and the publish itself needs the approved request.
 *
 * DEVIATION FROM THE ORIGINAL DESIGN — this class REPLACES
 * {@see FraudCaseBlockGuard} as the `requires` value on `publish`/`republish`
 * rather than being added "alongside" it as a second `requires` entry. The
 * design doc's own precedent for a second entry (`certification`'s
 * `Credential.revoke` transition) does not exist as an array shape at HEAD:
 * `Credential.revoke.requires` is a single string
 * (`OCA\Learniq\Service\WalletRevocationPropagationService`), and
 * OpenRegister's own `LifecycleAnnotationValidator::validate()` explicitly
 * rejects a non-string `requires` value (`is_string($spec['requires']) ===
 * false || $spec['requires'] === ''` => `lifecycle-requires-malformed`);
 * `TransitionEngine::listAvailableActions()` likewise casts `requires` to a
 * single `(string)`. There is no "stack two guards" shape to use. This class
 * instead COMPOSES the original guard: it constructor-injects
 * {@see FraudCaseBlockGuard} and calls its `check()` first (unchanged
 * fraud-case behaviour, byte-for-byte), then applies the report-period lock
 * check on top — mirroring how `MunicipalityFeedbackGuard`'s own docblock
 * already documents "no `x-openregister-*` extension expresses [this], so a
 * guard composes the missing capability" as a legitimate ADR-031 seam.
 *
 * Match key deviation: the report-card design.md text describes matching a
 * governing `ReportPeriod` by "periodCode + curriculumPlanIds containment +
 * academicYear". `GradeEntry` carries no `academicYear` property (verified:
 * only `Cohort`/`BsaTrajectory` do) — `tenant_id`, present on both schemas,
 * is used as the equivalent multi-tenant scoping safeguard instead.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Referenced from the GradeEntry schema's
 * x-openregister-lifecycle.transitions.publish/republish.requires in
 * learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-publishrepublish-proceeds-unaffected-when-no-reportperiod-governs-the-entry
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the GradeEntry `publish`/`republish` lifecycle transitions.
 *
 * Delegates to the pre-existing {@see FraudCaseBlockGuard} first (unchanged
 * behaviour); when that passes, resolves whether the entry's `period` +
 * `curriculumPlanId` match any `report-card` `ReportPeriod` (by `periodCode`
 * + `curriculumPlanIds` containment + `tenant_id`) that is `isLocked`. If no
 * such `ReportPeriod` exists, allows unconditionally (fail-open — a school
 * not using report cards, or a GradeEntry outside any declared
 * ReportPeriod's scope, is completely unaffected). If a matching, locked
 * ReportPeriod exists, blocks unless an approved correction request covers
 * this publish (CorrectionApprovals).
 *
 * @spec openspec/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */
class ReportPeriodLockGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'This grade is in a locked report period. A change needs a correction that a second person'
		. ' approved. Publish the grade with the approved value.';

	private const LEARNIQ_REGISTER = 'learniq';
	private const REPORT_PERIOD_SCHEMA = 'report-period';

	/**
	 * Constructor.
	 *
	 * @param FraudCaseBlockGuard $fraudCaseBlockGuard The original guard this class composes (unchanged behaviour, called first).
	 * @param ObjectService $objectService OR object access service.
	 * @param CorrectionApprovals $corrections The approved correction for a grade entry, if any.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FraudCaseBlockGuard $fraudCaseBlockGuard,
		private readonly ObjectService $objectService,
		private readonly CorrectionApprovals $corrections,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
	 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-publishrepublish-proceeds-unaffected-when-no-reportperiod-governs-the-entry
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		// 1. Preserve the original fraud-case check.
		$fraudCaseVerdict = $this->fraudCaseBlockGuard->check($object, $action, $userId);
		if ($fraudCaseVerdict->isAllowed() === false) {
			return $fraudCaseVerdict;
		}

		if ($this->allows(entry: $object, userId: $userId) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the
	 * `publish`/`republish` transition on a GradeEntry object.
	 *
	 * @param array<string,mixed> $entry The object at its target state, transition inputs merged in.
	 * @param string $userId The uid of the caller.
	 *
	 * @return bool True if the transition is allowed; false blocks it.
	 *
	 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
	 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-publishrepublish-proceeds-unaffected-when-no-reportperiod-governs-the-entry
	 */
	private function allows(array $entry, string $userId): bool {
		$period = (string)($entry['period'] ?? '');
		$curriculumPlanId = (string)($entry['curriculumPlanId'] ?? '');
		$tenantId = (string)($entry['tenant_id'] ?? '');
		$entryId = $entry['id'] ?? ($entry['uuid'] ?? '');

		if ($period === '' || $curriculumPlanId === '') {
			// Nothing to match a ReportPeriod against.
			return true;
		}

		$reportPeriod = $this->findGoverningReportPeriod(
			period: $period,
			curriculumPlanId: $curriculumPlanId,
			tenantId: $tenantId
		);

		if ($reportPeriod === null) {
			// No ReportPeriod governs this entry — fail open, mirroring
			// AttendanceFlagReportGuard's "no linked job -> allow
			// unconditionally" posture.
			return true;
		}

		$isLocked = $reportPeriod['isLocked'] ?? false;

		if ($isLocked !== true) {
			return true;
		}

		$request = $this->corrections->approvedFor(entry: $entry, publisher: $userId);
		if ($request !== null) {
			$this->logger->info(
				'[ReportPeriodLockGuard] GradeEntry {id} published in locked ReportPeriod {period} on correction {request}'
				. ' (asked by {requester}, approved by {approver}, published by {actor}).',
				[
					'id'        => $entryId,
					'period'    => $reportPeriod['id'] ?? ($reportPeriod['uuid'] ?? ''),
					'request'   => $request['id'] ?? '',
					'requester' => $request['requestedBy'] ?? '',
					'approver'  => $request['decidedBy'] ?? '',
					'actor'     => $userId,
				]
			);
			return true;
		}

		$this->logger->info(
			'[ReportPeriodLockGuard] GradeEntry {id} blocked: ReportPeriod {period} is locked and no correction approved by a second person covers {actor}.',
			['id' => $entryId, 'period' => $reportPeriod['id'] ?? ($reportPeriod['uuid'] ?? ''), 'actor' => $userId]
		);

		return false;
	}//end allows()

	/**
	 * Resolve the ReportPeriod (if any) governing this GradeEntry's period +
	 * curriculumPlanId, scoped to the same tenant.
	 *
	 * @param string $period GradeEntry.period value.
	 * @param string $curriculumPlanId GradeEntry.curriculumPlanId value.
	 * @param string $tenantId GradeEntry.tenant_id value.
	 *
	 * @return array<string,mixed>|null The governing ReportPeriod data array, or null when none matches.
	 */
	private function findGoverningReportPeriod(string $period, string $curriculumPlanId, string $tenantId): ?array {
		$filters = ['periodCode' => $period];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$candidates = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::REPORT_PERIOD_SCHEMA,
					]
				),
				'limit' => 500,
			]
		);

		foreach ($candidates as $candidate) {
			$candidateData = $candidate;
			if (is_array($candidate) === false) {
				$candidateData = $candidate->jsonSerialize();
			}

			$curriculumPlanIds = $candidateData['curriculumPlanIds'] ?? [];
			if (is_array($curriculumPlanIds) === false) {
				continue;
			}

			if (in_array($curriculumPlanId, $curriculumPlanIds, true) === true) {
				return $candidateData;
			}
		}//end foreach

		return null;
	}//end findGoverningReportPeriod()
}//end class
