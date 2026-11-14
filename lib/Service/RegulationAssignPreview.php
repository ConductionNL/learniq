<?php

/**
 * Learniq Regulation Assign Preview
 *
 * What assigning a regulation to one audience would do, before it is done:
 * the audiences the regulation's learners fall into (per section, team and
 * HR function), and for a chosen audience how many learners it holds, how
 * many are already enrolled in every course of the regulation and the first
 * rows with their outcome. Nothing is written.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-the-assignment-is-previewed-per-audience
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\RegulationAssignPreviewInterface;

/**
 * Read-only preview of an audience-narrowed regulation assignment.
 */
class RegulationAssignPreview implements RegulationAssignPreviewInterface {

	/**
	 * Rows returned with a preview; the counts cover every learner.
	 */
	public const ROW_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param RegulationAssignmentService $assignment The assignment the preview mirrors.
	 * @param RegulationAudienceResolver  $audience   Audience values per profile.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegulationAssignmentService $assignment,
		private readonly RegulationAudienceResolver $audience,
	) {
	}//end __construct()

	/**
	 * The preview of assigning the regulation, optionally to one audience.
	 *
	 * @param array<string,mixed>                     $regulation The published Regulation object.
	 * @param array{kind: string, value: string}|null $audience   The chosen audience, or null for the audience lists only.
	 *
	 * @return array<string,mixed> { courseName, audiences } plus { total, alreadyEnrolled, toEnrol, rows } once an audience is chosen.
	 *
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-the-assignment-is-previewed-per-audience
	 */
	public function preview(array $regulation, ?array $audience=null): array {
		$courses = $this->assignment->publishedCourses(regulation: $regulation);
		$preview = [
			'courseName' => $this->courseName(courses: $courses),
			'audiences' => $this->audiences(learners: $this->assignment->learnersInScope(regulation: $regulation)),
		];
		if ($audience === null) {
			return $preview;
		}

		$enrolledPerCourse = array_map(
			fn (array $course): array => $this->assignment->liveEnrolledUsers(courseId: (string)($course['id'] ?? ($course['uuid'] ?? ''))),
			$courses
		);
		$rows = [];
		foreach ($this->assignment->learnersInScope(regulation: $regulation, audience: $audience) as $learner) {
			$rows[] = $this->row(learner: $learner, enrolledPerCourse: $enrolledPerCourse);
		}

		$enrolled = count(array_filter($rows, static fn (array $row): bool => $row['outcome'] === 'enrolled'));

		return $preview + [
			'total' => count($rows),
			'alreadyEnrolled' => $enrolled,
			'toEnrol' => count($rows) - $enrolled,
			'rows' => array_slice($rows, 0, self::ROW_LIMIT),
		];
	}//end preview()

	/**
	 * The distinct audiences of the learners, per kind, sorted by label.
	 *
	 * @param array<int,array<string,mixed>> $learners The learners in scope.
	 *
	 * @return array<string,list<array{value: string, label: string}>>
	 */
	private function audiences(array $learners): array {
		$audiences = [];
		foreach (RegulationAudienceResolver::AUDIENCE_KINDS as $kind) {
			$values = [];
			foreach ($learners as $learner) {
				foreach ($this->audience->audienceValues(kind: $kind, profile: $learner) as $value) {
					$values[$value] = ['value' => $value, 'label' => $value];
				}
			}

			ksort($values);
			$audiences[$kind] = array_values($values);
		}

		return $audiences;
	}//end audiences()

	/**
	 * One preview row: the learner and whether they are enrolled in every course.
	 *
	 * @param array<string,mixed>            $learner           The LearnerProfile.
	 * @param array<int,array<string,bool>>  $enrolledPerCourse Enrolled user ids per course.
	 *
	 * @return array{id: string, name: string, role: string, outcome: string}
	 */
	private function row(array $learner, array $enrolledPerCourse): array {
		$userId = (string)$learner['ncUserId'];
		$missing = array_filter($enrolledPerCourse, static fn (array $enrolled): bool => isset($enrolled[$userId]) === false);
		$name = trim((string)($learner['fullName'] ?? ''));
		if ($name === '') {
			$name = trim((string)($learner['givenName'] ?? '') . ' ' . (string)($learner['familyName'] ?? ''));
		}

		$outcome = 'enrolled';
		if ($missing !== [] || $enrolledPerCourse === []) {
			$outcome = 'enrol';
		}

		return [
			'id' => (string)($learner['id'] ?? ($learner['uuid'] ?? '')),
			'name' => $name,
			'role' => (string)(($this->audience->audienceValues(kind: 'hr-function', profile: $learner)[0]) ?? ''),
			'outcome' => $outcome,
		];
	}//end row()

	/**
	 * The name of the regulation's course, with its code: "Name (CODE)".
	 *
	 * @param array<int,array<string,mixed>> $courses The published courses.
	 *
	 * @return string|null Null when the regulation has no published course.
	 */
	private function courseName(array $courses): ?string {
		if ($courses === []) {
			return null;
		}

		$name = (string)($courses[0]['name'] ?? '');
		$code = (string)($courses[0]['code'] ?? '');
		if ($code === '') {
			return $name;
		}

		return $name . ' (' . $code . ')';
	}//end courseName()
}//end class
