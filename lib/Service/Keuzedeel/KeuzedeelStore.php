<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-an-mbo-student-chooses-a-keuzedeel-inside-its-window
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Keuzedeel;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads the keuzedeel offers and choices a student's screens and line need.
 *
 * Reads without the caller's RBAC: the caller has already bound the student
 * to the signed-in user (the choose endpoint) or is the server (the restamp).
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
 */
class KeuzedeelStore {

	/**
	 * The learniq register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService  $objectService Reads the records.
	 * @param KeuzedeelRules $rules         Decides which offers are for the student.
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly KeuzedeelRules $rules = new KeuzedeelRules(),
	) {
	}//end __construct()

	/**
	 * The offered keuzedelen meant for this student.
	 *
	 * @param string $learnerUserId The student.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	public function offersFor(string $learnerUserId): array {
		$programmeIds = [];
		$cohortIds    = [];
		foreach ($this->rows(schema: 'enrolment', filters: ['learnerId' => $learnerUserId]) as $enrolment) {
			if (in_array($enrolment['lifecycle'] ?? null, ['withdrawn', 'failed'], true) === true) {
				continue;
			}

			if (is_string($enrolment['programmeId'] ?? null) === true && $enrolment['programmeId'] !== '') {
				$programmeIds[] = $enrolment['programmeId'];
			}

			if (is_string($enrolment['cohortId'] ?? null) === true && $enrolment['cohortId'] !== '') {
				$cohortIds[] = $enrolment['cohortId'];
			}
		}

		return array_values(
			array_filter(
				$this->rows(schema: 'keuzedeel-offer', filters: ['lifecycle' => 'offered']),
				fn (array $offer): bool => $this->rules->isFor(offer: $offer, programmeIds: $programmeIds, cohortIds: $cohortIds)
			)
		);
	}//end offersFor()

	/**
	 * One offer by id.
	 *
	 * @param string $offerId The offer.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	public function offer(string $offerId): ?array {
		if ($offerId === '') {
			return null;
		}

		foreach ($this->rows(schema: 'keuzedeel-offer', filters: [], ids: [$offerId]) as $offer) {
			if ((string) ($offer['id'] ?? ($offer['uuid'] ?? '')) === $offerId) {
				return $offer;
			}
		}

		return null;
	}//end offer()

	/**
	 * The choices of a student, or of an offer.
	 *
	 * @param array<string, string> $filters learnerId or offerId.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	public function choices(array $filters): array {
		return $this->rows(schema: 'keuzedeel-choice', filters: $filters);
	}//end choices()

	/**
	 * The rows of a schema that match the filters, as arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters, checked again on every row.
	 * @param list<string>         $ids     Restrict to these ids.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, array $filters, array $ids = []): array {
		$config = ['filters' => ['register' => self::REGISTER, 'schema' => $schema] + $filters, 'limit' => 5000];
		if ($ids !== []) {
			$config['ids'] = $ids;
		}

		$rows = [];
		foreach ($this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false) as $row) {
			$data = $row;
			if (is_array($row) === false) {
				$data = (array) $row->jsonSerialize();
			}

			$matches = true;
			foreach ($filters as $field => $value) {
				if (($data[$field] ?? null) !== $value) {
					$matches = false;
				}
			}

			if ($matches === true) {
				$rows[] = $data;
			}
		}

		return $rows;
	}//end rows()
}//end class
