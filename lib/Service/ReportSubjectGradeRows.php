<?php

/**
 * Learniq ReportSubjectGradeRows
 *
 * A pupil's latest published report card as one row per subject
 * (`report-subject-grade`), so the parent portal can draw a bar per subject.
 * Portaliq lists rows of a collection; a report card keeps its subjects in
 * one nested `subjectGrades` list, which a list block cannot split. The
 * report card itself is never written.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-latest-report-reads-as-one-bar-per-subject
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Writes and replaces the per-subject rows of a published report card.
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-latest-report-reads-as-one-bar-per-subject
 */
class ReportSubjectGradeRows {

	private const REGISTER = 'learniq';

	public const SCHEMA = 'report-subject-grade';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the names and writes the rows.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The rows of one report card, without writing anything.
	 *
	 * One row per subject that has a name, in the card's order. The caption
	 * ("Rapport 2 · juni 2026 · Groep 6") and the teacher's words travel on
	 * every row; the bars read them from the first.
	 *
	 * @param array<string, mixed>  $card  The report card, with its id.
	 * @param array<string, string> $names Course and plan names by id.
	 * @param string                $group The group's name, or ''.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-latest-report-reads-as-one-bar-per-subject
	 */
	public static function rowsFor(array $card, array $names, string $group): array {
		$caption = implode(' · ', array_values(array_filter([
			trim((string)($card['periodName'] ?? '')),
			self::monthOf(at: (string)($card['composedAt'] ?? '')),
			$group,
		], static fn (string $part): bool => $part !== '')));

		$rows = [];
		foreach ((array)($card['subjectGrades'] ?? []) as $grade) {
			$name = ($names[(string)($grade['courseId'] ?? '')] ?? ($names[(string)($grade['curriculumPlanId'] ?? '')] ?? ''));
			if ($name === '') {
				continue;
			}

			$rows[] = [
				'learnerRef'    => (string)($card['learnerRef'] ?? ''),
				'learnerId'     => (string)($card['learnerId'] ?? ''),
				'reportCardId'  => (string)($card['id'] ?? ''),
				'subjectName'   => $name,
				'periodAverage' => ($grade['periodAverage'] ?? null),
				'passed'        => ($grade['passed'] ?? null),
				'position'      => count($rows),
				'caption'       => ($caption === '' ? null : $caption),
				'mentorComment' => ($card['mentorComment'] ?? null),
				'tenant_id'     => (string)($card['tenant_id'] ?? ''),
			];
		}

		return $rows;
	}//end rowsFor()

	/**
	 * Replace the pupil's rows with those of this newly published card.
	 *
	 * The rows of the pupil's older cards go first, so the portal always
	 * shows the latest report. A card without a pupil or an id writes nothing.
	 *
	 * @param array<string, mixed> $card The report card that was published.
	 *
	 * @return int The rows written.
	 *
	 * @throws \Throwable When OpenRegister refuses a read or a write.
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-latest-report-reads-as-one-bar-per-subject
	 */
	public function replace(array $card): int {
		$learnerRef = (string)($card['learnerRef'] ?? '');
		if ($learnerRef === '' || (string)($card['id'] ?? '') === '') {
			return 0;
		}

		foreach ($this->rowsOf(learnerRef: $learnerRef) as $old) {
			$this->objectService->deleteObject(
				uuid: (string)($old['id'] ?? ($old['uuid'] ?? '')),
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		}

		$rows = self::rowsFor(card: $card, names: $this->names(card: $card), group: $this->groupName(cohortId: (string)($card['cohortId'] ?? '')));
		foreach ($rows as $row) {
			$this->objectService->saveObject(
				object: $row,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		}

		return count($rows);
	}//end replace()

	/**
	 * "juni 2026" from an ISO date-time, or ''.
	 *
	 * @param string $at The date-time.
	 *
	 * @return string
	 */
	private static function monthOf(string $at): string {
		if (preg_match('/^(\d{4})-(\d{2})/', $at, $match) !== 1) {
			return '';
		}

		$months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

		return ($months[((int)$match[2]) - 1] ?? '') . ' ' . $match[1];
	}//end monthOf()

	/**
	 * The stored rows of a pupil.
	 *
	 * @param string $learnerRef The pupil's profile id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function rowsOf(string $learnerRef): array {
		return $this->read(filters: ['learnerRef' => $learnerRef], schema: self::SCHEMA, limit: 200);
	}//end rowsOf()

	/**
	 * The names of the card's courses and plans, by id.
	 *
	 * @param array<string, mixed> $card The report card.
	 *
	 * @return array<string, string>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function names(array $card): array {
		$names = [];
		foreach (['course' => 'courseId', 'curriculum-plan' => 'curriculumPlanId'] as $schema => $field) {
			$ids = array_values(array_unique(array_filter(array_map(static fn ($g): string => (string)(((array)$g)[$field] ?? ''), (array)($card['subjectGrades'] ?? [])))));
			if ($ids === []) {
				continue;
			}

			foreach ($this->read(filters: [], schema: $schema, limit: count($ids), ids: $ids) as $row) {
				$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
				$names[$id] = ($names[$id] ?? trim((string)($row['name'] ?? '')));
			}
		}

		return array_filter($names, static fn (string $name): bool => $name !== '');
	}//end names()

	/**
	 * The name of the card's group, or ''.
	 *
	 * @param string $cohortId The cohort id.
	 *
	 * @return string
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function groupName(string $cohortId): string {
		if ($cohortId === '') {
			return '';
		}

		return trim((string)(($this->read(filters: [], schema: 'cohort', limit: 1, ids: [$cohortId])[0] ?? [])['name'] ?? ''));
	}//end groupName()

	/**
	 * Rows of a learniq schema as arrays.
	 *
	 * @param array<string, mixed> $filters Property filters.
	 * @param string               $schema  The schema slug.
	 * @param int                  $limit   The cap.
	 * @param array<int, string>   $ids     Ids to read, or none.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function read(array $filters, string $schema, int $limit, array $ids = []): array {
		$config = ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => $limit];
		if ($ids !== []) {
			$config['ids'] = $ids;
		}

		$out = [];
		foreach ($this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end read()
}//end class
