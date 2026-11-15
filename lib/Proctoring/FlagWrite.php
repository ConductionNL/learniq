<?php

/**
 * Learniq Proctoring Flag Write
 *
 * One write to the `flags` of a proctoring session as FlagReview::review()
 * checks it: the stored flags by id, the writer, whether the writer is
 * staff, and the server time a new decision is stamped with. Built and read
 * only inside FlagReview, for the length of one review.
 *
 * @category Proctoring
 * @package  OCA\Learniq\Proctoring
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
 * @spec openspec/changes/proctoring-flag-review-page/specs/assessment/spec.md#requirement-only-staff-decide-a-flag-and-the-server-records-who-did
 */

declare(strict_types=1);

namespace OCA\Learniq\Proctoring;

use DateTimeImmutable;

/**
 * One flags write under review.
 *
 * @spec openspec/changes/proctoring-flag-review-page/specs/assessment/spec.md#requirement-only-staff-decide-a-flag-and-the-server-records-who-did
 */
final class FlagWrite {

	/**
	 * The stored flags that have an id, keyed by it.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $storedById;

	/**
	 * The writer.
	 *
	 * @var string
	 */
	public string $uid;

	/**
	 * Whether the writer is staff.
	 *
	 * @var bool
	 */
	public bool $staff;

	/**
	 * Server time.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $now;
}//end class
