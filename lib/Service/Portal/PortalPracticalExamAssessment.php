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
use OCA\Learniq\Support\ObjectRow;
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
		$refusal = $this->requestRefusal(assurance: $assurance, assessorRef: $assessorRef, pair: $pair);
		if ($refusal !== null) {
			return $refusal;
		}

		$lifecycle = 'submitted';
		if (($body['lifecycle'] ?? null) === 'draft') {
			$lifecycle = 'draft';
		}

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
			if ($existing !== null && ($existing['lifecycle'] ?? null) === 'submitted') {
				return new PortalOutcome(status: 409, body: ['error' => 'already_submitted'], reason: 'already-submitted');
			}

			$saved = $this->objectService->saveObject(
				object: $this->stamped(
					assessorRef: $assessorRef,
					assessor: $assessor,
					stamp: ['assurance' => $assurance, 'lifecycle' => $lifecycle, 'tenant' => $this->text(value: ($assignment['tenant_id'] ?? null))],
					body: $body
				),
				register: self::REGISTER,
				schema: self::ASSESSMENT_SCHEMA,
				uuid: $this->idOf(row: $existing),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalPracticalExamAssessment] A practical exam assessment could not be stored: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}//end try

		$status = 201;
		if ($existing !== null) {
			$status = 200;
		}

		return new PortalOutcome(
			status: $status,
			body: [
				'assessmentId' => $this->idOf(row: $this->toRow(object: $saved)),
				'assuranceLevel' => $assurance,
				'lifecycle' => $lifecycle,
			]
		);
	}//end submit()

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
		if ($assessorRef === '' || $sittingId === '' || $learnerRef === '') {
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

		if ($existing === null || ($existing['lifecycle'] ?? null) !== 'draft') {
			return new PortalOutcome(status: 200, body: ['draft' => null]);
		}

		$draft = ['assessmentId' => $this->idOf(row: $existing)];
		foreach (self::WRITABLE as $field) {
			$draft[$field] = ($existing[$field] ?? null);
		}

		return new PortalOutcome(status: 200, body: ['draft' => $draft]);
	}//end draft()

	/**
	 * Why the request is refused before any read: assurance below the floor, or an incomplete request.
	 *
	 * @param string                $assurance   The caller's assurance level.
	 * @param string                $assessorRef The assessor.
	 * @param array<string, string> $pair        The sitting and the candidate.
	 *
	 * @return PortalOutcome|null Null when the request may proceed.
	 */
	private function requestRefusal(string $assurance, string $assessorRef, array $pair): ?PortalOutcome {
		$floor = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(status: 403, body: ['error' => 'assurance_too_low', 'required' => $floor], reason: 'assurance-too-low');
		}

		if ($assessorRef === '' || $pair['sittingId'] === '' || $pair['learnerRef'] === '') {
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
	 * @param string               $assessorRef The assessor's uuid.
	 * @param array<string, mixed> $assessor    The ExternalAssessor record.
	 * @param array<string, string> $stamp      assurance, lifecycle and tenant.
	 * @param array<string, mixed> $body        What the form sent.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
	 */
	private function stamped(string $assessorRef, array $assessor, array $stamp, array $body): array {
		$object = [];
		foreach (self::WRITABLE as $field) {
			if (array_key_exists($field, $body) === true) {
				$object[$field] = $body[$field];
			}
		}

		$name = trim($this->text(value: ($assessor['givenName'] ?? null)).' '.$this->text(value: ($assessor['familyName'] ?? null)));

		return array_merge(
			$object,
			[
				'assessorId' => $assessorRef,
				'assessorName' => $this->orNull(value: $name),
				'assuranceLevel' => $stamp['assurance'],
				'lifecycle' => $stamp['lifecycle'],
				'tenant_id' => $this->orNull(value: $stamp['tenant']),
			]
		);
	}//end stamped()

	/**
	 * The assurance a school demands before it accepts an assessment.
	 *
	 * @return string One of the eIDAS levels; `basic` when unset or unknown.
	 */
	private function floor(): string {
		$declared = $this->appConfig->getValueString(self::APP_ID, self::MIN_ASSURANCE_KEY, 'basic');
		if (isset(self::ASSURANCE_ORDER[$declared]) === false) {
			return 'basic';
		}

		return $declared;
	}//end floor()

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

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		return $this->objectRow->asArray(object: $object);
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

	/**
	 * The text, or null when it is empty.
	 *
	 * @param string $value The text.
	 *
	 * @return string|null
	 */
	private function orNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end class
