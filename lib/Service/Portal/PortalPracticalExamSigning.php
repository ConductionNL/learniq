<?php

/**
 * Learniq PortalPracticalExamSigning
 *
 * Lets an external assessor sign a submitted practical exam assessment from
 * the portal, as one of the two assessors the result needs.
 *
 * An assessor may only sign an assessment of a candidate at a sitting he
 * holds a live `ExamAssessorAssignment` for, only once, and only after the
 * assessment was submitted. The signature is a `Signature` with
 * `subjectKind: practical-exam-assessment` and `signerRole: assessor`; who
 * signed, when, at which assurance level and under which assignment are the
 * server's, read from the assertion and the assignment, never from the request.
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
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeInterface;
use OCA\Learniq\Kernel\Contracts\PortalPracticalExamSigningInterface;
use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Override;
use Throwable;

/**
 * Checks and stores an assessor's signature on a practical exam assessment.
 *
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
 */
class PortalPracticalExamSigning implements PortalPracticalExamSigningInterface {

	private const REGISTER = 'learniq';

	private const ASSESSMENT_SCHEMA = 'practical-exam-assessment';

	private const ASSIGNMENT_SCHEMA = 'exam-assessor-assignment';

	private const SIGNATURE_SCHEMA = 'signature';

	/**
	 * The subject kind a practical exam assessment is signed under.
	 */
	public const SUBJECT_KIND = 'practical-exam-assessment';

	/**
	 * The method a portal signature records: the signed portal assertion.
	 */
	public const METHOD = 'portal-assertion';

	/**
	 * The eIDAS ladder, as `Signature.assuranceLevel` spells it.
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
	 * Turns one OpenRegister result into a plain array.
	 *
	 * @var ObjectRow
	 */
	private readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the assessment and the assignment, writes the signature.
	 * @param IAppConfig      $appConfig     Holds the school's assurance floor.
	 * @param ITimeFactory    $timeFactory   Stamps when the signature was made.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * Sign a submitted practical exam assessment as the calling assessor.
	 *
	 * @param string $assessorRef  The `externalAssessorId` claim.
	 * @param string $trust        The session's trust (`low`, `substantial`, `high`).
	 * @param string $assessmentId The assessment to sign.
	 *
	 * @return PortalOutcome 201 with the signature; 403, 409, 422 or 502 otherwise.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
	 */
	public function sign(string $assessorRef, string $trust, string $assessmentId): PortalOutcome {
		return $this->guarded(
			trust: $trust,
			required: [$assessorRef, $assessmentId],
			call: fn (string $assurance): PortalOutcome => $this->signChecked(
				assessorRef: $assessorRef,
				assurance: $assurance,
				assessmentId: $assessmentId
			)
		);
	}//end sign()

	/**
	 * Sign the submitted assessment of a candidate at a sitting, as the calling assessor.
	 *
	 * This is the portal's signing action: the assessor picks the exam and
	 * the candidate from his own assignments, because the assessment itself
	 * was written by the other assessor and is not in his listings. Exactly
	 * one submitted assessment of that candidate at that sitting is signed;
	 * none answers 409 `not_submitted`, more than one 409 `several_assessments`,
	 * so a signature never lands on a guess.
	 *
	 * @param string $assessorRef The `externalAssessorId` claim.
	 * @param string $trust       The session's trust (`low`, `substantial`, `high`).
	 * @param string $sittingId   The exam sitting.
	 * @param string $learnerRef  The candidate.
	 *
	 * @return PortalOutcome 201 with the signature; 403, 409, 422 or 502 otherwise.
	 *
	 * @spec openspec/changes/practical-exam-assessment/tasks.md#t9
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
	 */
	#[Override]
	public function signForCandidate(string $assessorRef, string $trust, string $sittingId, string $learnerRef): PortalOutcome {
		return $this->guarded(
			trust: $trust,
			required: [$assessorRef, $sittingId, $learnerRef],
			call: fn (string $assurance): PortalOutcome => $this->signCandidateChecked(
				assessorRef: $assessorRef,
				assurance: $assurance,
				candidate: ['examSittingId' => $sittingId, 'learnerRef' => $learnerRef]
			)
		);
	}//end signForCandidate()

	/**
	 * The checks every signature starts with, then the call, with a failed write as 502.
	 *
	 * The school's floor refuses before anything is read, and an empty
	 * required value answers 422.
	 *
	 * @param string                           $trust    The session's trust.
	 * @param array<int, string>               $required The values that must not be empty.
	 * @param callable(string): PortalOutcome $call     The signing, given the session's assurance level.
	 *
	 * @return PortalOutcome The outcome.
	 */
	private function guarded(string $trust, array $required, callable $call): PortalOutcome {
		$assurance = (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
		$floor     = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(status: 403, body: ['error' => 'assurance_too_low', 'required' => $floor], reason: 'assurance-too-low');
		}

		if (in_array('', $required, true) === true) {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		try {
			return $call($assurance);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalPracticalExamSigning] A practical exam signature could not be stored: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}
	}//end guarded()

	/**
	 * Find the one submitted assessment of the candidate at the sitting, then sign it.
	 *
	 * @param string               $assessorRef The calling assessor.
	 * @param string               $assurance   The assurance level of his session.
	 * @param array<string, mixed> $candidate   `examSittingId` and `learnerRef`.
	 *
	 * @return PortalOutcome The outcome.
	 */
	private function signCandidateChecked(string $assessorRef, string $assurance, array $candidate): PortalOutcome {
		if ($this->assignmentFor(assessorRef: $assessorRef, assessment: $candidate) === null) {
			return new PortalOutcome(status: 403, body: ['error' => 'not_assigned'], reason: 'not-assigned');
		}

		$submitted = $this->all(schema: self::ASSESSMENT_SCHEMA, wanted: array_merge($candidate, ['lifecycle' => 'submitted']));
		if ($submitted === []) {
			return new PortalOutcome(status: 409, body: ['error' => 'not_submitted'], reason: 'not-submitted');
		}

		if (count($submitted) > 1) {
			return new PortalOutcome(status: 409, body: ['error' => 'several_assessments'], reason: 'several-assessments');
		}

		$assessmentId = $this->text(value: ($submitted[0]['id'] ?? ($submitted[0]['uuid'] ?? null)));

		return $this->signChecked(assessorRef: $assessorRef, assurance: $assurance, assessmentId: $assessmentId);
	}//end signCandidateChecked()

	/**
	 * Refuse what the assessor may not sign, then store his signature.
	 *
	 * An assessment that does not exist answers like one he is not assigned
	 * to, so the refusal tells nothing about other sittings.
	 *
	 * @param string $assessorRef  The calling assessor.
	 * @param string $assurance    The assurance level of his session.
	 * @param string $assessmentId The assessment to sign.
	 *
	 * @return PortalOutcome The outcome.
	 */
	private function signChecked(string $assessorRef, string $assurance, string $assessmentId): PortalOutcome {
		$assessment = $this->first(schema: self::ASSESSMENT_SCHEMA, wanted: ['id' => $assessmentId]);
		$assignment = $this->assignmentFor(assessorRef: $assessorRef, assessment: $assessment);
		if ($assessment === null || $assignment === null) {
			return new PortalOutcome(status: 403, body: ['error' => 'not_assigned'], reason: 'not-assigned');
		}

		if (($assessment['lifecycle'] ?? null) !== 'submitted') {
			return new PortalOutcome(status: 409, body: ['error' => 'not_submitted'], reason: 'not-submitted');
		}

		$own = ['subjectKind' => self::SUBJECT_KIND, 'subjectId' => $assessmentId, 'signerId' => $assessorRef];
		if ($this->first(schema: self::SIGNATURE_SCHEMA, wanted: $own) !== null) {
			return new PortalOutcome(status: 409, body: ['error' => 'already_signed'], reason: 'already-signed');
		}

		return $this->store(own: $own, context: ['assurance' => $assurance, 'assignment' => $assignment, 'assessment' => $assessment]);
	}//end signChecked()

	/**
	 * The assessor's live assignment for the assessment's candidate at its sitting, or null.
	 *
	 * @param string                    $assessorRef The calling assessor.
	 * @param array<string, mixed>|null $assessment  The assessment, null when it does not exist.
	 *
	 * @return array<string, mixed>|null The assignment.
	 */
	private function assignmentFor(string $assessorRef, ?array $assessment): ?array {
		if ($assessment === null) {
			return null;
		}

		return $this->first(
			schema: self::ASSIGNMENT_SCHEMA,
			wanted: [
				'externalAssessorId' => $assessorRef,
				'examSittingId' => ($assessment['examSittingId'] ?? null),
				'learnerRef' => ($assessment['learnerRef'] ?? null),
				'lifecycle' => 'assigned',
			]
		);
	}//end assignmentFor()

	/**
	 * Store the assessor's signature on the assessment and answer 201.
	 *
	 * @param array<string, mixed> $own     The subject and the signer.
	 * @param array<string, mixed> $context `assurance` (the assurance level of his session),
	 *                                      `assignment` (his assignment) and `assessment`.
	 *
	 * @return PortalOutcome The outcome.
	 */
	private function store(array $own, array $context): PortalOutcome {
		$assurance  = $context['assurance'];
		$assignment = $context['assignment'];
		$assessment = $context['assessment'];
		$signedAt = $this->timeFactory->getDateTime()->format(DateTimeInterface::ATOM);
		$saved    = $this->objectService->saveObject(
			object: array_merge(
				$own,
				[
					'subjectVersion' => 1,
					'signerRole' => 'assessor',
					'signedAt' => $signedAt,
					'assuranceLevel' => $assurance,
					'method' => self::METHOD,
					'evidenceRef' => 'exam-assessor-assignment:'.$this->text(value: ($assignment['id'] ?? null)),
					'tenant_id' => $this->text(value: ($assessment['tenant_id'] ?? null)),
				]
			),
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::SIGNATURE_SCHEMA)),
			_rbac: false,
			_multitenancy: false
		);

		return new PortalOutcome(
			status: 201,
			body: [
				'signatureId' => $this->text(value: ($this->objectRow->asArray(object: $saved)['id'] ?? null)),
				'role' => $this->text(value: ($assignment['role'] ?? null)),
				'assuranceLevel' => $assurance,
				'signedAt' => $signedAt,
			]
		);
	}//end store()

	/**
	 * The first row of a schema whose fields hold exactly the wanted values.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $wanted Field => value; `id` matches the object id.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	private function first(string $schema, array $wanted): ?array {
		$rows = $this->all(schema: $schema, wanted: $wanted);

		return ($rows[0] ?? null);
	}//end first()

	/**
	 * Every row of a schema whose fields hold exactly the wanted values.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $wanted Field => value; `id` matches the object id.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function all(string $schema, array $wanted): array {
		$filters = array_merge(['register' => self::REGISTER, 'schema' => $schema], $wanted);
		$config  = ['filters' => $filters, 'limit' => 10];
		if (isset($wanted['id']) === true) {
			unset($config['filters']['id']);
			$config['ids'] = [$wanted['id']];
		}

		$objects = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);
		$rows    = [];
		foreach ($objects as $object) {
			$row = $this->objectRow->asArray(object: $object);
			if ($this->holds(row: $row, wanted: $wanted) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end all()

	/**
	 * Whether a row holds every wanted value; the store's filter is not trusted alone.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $wanted Field => value.
	 *
	 * @return bool True when every field matches.
	 */
	private function holds(array $row, array $wanted): bool {
		foreach ($wanted as $field => $value) {
			$have = ($row[$field] ?? null);
			if ($field === 'id') {
				$have = ($row['id'] ?? ($row['uuid'] ?? null));
			}

			if ($value === null || $have !== $value) {
				return false;
			}
		}

		return true;
	}//end holds()

	/**
	 * The school's assurance floor for practical exams, `basic` when unset or unknown.
	 *
	 * @return string The floor.
	 */
	private function floor(): string {
		$declared = $this->appConfig->getValueString(PortalPracticalExamAssessment::APP_ID, PortalPracticalExamAssessment::MIN_ASSURANCE_KEY, 'basic');
		if (isset(self::ASSURANCE_ORDER[$declared]) === false) {
			return 'basic';
		}

		return $declared;
	}//end floor()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()
}//end class
