<?php

/**
 * Learniq Course Quality Score Rollup Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent, filtered to the
 * CourseEvaluationResponse schema's `submit` transition. Find-or-creates the
 * matching CourseQualityScore row for (courseId, teacherId, academicYear,
 * period), invokes CourseQualityScoreEvaluator, and persists the recomputed
 * responseCount/invitationCount/averageOverallScore/responseRate/
 * lastRecomputedAt.
 *
 * ADR-031 legitimate exception: "Lifecycle handler — event-to-object-write
 * bridge that cannot be expressed as a schema declaration." Mirrors
 * GradeRollupHandler's find-or-create-and-recompute shape exactly
 * (FinalGrade / GradeFormulaEvaluator). NOT a TimedJob (ADR-022).
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
 * @spec openspec/specs/course-evaluation/spec.md#requirement-course-teacher-quality-scores-are-a-declared-aggregation-and-calculation-engine-not-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\CourseQualityScoreEvaluator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Bridges CourseEvaluationResponse `submit` → CourseQualityScore find-or-create + recompute.
 *
 * @implements IEventListener<Event>
 */
class CourseQualityScoreRollupHandler implements IEventListener {

	use PicksFields;

	private const LEARNIQ_REGISTER = 'learniq';
	private const COURSE_EVALUATION_RESPONSE_SCHEMA = 'course-evaluation-response';
	private const COURSE_QUALITY_SCORE_SCHEMA = 'course-quality-score';

	/**
	 * The CourseQualityScore fields copied from CourseQualityScoreEvaluator::evaluate().
	 */
	private const SCORE_FIELDS = ['responseCount', 'invitationCount', 'averageOverallScore', 'responseRate', 'lastRecomputedAt'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param CourseQualityScoreEvaluator $evaluator Calculation engine.
	 * @param ListenerSchemaResolver $schemas Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CourseQualityScoreEvaluator $evaluator,
		private readonly ListenerSchemaResolver $schemas,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
	 */
	public function handle(Event $event): void {
		$response = $this->submittedResponse(event: $event);
		$scope = self::scopeOf(response: $response ?? []);
		if ($response === null || $scope === null) {
			return;
		}

		// The scope's keys are evaluate()'s parameter names.
		$result = $this->evaluator->evaluate(...$scope);
		$fields = $scope + self::pick(row: $result, keys: self::SCORE_FIELDS) + ['tenant_id' => $response['tenant_id'] ?? ''];

		$this->objectService->saveObject(
			object: array_merge($this->findExisting(scope: $scope) ?? [], $fields),
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::LEARNIQ_REGISTER, schema: self::COURSE_QUALITY_SCORE_SCHEMA)
			)
		);
	}//end handle()

	/**
	 * The data of a learniq CourseEvaluationResponse that was just submitted, or null for any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string,mixed>|null
	 */
	private function submittedResponse(Event $event): ?array {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return null;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER
			|| $this->schemas->eventSchema(event: $event) !== self::COURSE_EVALUATION_RESPONSE_SCHEMA
			|| $event->getTo() !== 'submitted'
		) {
			return null;
		}

		return $event->getObject()->jsonSerialize();
	}//end submittedResponse()

	/**
	 * The course, teacher (null for a course-level score), year and period a response scores, or null when one is missing.
	 *
	 * @param array<string,mixed> $response The response.
	 *
	 * @return array{courseId: string, teacherId: string|null, academicYear: string, period: string}|null
	 */
	private static function scopeOf(array $response): ?array {
		$scope = [
			'courseId' => $response['courseId'] ?? '',
			'teacherId' => $response['teacherId'] ?? null,
			'academicYear' => $response['academicYear'] ?? '',
			'period' => $response['period'] ?? '',
		];
		if ($scope['courseId'] === '' || $scope['academicYear'] === '' || $scope['period'] === '') {
			return null;
		}

		if ($scope['teacherId'] === '') {
			$scope['teacherId'] = null;
		}

		return $scope;
	}//end scopeOf()

	/**
	 * The stored CourseQualityScore row of the scope, if any.
	 *
	 * OR's filter matching treats a missing key as "any" rather than "null"
	 * for some drivers; an explicit null is still passed through so a
	 * course-level (teacherId:null) row is not confused with a teacher-scoped one.
	 *
	 * @param array{courseId: string, teacherId: string|null, academicYear: string, period: string} $scope The scope.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findExisting(array $scope): ?array {
		$filters = [
			'courseId' => $scope['courseId'],
			'academicYear' => $scope['academicYear'],
			'period' => $scope['period'],
			'teacherId' => $scope['teacherId'],
			'register' => self::LEARNIQ_REGISTER,
			'schema' => self::COURSE_QUALITY_SCORE_SCHEMA,
		];
		$existing = $this->objectService->findAll(['filters' => $filters, 'limit' => 1]);
		if (empty($existing) === true) {
			return null;
		}

		if (is_array($existing[0]) === false) {
			return $existing[0]->jsonSerialize();
		}

		return $existing[0];
	}//end findExisting()
}//end class
