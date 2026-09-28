<?php

/**
 * Learniq Sharing Blocked Exception
 *
 * Thrown when the sharing gate refuses to let a course leave the school.
 * Carries the full list of reasons, so the caller can show every one of them
 * at once instead of making the teacher fix them one round trip at a time.
 *
 * @category Exception
 * @package  OCA\Learniq\Exception
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
 */

declare(strict_types=1);

namespace OCA\Learniq\Exception;

use RuntimeException;

/**
 * The course may not leave the school yet.
 */
class SharingBlockedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{code: string, id: string, name: string}> $blockers The gate's reasons.
	 */
	public function __construct(
		private readonly array $blockers,
	) {
		parent::__construct(message: 'This course may not leave the school yet.');

	}//end __construct()

	/**
	 * The reasons the gate gave.
	 *
	 * @return array<int, array{code: string, id: string, name: string}>
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
	 */
	public function getBlockers(): array {
		return $this->blockers;

	}//end getBlockers()
}//end class
