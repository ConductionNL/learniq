<?php

/**
 * Learniq Programme Progress
 *
 * A learner's progress through each programme they are enrolled in, read
 * from their own course enrolments that name the programme (`programmeId`).
 * The enrolment's `mandatory` flag decides whether a part counts (design D1:
 * the programme only supplies the default at enrolment time, a manager may
 * change it per person): completion is the share of mandatory parts in
 * `completed`, and optional parts are listed apart without counting. A
 * programme where none of the learner's parts is mandatory (every programme
 * from before `mandatoryCourseIds`, design D2) counts every part, so it is
 * not complete from the start. A withdrawn part counts nowhere.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Programme
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
 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#requirement-progress-counts-mandatory-parts
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Programme;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Programme progress per learner, counting mandatory parts only.
 */
class ProgrammeProgress {

	/**
	 * The register every row lives in.
	 */
	private const REGISTER = 'learniq';

	/**
	 * Enrolment states that do not count as a part at all.
	 */
	private const NOT_COUNTED = ['withdrawn'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
	) {
	}//end __construct()

	/**
	 * Progress for every programme the learner has a part enrolment in.
	 *
	 * The reads skip OpenRegister's RBAC because they are scoped here: only
	 * rows whose `learnerId` is the caller, and the programmes and courses
	 * those rows name.
	 *
	 * @param string $userId The learner's Nextcloud user id.
	 *
	 * @return list<array{programmeId: string, name: string, mandatoryTotal: int, mandatoryCompleted: int, percent: int, complete: bool, mandatory: list<array{courseId: string, courseName: string, lifecycle: string}>, optional: list<array{courseId: string, courseName: string, lifecycle: string}>}>
	 *
	 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#scenario-optional-parts-do-not-block-completion
	 */
	public function forLearner(string $userId): array {
		if ($userId === '') {
			return [];
		}

		$rows = $this->objects->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => 'enrolment', 'learnerId' => $userId], 'limit' => 1000],
			_rbac: false
		);

		$byProgramme = [];
		foreach ((array)$rows as $row) {
			$enrolment = $this->toArray(value: $row);
			$programmeId = (string)($enrolment['programmeId'] ?? '');
			if ($programmeId === '' || ($enrolment['learnerId'] ?? '') !== $userId
				|| in_array((string)($enrolment['lifecycle'] ?? ''), self::NOT_COUNTED, true) === true
			) {
				continue;
			}

			$byProgramme[$programmeId][] = $enrolment;
		}

		$summaries = [];
		foreach ($byProgramme as $programmeId => $enrolments) {
			$programme = ($this->read(schema: 'programme', id: (string)$programmeId) ?? []);
			$summaries[] = $this->summarise(programmeId: (string)$programmeId, name: (string)($programme['name'] ?? ''), enrolments: $enrolments);
		}

		return $summaries;
	}//end forLearner()

	/**
	 * One programme's progress from the learner's part enrolments.
	 *
	 * @param string                     $programmeId The programme uuid.
	 * @param string                     $name        The programme name.
	 * @param list<array<string, mixed>> $enrolments  The learner's counted part enrolments.
	 *
	 * @return array{programmeId: string, name: string, mandatoryTotal: int, mandatoryCompleted: int, percent: int, complete: bool, mandatory: list<array{courseId: string, courseName: string, lifecycle: string}>, optional: list<array{courseId: string, courseName: string, lifecycle: string}>}
	 */
	private function summarise(string $programmeId, string $name, array $enrolments): array {
		$anyMandatory = in_array(true, array_map(static fn (array $e): bool => ($e['mandatory'] ?? false) === true, $enrolments), true);

		$mandatory = [];
		$optional = [];
		foreach ($enrolments as $enrolment) {
			$part = $this->part(enrolment: $enrolment);
			if ($anyMandatory === false || ($enrolment['mandatory'] ?? false) === true) {
				$mandatory[] = $part;
				continue;
			}

			$optional[] = $part;
		}

		$completed = count(array_filter($mandatory, static fn (array $part): bool => $part['lifecycle'] === 'completed'));
		$total = count($mandatory);
		$percent = 0;
		if ($total > 0) {
			$percent = (int)round(100 * $completed / $total);
		}

		return [
			'programmeId' => $programmeId,
			'name' => $name,
			'mandatoryTotal' => $total,
			'mandatoryCompleted' => $completed,
			'percent' => $percent,
			'complete' => $total > 0 && $completed === $total,
			'mandatory' => $mandatory,
			'optional' => $optional,
		];
	}//end summarise()

	/**
	 * One part as the learner sees it.
	 *
	 * @param array<string, mixed> $enrolment The part enrolment.
	 *
	 * @return array{courseId: string, courseName: string, lifecycle: string}
	 */
	private function part(array $enrolment): array {
		$courseId = (string)($enrolment['courseId'] ?? '');
		$course = ($this->read(schema: 'course', id: $courseId) ?? []);

		return [
			'courseId' => $courseId,
			'courseName' => (string)($course['name'] ?? ($course['title'] ?? $courseId)),
			'lifecycle' => (string)($enrolment['lifecycle'] ?? ''),
		];
	}//end part()

	/**
	 * One row by id, or null when there is none.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false, _render: false);
		} catch (DoesNotExistException) {
			return null;
		}

		$row = $object?->jsonSerialize();
		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end read()

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $value An ObjectEntity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end toArray()
}//end class
