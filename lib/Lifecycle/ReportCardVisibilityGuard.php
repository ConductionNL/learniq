<?php

/**
 * Learniq Report Card Visibility Guard
 *
 * Lifecycle guard for the ReportCard schema's `publishToParents` transition
 * (finalised -> published-to-parents). Re-resolves every contributing
 * subject's `GradeEntry.visibleFrom` at the actual moment of publication —
 * not trusted from composition time — and blocks the transition unless
 * every one has already passed `@now`.
 *
 * This is the enforcement point for report-card's "publishToParents MUST
 * NOT surface a grade before its own scheduled visibility window"
 * requirement: `ReportCard.draft` can sit in `rapportvergadering-review` for
 * days between composition and a mentor clicking "publish", and
 * `CurriculumPlan.gradeVisibilityPolicy.mode: nextSchoolDay` exists
 * precisely so a grade published at 23:40 doesn't surface until the next
 * morning. Trusting the compose-time snapshot would let a report card
 * composed the moment before a `visibleFrom` window opens still be
 * published-to-parents seconds later, reopening exactly the hole
 * grade-visibility-scheduling closed.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Referenced from the ReportCard schema's
 * x-openregister-lifecycle.transitions.publishToParents.requires in
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Guards the ReportCard `publishToParents` (finalised ->
 * published-to-parents) lifecycle transition.
 *
 * Resolves every `subjectGrades[].sourceGradeEntryIds[]` entry's current
 * `GradeEntry.visibleFrom` and blocks unless every resolved value has
 * already passed the current moment (an unresolvable entry, or one with a
 * null/future `visibleFrom`, blocks — fail closed).
 *
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-publishtoparents-must-not-surface-a-grade-before-its-own-scheduled-visibility-window
 */
class ReportCardVisibilityGuard {

	private const LEARNIQ_REGISTER = 'learniq';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param ITimeFactory $timeFactory NC time source (injectable "now" for tests).
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * OR lifecycle guard entry-point.
	 *
	 * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
	 *                                               - 'object'     : the ReportCard data array
	 *                                               - 'transition' : 'publishToParents'
	 *                                               - 'from'       : 'finalised'
	 *                                               - 'to'         : 'published-to-parents'
	 *
	 * @return bool True when every contributing GradeEntry's visibleFrom has passed; false blocks it.
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
	 */
	public function check(array &$transitionContext): bool {
		$object = $transitionContext['object'] ?? [];
		$objectId = $object['id'] ?? ($object['uuid'] ?? '');
		$subjectRows = $object['subjectGrades'] ?? [];
		$tenantId = (string)($object['tenant_id'] ?? '');

		if (is_array($subjectRows) === false) {
			return true;
		}

		$blocking = $this->firstUnreleasedGradeEntry(subjectRows: $subjectRows, tenantId: $tenantId);
		if ($blocking !== null) {
			$this->logger->info(
				'[ReportCardVisibilityGuard] ReportCard {id} blocked — subject {plan} sourceGradeEntry {entry} visibleFrom has not passed.',
				[
					'id' => $objectId,
					'plan' => $blocking['plan'],
					'entry' => $blocking['entry'],
				]
			);
			return false;
		}

		return true;
	}//end check()

	/**
	 * Find the first source GradeEntry whose `visibleFrom` has not yet passed.
	 *
	 * Rows and ids that are not usable are skipped rather than treated as
	 * blocking — the guard only refuses on a grade it can actually show is
	 * still embargoed.
	 *
	 * @param array<int,mixed> $subjectRows The ReportCard's subjectGrades rows.
	 * @param string $tenantId Tenant UUID scope.
	 *
	 * @return array{plan: mixed, entry: string}|null The blocking entry, or null when all are released.
	 *
	 * @spec openspec/specs/report-card/spec.md#requirement-publishtoparents-must-not-surface-a-grade-before-its-own-scheduled-visibility-window
	 */
	private function firstUnreleasedGradeEntry(array $subjectRows, string $tenantId): ?array {
		$now = $this->timeFactory->now();

		foreach ($subjectRows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$entry = $this->firstUnreleasedEntryInRow(row: $row, tenantId: $tenantId, now: $now);
			if ($entry !== null) {
				return [
					'plan' => ($row['curriculumPlanId'] ?? '?'),
					'entry' => $entry,
				];
			}
		}

		return null;
	}//end firstUnreleasedGradeEntry()

	/**
	 * Find the first still-embargoed source GradeEntry within one subject row.
	 *
	 * @param array<string,mixed> $row One subjectGrades row.
	 * @param string $tenantId Tenant UUID scope.
	 * @param mixed $now The current time, resolved once by the caller.
	 *
	 * @return string|null The blocking GradeEntry id, or null when the row is clear.
	 *
	 * @spec openspec/specs/report-card/spec.md#requirement-publishtoparents-must-not-surface-a-grade-before-its-own-scheduled-visibility-window
	 */
	private function firstUnreleasedEntryInRow(array $row, string $tenantId, mixed $now): ?string {
		$sourceIds = ($row['sourceGradeEntryIds'] ?? []);
		if (is_array($sourceIds) === false) {
			return null;
		}

		foreach ($sourceIds as $gradeEntryId) {
			if (is_string($gradeEntryId) === false || $gradeEntryId === '') {
				continue;
			}

			$visibleFrom = $this->fetchVisibleFrom(gradeEntryId: $gradeEntryId, tenantId: $tenantId);
			if ($this->hasPassed(visibleFrom: $visibleFrom, now: $now) === false) {
				return $gradeEntryId;
			}
		}

		return null;
	}//end firstUnreleasedEntryInRow()

	/**
	 * Fetch a GradeEntry's current `visibleFrom` value.
	 *
	 * @param string $gradeEntryId UUID of the GradeEntry.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return string|null The visibleFrom value, or null when unresolvable/unset.
	 */
	private function fetchVisibleFrom(string $gradeEntryId, string $tenantId): ?string {
		$filters = ['id' => $gradeEntryId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::GRADE_ENTRY_SCHEMA,
				'filters' => $filters,
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			$this->logger->warning(
				'[ReportCardVisibilityGuard] GradeEntry {id} not found — treating visibleFrom as not-yet-passed (fail closed).',
				['id' => $gradeEntryId]
			);
			return null;
		}

		$entry = $results[0];
		if (is_array($results[0]) === false) {
			$entry = $results[0]->jsonSerialize();
		}

		$visibleFrom = $entry['visibleFrom'] ?? null;
		if (is_string($visibleFrom) === false || $visibleFrom === '') {
			return null;
		}

		return $visibleFrom;
	}//end fetchVisibleFrom()

	/**
	 * Whether a `visibleFrom` value has already passed the given "now".
	 *
	 * A null/unresolvable `visibleFrom` is treated as NOT yet passed
	 * (fail closed) — the whole point of this guard is to never surface a
	 * grade whose scheduled visibility is uncertain or still pending.
	 *
	 * @param string|null $visibleFrom ISO-8601 visibleFrom value, or null.
	 * @param DateTimeImmutable $now The current moment.
	 *
	 * @return bool True when visibleFrom is set and in the past.
	 */
	private function hasPassed(?string $visibleFrom, DateTimeImmutable $now): bool {
		if ($visibleFrom === null) {
			return false;
		}

		try {
			$visibleFromDate = new DateTimeImmutable($visibleFrom);
		} catch (\Exception) {
			return false;
		}

		return $visibleFromDate <= $now;
	}//end hasPassed()
}//end class
