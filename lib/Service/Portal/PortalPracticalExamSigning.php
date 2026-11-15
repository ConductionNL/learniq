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
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
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
		$assurance = (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
		$floor     = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(status: 403, body: ['error' => 'assurance_too_low', 'required' => $floor], reason: 'assurance-too-low');
		}

		if ($assessorRef === '' || $assessmentId === '') {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		try {
			return $this->signChecked(assessorRef: $assessorRef, assurance: $assurance, assessmentId: $assessmentId);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalPracticalExamSigning] A practical exam signature could not be stored: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}
	}//end sign()

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
		$assignment = null;
		if ($assessment !== null) {
			$assignment = $this->first(
				schema: self::ASSIGNMENT_SCHEMA,
				wanted: [
					'externalAssessorId' => $assessorRef,
					'examSittingId' => ($assessment['examSittingId'] ?? null),
					'learnerRef' => ($assessment['learnerRef'] ?? null),
					'lifecycle' => 'assigned',
				]
			);
		}

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
			register: self::REGISTER,
			schema: self::SIGNATURE_SCHEMA,
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
	}//end signChecked()

	/**
	 * The first row of a schema whose fields hold exactly the wanted values.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $wanted Field => value; `id` matches the object id.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	private function first(string $schema, array $wanted): ?array {
		$filters = array_merge(['register' => self::REGISTER, 'schema' => $schema], $wanted);
		$config  = ['filters' => $filters, 'limit' => 10];
		if (isset($wanted['id']) === true) {
			unset($config['filters']['id']);
			$config['ids'] = [$wanted['id']];
		}

		$objects = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);
		foreach ($objects as $object) {
			$row = $this->objectRow->asArray(object: $object);
			if ($this->holds(row: $row, wanted: $wanted) === true) {
				return $row;
			}
		}

		return null;
	}//end first()

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
