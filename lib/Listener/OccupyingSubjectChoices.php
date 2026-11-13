<?php

/**
 * Learniq Occupying Subject Choices
 *
 * The other SubjectChoice rows of a curriculum plan that hold a seat: the
 * ones in an approved or locked state, within the tenant when it is known.
 * SubjectChoiceValidator counts them against capacityByCourseId.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/school-structure/spec.md#requirement-a-submitted-subject-choice-is-validated-against-the-plan-s-elective-rules-not-persisted-unchecked
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads the SubjectChoice rows that occupy a seat on the same plan.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-a-submitted-subject-choice-is-validated-against-the-plan-s-elective-rules-not-persisted-unchecked
 */
final class OccupyingSubjectChoices {

	/**
	 * SubjectChoice lifecycle states that occupy a seat for capacity counting.
	 *
	 * @var string[]
	 */
	private const OCCUPYING_STATES = ['approved', 'locked'];

	/**
	 * Where SubjectChoice rows are read from.
	 */
	private const SOURCE = [
		'register' => 'learniq',
		'schema' => 'subject-choice',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The plan's SubjectChoice rows in an occupying state, each state read in turn,
	 * without the SubjectChoice being validated.
	 *
	 * @param array{curriculumPlanId: string, tenantId: string, choiceId: string} $choice The governing CurriculumPlan UUID, the tenant and
	 *     the SubjectChoice id being validated, excluded from results.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-submitted-subject-choice-is-validated-against-the-plan-s-elective-rules-not-persisted-unchecked
	 */
	public function forChoice(array $choice): array {
		$siblings = [];
		foreach (self::OCCUPYING_STATES as $lifecycle) {
			$rows = $this->objectService->findAll(['filters' => self::filters(choice: $choice, lifecycle: $lifecycle), 'limit' => 5000]);
			$siblings = array_merge($siblings, self::withoutChoice(rows: $rows, choiceId: $choice['choiceId']));
		}

		return $siblings;
	}//end forChoice()

	/**
	 * The filters for the plan's SubjectChoice rows in one lifecycle state, within the tenant when it is known.
	 *
	 * @param array{curriculumPlanId: string, tenantId: string, choiceId: string} $choice    The plan, tenant and choice.
	 * @param string                                                              $lifecycle The occupying state.
	 *
	 * @return array<string,string>
	 */
	private static function filters(array $choice, string $lifecycle): array {
		$filters = [
			'curriculumPlanId' => $choice['curriculumPlanId'],
			'lifecycle' => $lifecycle,
		];
		if ($choice['tenantId'] !== '') {
			$filters['tenant_id'] = $choice['tenantId'];
		}

		return array_merge($filters, self::SOURCE);
	}//end filters()

	/**
	 * The rows as arrays, without the SubjectChoice being validated.
	 *
	 * @param array<int,mixed> $rows     The rows read.
	 * @param string           $choiceId The SubjectChoice being validated.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function withoutChoice(array $rows, string $choiceId): array {
		$siblings = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			if ($id !== '' && $id === $choiceId) {
				continue;
			}

			$siblings[] = $row;
		}

		return $siblings;
	}//end withoutChoice()
}//end class
