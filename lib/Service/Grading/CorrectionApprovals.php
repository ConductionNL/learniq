<?php

/**
 * Learniq Correction Approvals
 *
 * Finds the approved DataCorrectionRequest that lets a grade entry in a
 * locked report period be published again. The report period lock guard
 * asks it before the publish; the applied handler asks it after, to mark
 * the request applied. Both ask the same question, so they share this class.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Grading
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Grading;

use OCA\Learniq\Kernel\Contracts\CorrectionApprovalsInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The approved correction for one grade entry, if there is one.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */
class CorrectionApprovals implements CorrectionApprovalsInterface {

	private const REGISTER = 'learniq';

	private const SCHEMA = 'data-correction-request';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
	) {
	}//end __construct()

	/**
	 * The approved request that covers this publish of the grade entry.
	 *
	 * A request covers it when it names the entry, is approved, was decided
	 * by someone other than the person who asked, the person publishing is
	 * not the one who approved, and the entry carries the value that was
	 * approved. The lookup runs without the caller's read rights: the guard
	 * judges facts the publishing teacher may not be allowed to list.
	 *
	 * @param array<string, mixed> $entry     The grade entry at its target state.
	 * @param string               $publisher The uid of the person publishing.
	 *
	 * @return array<string, mixed>|null The request, or null when none covers the publish.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function approvedFor(array $entry, string $publisher): ?array {
		$publish = new CorrectionPublish(entry: $entry, publisher: $publisher);
		if ($publish->entryId === '' || $publisher === '') {
			return null;
		}

		return $this->firstCovering(rows: $this->approvedRequests(entryId: $publish->entryId), publish: $publish);
	}//end approvedFor()

	/**
	 * The approved requests that name the grade entry, read without the
	 * caller's read rights.
	 *
	 * @param string $entryId The grade entry uuid.
	 *
	 * @return array<int, mixed> The stored requests.
	 */
	private function approvedRequests(string $entryId): array {
		return $this->objects->findAll(
			config: [
				'filters' => [
					'register'     => self::REGISTER,
					'schema'       => self::SCHEMA,
					'gradeEntryId' => $entryId,
					'lifecycle'    => 'approved',
				],
				'limit'   => 50,
			],
			_rbac: false
		);
	}//end approvedRequests()

	/**
	 * The first request that covers the publish, in stored order.
	 *
	 * @param array<int, mixed> $rows    The stored requests.
	 * @param CorrectionPublish $publish The publish being judged.
	 *
	 * @return array<string, mixed>|null The request, or null when none covers it.
	 */
	private function firstCovering(array $rows, CorrectionPublish $publish): ?array {
		foreach ($rows as $row) {
			$request = $this->asArray(row: $row);
			if ($this->covers(request: $request, publish: $publish) === true) {
				return $request;
			}
		}

		return null;
	}//end firstCovering()

	/**
	 * A stored request as an array.
	 *
	 * @param mixed $row The row: an array, or an object that serialises to one.
	 *
	 * @return array<string, mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return $row->jsonSerialize();
	}//end asArray()

	/**
	 * Whether one request covers this publish.
	 *
	 * @param array<string, mixed> $request The correction request.
	 * @param CorrectionPublish    $publish The publish being judged.
	 *
	 * @return bool
	 */
	private function covers(array $request, CorrectionPublish $publish): bool {
		return ($request['lifecycle'] ?? null) === 'approved'
			&& (string)($request['gradeEntryId'] ?? '') === $publish->entryId
			&& $this->decidedByAnother(request: $request, publisher: $publish->publisher) === true
			&& $this->sameValue(approved: ($request['proposedValue'] ?? null), current: ($publish->entry['value'] ?? null)) === true;
	}//end covers()

	/**
	 * Whether the request was asked and decided by two people, neither decision
	 * by the person publishing.
	 *
	 * @param array<string, mixed> $request   The correction request.
	 * @param string               $publisher The uid of the person publishing.
	 *
	 * @return bool
	 */
	private function decidedByAnother(array $request, string $publisher): bool {
		$requester = (string)($request['requestedBy'] ?? '');
		$approver  = (string)($request['decidedBy'] ?? '');

		return $requester !== ''
			&& $approver !== ''
			&& $approver !== $requester
			&& $approver !== $publisher;
	}//end decidedByAnother()

	/**
	 * Whether the entry's value is the approved value. Numbers compare as
	 * numbers (7.5 equals "7.5"); an empty proposal matches an empty value.
	 *
	 * @param mixed $approved The request's proposedValue.
	 * @param mixed $current  The grade entry's value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function sameValue(mixed $approved, mixed $current): bool {
		if ($this->isBlank(value: $approved) === true) {
			return $this->isBlank(value: $current);
		}

		if (is_numeric($approved) === false || is_numeric($current) === false) {
			return false;
		}

		return abs((float)$approved - (float)$current) < 0.000001;
	}//end sameValue()

	/**
	 * Whether a value is empty: null or the empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isBlank(mixed $value): bool {
		return $value === null || $value === '';
	}//end isBlank()
}//end class
