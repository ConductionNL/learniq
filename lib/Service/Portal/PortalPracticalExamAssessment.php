<?php

/**
 * Learniq PortalPracticalExamAssessment
 *
 * Writes the practical exam assessment an external assessor fills in from the
 * portal, and records who assessed and how sure the school is of that.
 *
 * An assessor may only assess a candidate at a sitting he holds a live
 * `ExamAssessorAssignment` for; anything else is refused before a write. The
 * form saves a draft on every step, on the same record (`lifecycle: draft`),
 * so a dropped connection loses nothing: the next save of that assessor for
 * that candidate at that sitting updates his draft, and `draft()` hands it
 * back to resume. A submitted assessment is final.
 *
 * Like the werkproces assessment of a workplace trainer, the write runs
 * through learniq's own endpoint: only a forwarded request carries the signed
 * assertion, and the assertion is where the sign-in level is read.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Kernel\Contracts\PortalPracticalExamAssessmentInterface;
use OCA\Learniq\Service\Portal\Support\AssessmentRowValues;
use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks, stamps and stores an assessor's practical exam assessment.
 *
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
 */
class PortalPracticalExamAssessment implements PortalPracticalExamAssessmentInterface {

	use AssessmentRowValues;

	private const REGISTER = 'learniq';

	private const ASSESSMENT_SCHEMA = 'practical-exam-assessment';

	private const ASSIGNMENT_SCHEMA = 'exam-assessor-assignment';

	private const ASSESSOR_SCHEMA = 'external-assessor';

	/**
	 * The app id whose config holds the school's floor.
	 */
	public const APP_ID = 'learniq';

	/**
	 * The config key a school raises to demand a stronger sign-in before a
	 * practical exam assessment is accepted. Default `basic`: an invited
	 * assessor signing in with the account the school gave him may assess.
	 */
	public const MIN_ASSURANCE_KEY = 'practical_exam_min_assurance';

	/**
	 * The eIDAS ladder, as `PracticalExamAssessment.assuranceLevel` spells it.
	 *
	 * @var array<string, int>
	 */
	private const ASSURANCE_ORDER = ['none' => 0, 'basic' => 1, 'substantial' => 2, 'high' => 3];

	/**
	 * What a portal session's trust means on that ladder.
	 *
	 * @var array<string, string>
	 */
	private const TRUST_TO_ASSURANCE = ['low' => 'basic', 'substantial' => 'substantial', 'high' => 'high'];

	/**
	 * The fields the assessor's form may send. Who assessed, how sure the school
	 * is of that, and the tenant are the server's.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE = ['examSittingId', 'learnerRef', 'assessmentModelId', 'criteria', 'outcome'];

	/**
	 * Turns one OpenRegister result into a plain array.
	 *
	 * @var ObjectRow
	 */
	private readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the assessor and his assignment, writes the assessment.
	 * @param IAppConfig      $appConfig     Holds the school's assurance floor.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * Save the assessor's assessment of one candidate at one sitting, as a draft or submitted.
	 *
	 * @param string               $assessorRef The `externalAssessorId` claim.
	 * @param string               $trust       The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed> $body        What the form sent; `lifecycle: draft` saves a step.
	 *
	 * @return PortalOutcome 201 on a new record, 200 on a draft carried on; 403, 409, 422 or 502 otherwise.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
	 */
	public function submit(string $assessorRef, string $trust, array $body): PortalOutcome {
		$assurance = (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
		$pair = ['sittingId' => $this->text(value: ($body['examSittingId'] ?? null)), 'learnerRef' => $this->text(value: ($body['learnerRef'] ?? null))];
		$refusal = $this->requestRefusal(assurance: $assurance, required: [$assessorRef, $pair['sittingId'], $pair['learnerRef']]);
		if ($refusal !== null) {
			return $refusal;
		}

		$lifecycle = 'submitted';
		if (($body['lifecycle'] ?? null) === 'draft') {
			$lifecycle = 'draft';
		}

		return $this->store(
			assessorRef: $assessorRef,
			request: ['assurance' => $assurance, 'lifecycle' => $lifecycle, 'pair' => $pair, 'body' => $body]
		);
	}//end submit()

	/**
	 * Store an assessment the request checks let through: the assessor must be
	 * known and assigned, and a submitted assessment is never stored again.
	 *
	 * @param string               $assessorRef The `externalAssessorId` claim.
	 * @param array<string, mixed> $request     `assurance`, `lifecycle`, `pair` (sitting and candidate) and `body`.
	 *
	 * @return PortalOutcome 201 or 200 `{assessmentId, assuranceLevel, lifecycle}`, or 403, 409 or 502.
	 */
	private function store(string $assessorRef, array $request): PortalOutcome {
		$pair = $request['pair'];
		try {
			$assessor = $this->row(schema: self::ASSESSOR_SCHEMA, id: $assessorRef);
			if ($assessor === null) {
				return new PortalOutcome(status: 403, body: ['error' => 'unknown_assessor'], reason: 'unknown-assessor');
			}

			$assignment = $this->assignmentOf(assessorRef: $assessorRef, pair: $pair);
			if ($assignment === null) {
				return new PortalOutcome(status: 403, body: ['error' => 'not_assigned'], reason: 'not-assigned');
			}

			$existing = $this->ownAssessment(assessorRef: $assessorRef, pair: $pair);
			if (self::hasLifecycle(row: $existing, lifecycle: 'submitted') === true) {
				return new PortalOutcome(status: 409, body: ['error' => 'already_submitted'], reason: 'already-submitted');
			}

			$saved = $this->save(
				assessor: $assessor,
				stamp: [
					'assessorRef' => $assessorRef,
					'assurance' => $request['assurance'],
					'lifecycle' => $request['lifecycle'],
					'tenant' => $this->text(value: ($assignment['tenant_id'] ?? null)),
					'body' => $request['body'],
					'existing' => $existing,
				]
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalPracticalExamAssessment] A practical exam assessment could not be stored: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}//end try

		return $this->stored(saved: $saved, request: array_merge($request, ['created' => $existing === null]));
	}//end store()

	/**
	 * Save the stamped assessment, over the assessor's own earlier one when there is one.
	 *
	 * @param array<string, mixed> $assessor The assessor.
	 * @param array<string, mixed> $stamp    What the portal stamps on the body, with `existing` (the assessor's own earlier assessment, or null).
	 *
	 * @return mixed The saved object.
	 */
	private function save(array $assessor, array $stamp): mixed {
		return $this->objectService->saveObject(
			object: $this->stamped(assessor: $assessor, stamp: $stamp),
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: self::ASSESSMENT_SCHEMA),
				uuid: $this->idOf(row: $stamp['existing'])
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * The answer to a stored assessment: 201 when it is new, 200 when it replaced a draft.
	 *
	 * @param mixed                $saved   The saved object.
	 * @param array<string, mixed> $request The request, with `created` (whether the assessment is new).
	 *
	 * @return PortalOutcome
	 */
	private function stored(mixed $saved, array $request): PortalOutcome {
		$status = 200;
		if ($request['created'] === true) {
			$status = 201;
		}

		return new PortalOutcome(
			status: $status,
			body: [
				'assessmentId' => $this->idOf(row: $this->toRow(object: $saved)),
				'assuranceLevel' => $request['assurance'],
				'lifecycle' => $request['lifecycle'],
			]
		);
	}//end stored()

	/**
	 * Whether a row exists and is in the given lifecycle.
	 *
	 * @param array<string, mixed>|null $row       The row.
	 * @param string                    $lifecycle The lifecycle.
	 *
	 * @return bool
	 */
	private static function hasLifecycle(?array $row, string $lifecycle): bool {
		return $row !== null && ($row['lifecycle'] ?? null) === $lifecycle;
	}//end hasLifecycle()

	/**
	 * Whether any of the given values is empty.
	 *
	 * @param array<int, string> $values The values.
	 *
	 * @return bool
	 */
	private static function anyEmpty(array $values): bool {
		return in_array('', $values, true);
	}//end anyEmpty()

	/**
	 * The assessor's own draft for one candidate at one sitting, to resume it.
	 *
	 * @param string $assessorRef The `externalAssessorId` claim.
	 * @param string $sittingId   The sitting.
	 * @param string $learnerRef  The candidate.
	 *
	 * @return PortalOutcome 200 `{draft}` (null when there is none), or 403, 422 or 502.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
	 */
	public function draft(string $assessorRef, string $sittingId, string $learnerRef): PortalOutcome {
		$pair = ['sittingId' => $sittingId, 'learnerRef' => $learnerRef];
		if (self::anyEmpty(values: [$assessorRef, $sittingId, $learnerRef]) === true) {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		try {
			if ($this->assignmentOf(assessorRef: $assessorRef, pair: $pair) === null) {
				return new PortalOutcome(status: 403, body: ['error' => 'not_assigned'], reason: 'not-assigned');
			}

			$existing = $this->ownAssessment(assessorRef: $assessorRef, pair: $pair);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalPracticalExamAssessment] A draft could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}

		return new PortalOutcome(status: 200, body: ['draft' => $this->draftOf(existing: $existing)]);
	}//end draft()

	/**
	 * The resumable draft of an own assessment, or null when it is none.
	 *
	 * @param array<string, mixed>|null $existing The assessor's own assessment.
	 *
	 * @return array<string, mixed>|null The draft.
	 */
	private function draftOf(?array $existing): ?array {
		if (self::hasLifecycle(row: $existing, lifecycle: 'draft') === false) {
			return null;
		}

		$draft = ['assessmentId' => $this->idOf(row: $existing)];
		foreach (self::WRITABLE as $field) {
			$draft[$field] = ($existing[$field] ?? null);
		}

		return $draft;
	}//end draftOf()

	/**
	 * Why the request is refused before any read: assurance below the floor, or an incomplete request.
	 *
	 * @param string             $assurance The caller's assurance level.
	 * @param array<int, string> $required  The assessor, the sitting and the candidate: none may be empty.
	 *
	 * @return PortalOutcome|null Null when the request may proceed.
	 */
	private function requestRefusal(string $assurance, array $required): ?PortalOutcome {
		$floor = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(status: 403, body: ['error' => 'assurance_too_low', 'required' => $floor], reason: 'assurance-too-low');
		}

		if (self::anyEmpty(values: $required) === true) {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		return null;
	}//end requestRefusal()

	/**
	 * The assessor's live assignment for this candidate at this sitting, or null.
	 *
	 * The read is filtered, and every row is checked again here: a filter the
	 * store ignored must not grant an assessment.
	 *
	 * @param string                $assessorRef The assessor.
	 * @param array<string, string> $pair        The sitting and the candidate.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
	 */
	private function assignmentOf(string $assessorRef, array $pair): ?array {
		$wanted = [
			'externalAssessorId' => $assessorRef,
			'examSittingId' => $pair['sittingId'],
			'learnerRef' => $pair['learnerRef'],
			'lifecycle' => 'assigned',
		];

		return $this->first(schema: self::ASSIGNMENT_SCHEMA, wanted: $wanted);
	}//end assignmentOf()

	/**
	 * The assessor's own assessment of this candidate at this sitting, or null.
	 *
	 * @param string                $assessorRef The assessor.
	 * @param array<string, string> $pair        The sitting and the candidate.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownAssessment(string $assessorRef, array $pair): ?array {
		$wanted = ['assessorId' => $assessorRef, 'examSittingId' => $pair['sittingId'], 'learnerRef' => $pair['learnerRef']];

		return $this->first(schema: self::ASSESSMENT_SCHEMA, wanted: $wanted);
	}//end ownAssessment()

	/**
	 * The first row of a schema whose fields all equal the wanted values, or null.
	 *
	 * @param string                $schema The schema slug.
	 * @param array<string, string> $wanted Field => value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(string $schema, array $wanted): ?array {
		$objects = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $wanted), 'limit' => 10],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = $this->toRow(object: $object);
			if ($this->holds(row: $row, wanted: $wanted) === true) {
				return $row;
			}
		}

		return null;
	}//end first()

	/**
	 * Whether every wanted field of a row holds exactly the wanted value.
	 *
	 * @param array<string, mixed>  $row    The row.
	 * @param array<string, string> $wanted Field => value.
	 *
	 * @return bool
	 */
	private function holds(array $row, array $wanted): bool {
		foreach ($wanted as $field => $value) {
			if (($row[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end holds()

	/**
	 * The assessment as it is stored: the assessor's own fields, then who
	 * assessed, how sure the school is, the lifecycle and the tenant. A client
	 * value for any of the server's fields is dropped, never merged.
	 *
	 * @param array<string, mixed> $assessor The ExternalAssessor record.
	 * @param array<string, mixed> $stamp    `assessorRef` (the assessor's uuid), `assurance`, `lifecycle`, `tenant` and `body` (what the form sent).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
	 */
	private function stamped(array $assessor, array $stamp): array {
		$object = [];
		foreach (self::WRITABLE as $field) {
			if (array_key_exists($field, $stamp['body']) === true) {
				$object[$field] = $stamp['body'][$field];
			}
		}

		$name = trim($this->text(value: ($assessor['givenName'] ?? null)).' '.$this->text(value: ($assessor['familyName'] ?? null)));

		return array_merge(
			$object,
			[
				'assessorId' => $stamp['assessorRef'],
				'assessorName' => $this->orNull(value: $name),
				'assuranceLevel' => $stamp['assurance'],
				'lifecycle' => $stamp['lifecycle'],
				'tenant_id' => $this->orNull(value: $stamp['tenant']),
			]
		);
	}//end stamped()

	/**
	 * One learniq row by uuid, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function row(string $schema, string $id): ?array {
		$objects = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'ids' => [$id], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = $this->toRow(object: $object);
			if ($this->idOf(row: $row) === $id) {
				return $row;
			}
		}

		return null;
	}//end row()

	/**
	 * A row's uuid, or null.
	 *
	 * @param array<string, mixed>|null $row The row.
	 *
	 * @return string|null
	 */
	private function idOf(?array $row): ?string {
		if ($row === null) {
			return null;
		}

		return $this->orNull(value: $this->text(value: ($row['id'] ?? ($row['uuid'] ?? null))));
	}//end idOf()
}//end class
