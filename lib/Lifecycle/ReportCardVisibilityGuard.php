<?php

/**
 * Learniq Report Card Visibility Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
 * @spec openspec/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use DateTimeImmutable;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
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
 * @spec openspec/specs/report-card/spec.md#requirement-publishtoparents-must-not-surface-a-grade-before-its-own-scheduled-visibility-window
 */
class ReportCardVisibilityGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'One or more grades on this report card are not visible yet, so it can not be published to parents.';

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
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
	 * @spec openspec/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True when every contributing GradeEntry's visibleFrom has passed; false blocks it.
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
	 * @spec openspec/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
	 */
	private function allows(array $object): bool {
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
	}//end allows()

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

		foreach ($this->sourceEntries(subjectRows: $subjectRows) as $candidate) {
			$visibleFrom = $this->fetchVisibleFrom(gradeEntryId: $candidate['entry'], tenantId: $tenantId);
			if ($this->hasPassed(visibleFrom: $visibleFrom, now: $now) === false) {
				return $candidate;
			}
		}

		return null;
	}//end firstUnreleasedGradeEntry()

	/**
	 * Every usable source GradeEntry of the report card, in row order, with
	 * the curriculum plan of its row. Rows and ids that are not usable are
	 * left out.
	 *
	 * @param array<int,mixed> $subjectRows The ReportCard's subjectGrades rows.
	 *
	 * @return array<int,array{plan: mixed, entry: string}> The entries to check, in order.
	 *
	 * @spec openspec/specs/report-card/spec.md#requirement-publishtoparents-must-not-surface-a-grade-before-its-own-scheduled-visibility-window
	 */
	private function sourceEntries(array $subjectRows): array {
		$entries = [];
		foreach ($subjectRows as $row) {
			if (is_array($row) === true) {
				$entries = array_merge($entries, $this->entriesOfRow(row: $row));
			}
		}

		return $entries;
	}//end sourceEntries()

	/**
	 * The usable source GradeEntry ids of one subject row, with its plan.
	 *
	 * @param array<string,mixed> $row One subjectGrades row.
	 *
	 * @return array<int,array{plan: mixed, entry: string}> The row's entries, in order.
	 *
	 * @spec openspec/specs/report-card/spec.md#requirement-publishtoparents-must-not-surface-a-grade-before-its-own-scheduled-visibility-window
	 */
	private function entriesOfRow(array $row): array {
		$entries = [];
		foreach ($this->sourceIdsOf(row: $row) as $gradeEntryId) {
			if (is_string($gradeEntryId) === true && $gradeEntryId !== '') {
				$entries[] = [
					'plan' => ($row['curriculumPlanId'] ?? '?'),
					'entry' => $gradeEntryId,
				];
			}
		}

		return $entries;
	}//end entriesOfRow()

	/**
	 * The source GradeEntry ids a subject row lists, or none when it lists them wrongly.
	 *
	 * @param array<string,mixed> $row One subjectGrades row.
	 *
	 * @return array<mixed> The listed ids, not yet checked.
	 *
	 * @spec openspec/specs/report-card/spec.md#requirement-publishtoparents-must-not-surface-a-grade-before-its-own-scheduled-visibility-window
	 */
	private function sourceIdsOf(array $row): array {
		$sourceIds = ($row['sourceGradeEntryIds'] ?? []);
		if (is_array($sourceIds) === false) {
			return [];
		}

		return $sourceIds;
	}//end sourceIdsOf()

	/**
	 * Fetch a GradeEntry's current `visibleFrom` value.
	 *
	 * @param string $gradeEntryId UUID of the GradeEntry.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return string|null The visibleFrom value, or null when unresolvable/unset.
	 */
	private function fetchVisibleFrom(string $gradeEntryId, string $tenantId): ?string {
		$results = $this->objectService->findAll($this->gradeEntryQuery(gradeEntryId: $gradeEntryId, tenantId: $tenantId));

		if (empty($results) === true) {
			$this->logger->warning(
				'[ReportCardVisibilityGuard] GradeEntry {id} not found — treating visibleFrom as not-yet-passed (fail closed).',
				['id' => $gradeEntryId]
			);
			return null;
		}

		return $this->visibleFromOf(result: $results[0]);
	}//end fetchVisibleFrom()

	/**
	 * The findAll config that reads one GradeEntry, scoped to the tenant when there is one.
	 *
	 * @param string $gradeEntryId UUID of the GradeEntry.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter, or ''.
	 *
	 * @return array<string,mixed> The config.
	 */
	private function gradeEntryQuery(string $gradeEntryId, string $tenantId): array {
		return [
			'ids' => [$gradeEntryId],
			'filters' => $this->gradeEntryFilters(tenantId: $tenantId),
			'limit' => 1,
		];
	}//end gradeEntryQuery()

	/**
	 * The findAll filters for a GradeEntry: the tenant first when there is one,
	 * then the register and schema.
	 *
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter, or ''.
	 *
	 * @return array<string,string> The filters.
	 */
	private function gradeEntryFilters(string $tenantId): array {
		$filters = [];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		return array_merge(
			$filters,
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::GRADE_ENTRY_SCHEMA,
			]
		);
	}//end gradeEntryFilters()

	/**
	 * The `visibleFrom` of a found GradeEntry, an array or an entity.
	 *
	 * @param mixed $result The first findAll result.
	 *
	 * @return string|null The visibleFrom value, or null when unset.
	 */
	private function visibleFromOf(mixed $result): ?string {
		$entry = $result;
		if (is_array($result) === false) {
			$entry = $result->jsonSerialize();
		}

		$visibleFrom = $entry['visibleFrom'] ?? null;
		if (is_string($visibleFrom) === false || $visibleFrom === '') {
			return null;
		}

		return $visibleFrom;
	}//end visibleFromOf()

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
