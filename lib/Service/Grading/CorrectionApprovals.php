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

use OCA\OpenRegister\Service\ObjectService;

/**
 * The approved correction for one grade entry, if there is one.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */
class CorrectionApprovals {

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
		$entryId = (string)($entry['id'] ?? ($entry['uuid'] ?? ''));
		if ($entryId === '' || $publisher === '') {
			return null;
		}

		$rows = $this->objects->findAll(
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

		foreach ($rows as $row) {
			$request = $row;
			if (is_array($row) === false) {
				$request = $row->jsonSerialize();
			}

			if ($this->covers(request: $request, entry: $entry, entryId: $entryId, publisher: $publisher) === true) {
				return $request;
			}
		}

		return null;
	}//end approvedFor()

	/**
	 * Whether one request covers this publish.
	 *
	 * @param array<string, mixed> $request   The correction request.
	 * @param array<string, mixed> $entry     The grade entry.
	 * @param string               $entryId   The grade entry uuid.
	 * @param string               $publisher The uid of the person publishing.
	 *
	 * @return bool
	 */
	private function covers(array $request, array $entry, string $entryId, string $publisher): bool {
		$requester = (string)($request['requestedBy'] ?? '');
		$approver  = (string)($request['decidedBy'] ?? '');

		return ($request['lifecycle'] ?? null) === 'approved'
			&& (string)($request['gradeEntryId'] ?? '') === $entryId
			&& $requester !== ''
			&& $approver !== ''
			&& $approver !== $requester
			&& $approver !== $publisher
			&& $this->sameValue(approved: ($request['proposedValue'] ?? null), current: ($entry['value'] ?? null)) === true;
	}//end covers()

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
		if ($approved === null || $approved === '') {
			return $current === null || $current === '';
		}

		if (is_numeric($approved) === false || is_numeric($current) === false) {
			return false;
		}

		return abs((float)$approved - (float)$current) < 0.000001;
	}//end sameValue()
}//end class
