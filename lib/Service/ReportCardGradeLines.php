<?php

/**
 * Learniq report card grade lines
 *
 * Writes a report card's subject grades the way a guardian reads them: the
 * name of the report period ("Rapport 1") and one line per subject
 * ("Rekenen: 7,9"). A ReportCard keeps its grades in `subjectGrades`, a list
 * of objects that name the subject and the period only by uuid. The portal
 * shows a nested list as one cell and leaves every uuid out, so a guardian
 * read "7,9, Yes; 8,3, Yes" without a subject or a period. ReportCard keeps
 * these readable copies (`periodName`, `gradeLines`) beside the source, and
 * the parent portal shows them.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-a-report-card-carries-its-subject-grades-in-readable-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Derives `periodName` and `gradeLines` from a ReportCard's own references.
 *
 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-a-report-card-carries-its-subject-grades-in-readable-form
 */
class ReportCardGradeLines {

	private const LEARNIQ_REGISTER = 'learniq';
	private const COURSE_SCHEMA = 'course';
	private const PLAN_SCHEMA = 'curriculum-plan';
	private const PERIOD_SCHEMA = 'report-period';

	/**
	 * Names already looked up, keyed by schema and uuid; null when the object
	 * has none or does not exist. A report card has a handful of subjects and
	 * a whole school shares them, so a back-fill reads each name once.
	 *
	 * @var array<string, string|null>
	 */
	private array $names = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The readable copies of a report card's period and subject grades.
	 *
	 * A subject is named by its course, else by its curriculum plan. A subject
	 * that has neither name is left out rather than shown as a code. A grade
	 * is written with one decimal and a decimal comma, the way a Dutch report
	 * card writes it; a subject without a grade shows its name alone.
	 *
	 * @param array<string, mixed> $reportCard The ReportCard as it will be saved.
	 *
	 * @return array{periodName: string|null, gradeLines: array<int, string>}
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-a-report-card-carries-its-subject-grades-in-readable-form
	 */
	public function derive(array $reportCard): array {
		$rows = array_values(
			array_filter((array)($reportCard['subjectGrades'] ?? []), static fn ($row): bool => is_array($row) === true)
		);

		$this->lookUp(schema: self::PERIOD_SCHEMA, ids: [$this->text(value: ($reportCard['reportPeriodId'] ?? null))]);
		$this->lookUp(schema: self::COURSE_SCHEMA, ids: array_map(fn (array $row): string => $this->text(value: ($row['courseId'] ?? null)), $rows));
		$this->lookUp(schema: self::PLAN_SCHEMA, ids: array_map(fn (array $row): string => $this->text(value: ($row['curriculumPlanId'] ?? null)), $rows));

		$lines = [];
		foreach ($rows as $row) {
			$subject = ($this->nameOf(schema: self::COURSE_SCHEMA, id: $this->text(value: ($row['courseId'] ?? null)))
				?? $this->nameOf(schema: self::PLAN_SCHEMA, id: $this->text(value: ($row['curriculumPlanId'] ?? null))));
			if ($subject === null) {
				continue;
			}

			$lines[] = $this->line(subject: $subject, grade: ($row['periodAverage'] ?? null));
		}

		return [
			'periodName' => $this->nameOf(schema: self::PERIOD_SCHEMA, id: $this->text(value: ($reportCard['reportPeriodId'] ?? null))),
			'gradeLines' => $lines,
		];
	}//end derive()

	/**
	 * One subject line: "Rekenen: 7,9", or "Rekenen" without a grade.
	 *
	 * @param string $subject The subject name.
	 * @param mixed  $grade   The period average.
	 *
	 * @return string
	 */
	private function line(string $subject, mixed $grade): string {
		if (is_int($grade) === false && is_float($grade) === false) {
			return $subject;
		}

		return $subject . ': ' . number_format((float)$grade, 1, ',', '');
	}//end line()

	/**
	 * Read the names of the given objects that are not cached yet, in one query.
	 *
	 * @param string             $schema The schema slug.
	 * @param array<int, string> $ids    The uuids; empty strings are skipped.
	 *
	 * @return void
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function lookUp(string $schema, array $ids): void {
		$missing = [];
		foreach ($ids as $id) {
			if ($id !== '' && array_key_exists($schema . ':' . $id, $this->names) === false) {
				$missing[$id] = true;
			}
		}

		if (count($missing) === 0) {
			return;
		}

		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => $schema,
				],
				'ids' => array_keys($missing),
				'limit' => count($missing),
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach (array_keys($missing) as $id) {
			$this->names[$schema . ':' . $id] = null;
		}

		foreach ($objects as $object) {
			$row = $this->toRow(object: $object);
			$id = $this->text(value: ($row['id'] ?? ($row['uuid'] ?? null)));
			if (array_key_exists($id, $missing) === false) {
				continue;
			}

			$name = trim($this->text(value: ($row['name'] ?? null)));
			if ($name !== '') {
				$this->names[$schema . ':' . $id] = $name;
			}
		}
	}//end lookUp()

	/**
	 * The cached name of an object, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return string|null
	 */
	private function nameOf(string $schema, string $id): ?string {
		if ($id === '') {
			return null;
		}

		return ($this->names[$schema . ':' . $id] ?? null);
	}//end nameOf()

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $object An array or a serialisable entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end toRow()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()
}//end class
