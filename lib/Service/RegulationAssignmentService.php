<?php

/**
 * Learniq Regulation Assignment Service
 *
 * Assigns a regulation's mandatory courses to the learners its audience scope
 * covers (learniq#951). For every published Course bound to the regulation
 * (`Course.regulationSlug`) and every active LearnerProfile the regulation
 * covers (department, role or everyone, see RegulationAudienceResolver), a
 * mandatory Enrolment is created unless the learner already holds a live one
 * for that course. Learners outside the scope get nothing.
 *
 * Runs when a regulation is published (RegulationAssignmentHandler) and on
 * demand through RegulationAssignmentController, so learners who join a
 * department or gain a role later can be picked up.
 *
 * ADR-031 legitimate exception: a cross-object write (Regulation to many
 * Enrolments) that no declarative schema expression covers.
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
 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\RegulationAssignmentServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Creates mandatory enrolments for the learners a regulation covers.
 */
class RegulationAssignmentService implements RegulationAssignmentServiceInterface {

	private const LEARNIQ_REGISTER = 'learniq';
	private const PAGE_LIMIT = 10000;

	/**
	 * Enrolment states that no longer hold the learner in the course; a learner
	 * whose only enrolment is in one of these is assigned again.
	 */
	private const ENDED_STATES = ['withdrawn', 'failed'];

	/**
	 * Reads the rows of one learniq schema.
	 *
	 * @var SchemaRows
	 */
	private readonly SchemaRows $schemaRows;

	/**
	 * Constructor.
	 *
	 * @param ObjectService              $objectService OR object access.
	 * @param RegulationAudienceResolver $audience      Audience-scope predicate.
	 * @param LoggerInterface            $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly RegulationAudienceResolver $audience,
		private readonly LoggerInterface $logger,
	) {
		$this->schemaRows = new SchemaRows(objectService: $objectService, limit: self::PAGE_LIMIT);
	}//end __construct()

	/**
	 * Assign the regulation's mandatory courses to its audience, or to one
	 * part of it.
	 *
	 * @param array<string,mixed>                     $regulation The Regulation object.
	 * @param array{kind: string, value: string}|null $audience   One audience to narrow to (see
	 *                                                            RegulationAudienceResolver::AUDIENCE_KINDS), or null for all.
	 * @param string|null                             $dueDate    A due date (Y-m-d) for the enrolments it creates.
	 *
	 * @return array{created: int, skipped: int, inScope: int, courses: int}
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-an-assignment-can-be-narrowed-to-one-audience
	 */
	public function assign(array $regulation, ?array $audience=null, ?string $dueDate=null): array {
		$result = ['created' => 0, 'skipped' => 0, 'inScope' => 0, 'courses' => 0];
		$slug = (string)($regulation['slug'] ?? '');
		if ($slug === '' || ($regulation['active'] ?? true) === false) {
			return $result;
		}

		$courses = $this->publishedCourses(regulation: $regulation);
		$result['courses'] = count($courses);

		$learners = $this->learnersInScope(regulation: $regulation, audience: $audience);
		$result['inScope'] = count($learners);

		foreach ($courses as $course) {
			$courseId = (string)($course['id'] ?? ($course['uuid'] ?? ''));
			$enrolled = $this->liveEnrolledUsers(courseId: $courseId);
			foreach ($learners as $learner) {
				$userId = (string)$learner['ncUserId'];
				if (isset($enrolled[$userId]) === true) {
					$result['skipped']++;
					continue;
				}

				$enrolment = [
					'learnerId' => $userId,
					'learnerRef' => (string)($learner['id'] ?? ($learner['uuid'] ?? '')),
					'courseId' => $courseId,
					'mandatory' => true,
					'regulationSlug' => $slug,
					'source' => 'system',
					'managerId' => $learner['managerId'] ?? null,
					'tenant_id' => (string)($regulation['tenant_id'] ?? ($course['tenant_id'] ?? '')),
				];
				if ($dueDate !== null) {
					$enrolment['dueDate'] = $dueDate;
				}

				$this->objectService->saveObject(object: $enrolment, register: self::LEARNIQ_REGISTER, schema: 'enrolment');
				$enrolled[$userId] = true;
				$result['created']++;
			}//end foreach
		}//end foreach

		$this->logger->info(
			'[RegulationAssignmentService] Regulation {slug}: {created} enrolment(s) created, {skipped} already enrolled, {inScope} learner(s) in scope.',
			['slug' => $slug] + $result
		);

		return $result;
	}//end assign()

	/**
	 * The audience a request names: null for none, false when it is not valid.
	 *
	 * @param string $kind  section, team or hr-function; empty for none.
	 * @param string $value The audience value within that kind.
	 *
	 * @return array{kind: string, value: string}|false|null
	 *
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-an-assignment-can-be-narrowed-to-one-audience
	 */
	public function audienceOf(string $kind, string $value): array|false|null {
		$kind = trim($kind);
		$value = trim($value);
		if ($kind === '' && $value === '') {
			return null;
		}

		if (in_array($kind, RegulationAudienceResolver::AUDIENCE_KINDS, true) === false || $value === '') {
			return false;
		}

		return ['kind' => $kind, 'value' => $value];
	}//end audienceOf()

	/**
	 * The due date a request names: null for none, false when it is not a Y-m-d date.
	 *
	 * @param string $value The due date as sent.
	 *
	 * @return string|false|null
	 *
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-an-assignment-can-be-narrowed-to-one-audience
	 */
	public function dueDateOf(string $value): string|false|null {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
			|| checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false
		) {
			return false;
		}

		return $value;
	}//end dueDateOf()

	/**
	 * The published courses bound to the regulation.
	 *
	 * @param array<string,mixed> $regulation The Regulation object.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function publishedCourses(array $regulation): array {
		return array_values(
			array_filter(
				$this->rows(schema: 'course', filters: ['regulationSlug' => (string)($regulation['slug'] ?? '')]),
				static fn (array $course): bool => ($course['lifecycle'] ?? '') === 'published'
			)
		);
	}//end publishedCourses()

	/**
	 * The active learners with an account the regulation covers, narrowed to
	 * one audience when given.
	 *
	 * @param array<string,mixed>                     $regulation The Regulation object.
	 * @param array{kind: string, value: string}|null $audience   One audience, or null for all.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function learnersInScope(array $regulation, ?array $audience=null): array {
		return array_values(
			array_filter(
				$this->rows(schema: 'learner-profile'),
				fn (array $profile): bool => (string)($profile['ncUserId'] ?? '') !== ''
					&& $this->audience->covers(regulation: $regulation, profile: $profile) === true
					&& $this->inAudience(profile: $profile, audience: $audience) === true
			)
		);
	}//end learnersInScope()

	/**
	 * Whether a profile belongs to the audience; every profile does when none is given.
	 *
	 * @param array<string,mixed>                     $profile  The LearnerProfile.
	 * @param array{kind: string, value: string}|null $audience The audience, or null.
	 *
	 * @return bool
	 */
	private function inAudience(array $profile, ?array $audience): bool {
		if ($audience === null) {
			return true;
		}

		return in_array($audience['value'], $this->audience->audienceValues(kind: $audience['kind'], profile: $profile), true);
	}//end inAudience()

	/**
	 * Nextcloud user ids holding a live enrolment in the course.
	 *
	 * @param string $courseId Course UUID.
	 *
	 * @return array<string,bool>
	 */
	public function liveEnrolledUsers(string $courseId): array {
		$users = [];
		foreach ($this->rows(schema: 'enrolment', filters: ['courseId' => $courseId]) as $enrolment) {
			if (in_array($enrolment['lifecycle'] ?? 'pending', self::ENDED_STATES, true) === true) {
				continue;
			}

			$userId = (string)($enrolment['learnerId'] ?? '');
			if ($userId !== '') {
				$users[$userId] = true;
			}
		}

		return $users;
	}//end liveEnrolledUsers()

	/**
	 * Rows of a schema as arrays.
	 *
	 * @param string              $schema  Schema slug.
	 * @param array<string,mixed> $filters Field filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schema, array $filters=[]): array {
		return $this->schemaRows->serialised(schema: $schema, filters: $filters);
	}//end rows()
}//end class
