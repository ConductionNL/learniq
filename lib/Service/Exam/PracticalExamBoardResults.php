<?php

/**
 * Learniq PracticalExamBoardResults
 *
 * The practical exam results the exam board reads: an assessment reaches the
 * board only once both its first and its second assessor have signed it.
 *
 * Who the first and the second assessor are comes from the live
 * `ExamAssessorAssignment` rows of that candidate at that sitting, not from the
 * signatures themselves, so a third person's signature never completes a pair.
 * Every read runs with the caller's own rights: a caller who may not read
 * practical exam assessments gets nothing.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Exam
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

namespace OCA\Learniq\Service\Exam;

use OCA\Learniq\Kernel\Contracts\PracticalExamBoardResultsInterface;
use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Lists the co-signed practical exam results of a sitting.
 *
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
 */
class PracticalExamBoardResults implements PracticalExamBoardResultsInterface {

	private const REGISTER = 'learniq';

	private const PAGE = 200;

	/**
	 * The two roles a result needs a signature from.
	 *
	 * @var array<int, string>
	 */
	private const ROLES = ['first', 'second'];

	/**
	 * Turns one OpenRegister result into a plain array.
	 *
	 * @var ObjectRow
	 */
	private readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads assessments, assignments and signatures with the caller's rights.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * The practical exam results of one sitting that both assessors signed.
	 *
	 * @param string $sittingId The exam sitting.
	 *
	 * @return array<int, array<string, mixed>> One row per co-signed assessment, with both signatures.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
	 */
	public function forSitting(string $sittingId): array {
		if ($sittingId === '') {
			return [];
		}

		$assessors = $this->assessorsByCandidate(sittingId: $sittingId);
		$results   = [];
		foreach ($this->rows(schema: 'practical-exam-assessment', filters: ['examSittingId' => $sittingId, 'lifecycle' => 'submitted']) as $assessment) {
			if (($assessment['examSittingId'] ?? null) !== $sittingId || ($assessment['lifecycle'] ?? null) !== 'submitted') {
				continue;
			}

			$pair       = ($assessors[(string)($assessment['learnerRef'] ?? '')] ?? []);
			$signatures = $this->pairSignatures(assessmentId: $this->idOf(row: $assessment), pair: $pair);
			if ($signatures === null) {
				continue;
			}

			$results[] = [
				'assessmentId' => $this->idOf(row: $assessment),
				'examSittingId' => $sittingId,
				'learnerRef' => ($assessment['learnerRef'] ?? null),
				'assessmentModelId' => ($assessment['assessmentModelId'] ?? null),
				'criteria' => ($assessment['criteria'] ?? []),
				'outcome' => ($assessment['outcome'] ?? null),
				'assessorName' => ($assessment['assessorName'] ?? null),
				'signatures' => $signatures,
			];
		}//end foreach

		return $results;
	}//end forSitting()

	/**
	 * Per candidate of the sitting, the assessor holding each live role.
	 *
	 * @param string $sittingId The sitting.
	 *
	 * @return array<string, array<string, string>> learnerRef => [role => externalAssessorId].
	 */
	private function assessorsByCandidate(string $sittingId): array {
		$byCandidate = [];
		foreach ($this->rows(schema: 'exam-assessor-assignment', filters: ['examSittingId' => $sittingId, 'lifecycle' => 'assigned']) as $assignment) {
			$learner  = ($assignment['learnerRef'] ?? null);
			$role     = ($assignment['role'] ?? null);
			$assessor = ($assignment['externalAssessorId'] ?? null);
			if (($assignment['examSittingId'] ?? null) !== $sittingId || ($assignment['lifecycle'] ?? null) !== 'assigned'
				|| is_string($learner) === false || in_array($role, self::ROLES, true) === false || is_string($assessor) === false
			) {
				continue;
			}

			$byCandidate[$learner][$role] = $assessor;
		}

		return $byCandidate;
	}//end assessorsByCandidate()

	/**
	 * The first and the second assessor's signatures on one assessment, or null while either is missing.
	 *
	 * @param string                $assessmentId The assessment.
	 * @param array<string, string> $pair         Role => the assessor holding it.
	 *
	 * @return array<int, array<string, mixed>>|null Both signatures, first then second.
	 */
	private function pairSignatures(string $assessmentId, array $pair): ?array {
		if ($assessmentId === '' || count($pair) !== count(self::ROLES)) {
			return null;
		}

		$bySigner = [];
		foreach ($this->rows(schema: 'signature', filters: ['subjectKind' => 'practical-exam-assessment', 'subjectId' => $assessmentId]) as $signature) {
			if (($signature['subjectKind'] ?? null) === 'practical-exam-assessment' && ($signature['subjectId'] ?? null) === $assessmentId
				&& ($signature['signerRole'] ?? null) === 'assessor' && is_string($signature['signerId'] ?? null) === true
			) {
				$bySigner[$signature['signerId']] = $signature;
			}
		}

		$signatures = [];
		foreach (self::ROLES as $role) {
			$signature = ($bySigner[$pair[$role]] ?? null);
			if ($signature === null) {
				return null;
			}

			$signatures[] = [
				'role' => $role,
				'signerId' => $pair[$role],
				'signedAt' => ($signature['signedAt'] ?? null),
				'assuranceLevel' => ($signature['assuranceLevel'] ?? null),
			];
		}

		return $signatures;
	}//end pairSignatures()

	/**
	 * The rows of one schema matching the filters, read with the caller's rights.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Field => value.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(string $schema, array $filters): array {
		$objects = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => self::PAGE],
			_rbac: true
		);

		$rows = [];
		foreach ($objects as $object) {
			$rows[] = $this->objectRow->asArray(object: $object);
		}

		return $rows;
	}//end rows()

	/**
	 * The id of a row, '' when it has none.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id.
	 */
	private function idOf(array $row): string {
		$id = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()
}//end class
