<?php

/**
 * Contract of FlagReview
 *
 * The public surface of FlagReview that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of FlagReview.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FlagReviewInterface {

	/**
	 * The flags as they may be written, or the reason they may not.
	 *
	 * @param array<int, array<string, mixed>> $stored   The stored flags.
	 * @param array<int, array<string, mixed>> $incoming The flags in the write.
	 * @param string                           $uid      The writer.
	 * @param bool                             $staff    Whether the writer is staff.
	 * @param DateTimeImmutable                $now      Server time.
	 *
	 * @return array<int, array<string, mixed>>|string
	 *
	 * @spec openspec/changes/proctoring-flag-review-page/specs/assessment/spec.md#requirement-only-staff-decide-a-flag-and-the-server-records-who-did
	 */
	public function review(array $stored, array $incoming, string $uid, bool $staff, DateTimeImmutable $now): array|string;
}//end interface
