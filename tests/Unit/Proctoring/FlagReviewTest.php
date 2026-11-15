<?php

/**
 * FlagReview: every allowed and refused outcome of a flags write.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Proctoring
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

namespace OCA\Learniq\Tests\Unit\Proctoring;

use DateTimeImmutable;
use OCA\Learniq\Proctoring\FlagReview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins FlagReview::review() per case: the flags as written, or the refusal.
 *
 * @covers \OCA\Learniq\Proctoring\FlagReview
 */
class FlagReviewTest extends TestCase {

	private const NOW = '2026-10-01T09:00:00+00:00';
	private const STAMPED = ['reviewedBy' => 'teacher-1', 'reviewedAt' => self::NOW];
	private const KEPT = ['reviewedBy' => 'teacher-0', 'reviewedAt' => '2026-09-30T08:00:00+00:00'];

	/**
	 * The cases: stored, incoming, staff, and what review() answers.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: bool, 3: array<int, array<string, mixed>>|string}>
	 */
	public static function cases(): array {
		$pending = ['flagId' => 'f1', 'kind' => 'tab-switch'];
		$allowed = array_merge($pending, ['reviewDecision' => 'allowed'], self::KEPT);

		return [
			'nothing stored, nothing written' => [[], [], false, []],
			'a new pending flag' => [[], [$pending], false, [array_merge($pending, ['reviewDecision' => 'pending', 'reviewedBy' => null, 'reviewedAt' => null])]],
			'a new flag decided by a learner' => [[], [array_merge($pending, ['reviewDecision' => 'allowed'])], false, FlagReview::ONLY_STAFF],
			'a new flag decided by staff' => [[], [array_merge($pending, ['reviewDecision' => 'annulled'])], true, [array_merge($pending, ['reviewDecision' => 'annulled'], self::STAMPED)]],
			'a new flag with a bad decision' => [[], [array_merge($pending, ['reviewDecision' => 'ignored'])], true, FlagReview::BAD_DECISION],
			'a new flag with an empty decision' => [[], [array_merge($pending, ['reviewDecision' => ''])], false, [array_merge($pending, ['reviewDecision' => 'pending', 'reviewedBy' => null, 'reviewedAt' => null])]],
			'a stored flag removed' => [[$pending], [], true, FlagReview::NO_REMOVAL],
			'an id-less flag rewritten' => [[['kind' => 'blur']], [['kind' => 'focus']], true, FlagReview::NO_CHANGE],
			'an id-less flag kept, keys reordered' => [[['kind' => 'blur', 'at' => 1]], [['at' => 1, 'kind' => 'blur']], false, [['at' => 1, 'kind' => 'blur', 'reviewDecision' => 'pending', 'reviewedBy' => null, 'reviewedAt' => null]]],
			'a stored flag rewritten' => [[$pending], [['flagId' => 'f1', 'kind' => 'blur']], true, FlagReview::NO_CHANGE],
			'a stored flag sent back unchanged' => [[$allowed], [array_merge($pending, ['reviewDecision' => 'allowed', 'reviewedBy' => 'forged', 'reviewedAt' => 'forged'])], false, [$allowed]],
			'a decided flag decided again by staff' => [[$allowed], [array_merge($pending, ['reviewDecision' => 'annulled'])], true, FlagReview::DECIDED],
			'a decided flag changed by a learner' => [[$allowed], [array_merge($pending, ['reviewDecision' => 'annulled'])], false, FlagReview::ONLY_STAFF],
			'a pending flag decided by staff' => [[$pending], [array_merge($pending, ['reviewDecision' => 'allowed'])], true, [array_merge($pending, ['reviewDecision' => 'allowed'], self::STAMPED)]],
			'a pending flag decided by a learner' => [[$pending], [array_merge($pending, ['reviewDecision' => 'allowed'])], false, FlagReview::ONLY_STAFF],
			'a pending flag badly decided by staff' => [[$pending], [array_merge($pending, ['reviewDecision' => 'maybe'])], true, FlagReview::BAD_DECISION],
			'the first refusal wins' => [[], [array_merge($pending, ['reviewDecision' => 'maybe']), ['flagId' => 'f2', 'reviewDecision' => 'allowed']], false, FlagReview::ONLY_STAFF],
			'a stored and a new flag' => [[$pending], [$pending, ['flagId' => 'f2', 'kind' => 'blur']], false, [
				array_merge($pending, ['reviewDecision' => 'pending', 'reviewedBy' => null, 'reviewedAt' => null]),
				['flagId' => 'f2', 'kind' => 'blur', 'reviewDecision' => 'pending', 'reviewedBy' => null, 'reviewedAt' => null],
			]],
		];
	}//end cases()

	/**
	 * One write answers as recorded.
	 *
	 * @param array<int, array<string, mixed>>        $stored   The stored flags.
	 * @param array<int, array<string, mixed>>        $incoming The flags in the write.
	 * @param bool                                    $staff    Whether the writer is staff.
	 * @param array<int, array<string, mixed>>|string $expected What review() answers.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testReview(array $stored, array $incoming, bool $staff, array|string $expected): void {
		$writer = 'learner-1';
		if ($staff === true) {
			$writer = 'teacher-1';
		}

		$result = (new FlagReview())->review(stored: $stored, incoming: $incoming, uid: $writer, staff: $staff, now: new DateTimeImmutable(self::NOW));

		self::assertSame($expected, $result);
	}//end testReview()
}//end class
