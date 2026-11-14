<?php

/**
 * Learniq Learner Manager Lookup
 *
 * Answers one question: who is this pupil's mentor? The mentor is the
 * LearnerProfile's `managerId`. Read models stamp it so OpenRegister's
 * `match` rule can let the mentor read the row.
 *
 * Runs without a session (a background job, a repair step), so the read
 * passes `_rbac: false` and `_multitenancy: false`. A failed read throws: the
 * caller decides whether that keeps a stored value or stamps nobody.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Resolves the manager (mentor) of a pupil from their profile.
 *
 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
 */
class LearnerManagerLookup {

	private const REGISTER = 'learniq';
	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Turns one OpenRegister result into a plain array.
	 *
	 * @var ObjectRow
	 */
	private readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * The Nextcloud user id of the pupil's manager, or null when none is set.
	 *
	 * @param string $learnerId Nextcloud user id of the pupil.
	 *
	 * @return string|null
	 *
	 * @throws \Throwable When the profiles cannot be read.
	 *
	 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
	 */
	public function forLearner(string $learnerId): ?string {
		if ($learnerId === '') {
			return null;
		}

		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::PROFILE_SCHEMA,
					'ncUserId' => $learnerId,
				],
				'limit' => 5,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($rows as $row) {
			$profile = $this->objectRow->asArray(object: $row);
			// Never let a widened answer hand out another pupil's mentor.
			if (($profile['ncUserId'] ?? null) !== $learnerId) {
				continue;
			}

			$managerId = ($profile['managerId'] ?? null);
			if (is_string($managerId) === true && $managerId !== '') {
				return $managerId;
			}
		}

		return null;
	}//end forLearner()
}//end class
