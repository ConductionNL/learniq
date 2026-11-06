<?php

/**
 * Learniq Proctoring Flag Review rules
 *
 * The rules for a write to the `flags` of a proctoring session, matched on
 * `flagId` against the stored flags:
 *
 * - A caller outside the staff groups may only append flags whose
 *   `reviewDecision` is `pending` or absent. Changing or removing a stored
 *   flag is refused.
 * - A staff caller may move a stored flag from `pending` to `allowed` or
 *   `annulled`, once. A decided flag is not changed again, and no caller
 *   removes a stored flag.
 * - `reviewedBy` and `reviewedAt` belong to the server: a new decision is
 *   stamped with the caller and the server time, every other flag keeps its
 *   stored values, whatever the body said.
 *
 * Pure: no lookups, so ProctoringFlagReviewGuard decides who is staff and
 * what is stored, and this class only compares.
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
 * Compares incoming flags with stored ones and stamps who decided.
 *
 * @spec openspec/changes/proctoring-flag-review-page/specs/assessment/spec.md#requirement-only-staff-decide-a-flag-and-the-server-records-who-did
 */
class FlagReview {

	public const ONLY_STAFF = 'Only an instructor or compliance officer can decide a proctoring flag.';
	public const NO_CHANGE = 'A recorded proctoring flag cannot be changed.';
	public const NO_REMOVAL = 'A recorded proctoring flag cannot be removed.';
	public const DECIDED = 'This proctoring flag has already been decided.';
	public const BAD_DECISION = 'A proctoring flag can only be allowed or annulled.';

	private const PENDING = 'pending';
	private const DECISIONS = ['allowed', 'annulled'];

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
	public function review(array $stored, array $incoming, string $uid, bool $staff, DateTimeImmutable $now): array|string {
		$removal = $this->removalRefusal(stored: $stored, incoming: $incoming);
		if ($removal !== null) {
			return $removal;
		}

		$storedById = $this->byId(flags: $stored);
		$written = [];
		foreach ($incoming as $flag) {
			$old = ($storedById[($this->flagIdOf(flag: $flag) ?? '')] ?? null);
			$next = $this->storedFlag(old: $old, flag: $flag, uid: $uid, staff: $staff, now: $now);
			if ($old === null) {
				$next = $this->newFlag(flag: $flag, uid: $uid, staff: $staff, now: $now);
			}

			if (is_string($next) === true) {
				return $next;
			}

			$written[] = $next;
		}

		return $written;
	}//end review()

	/**
	 * Why a stored flag is missing from the write, or null when none is.
	 *
	 * A stored flag without an id cannot be matched, so it must come back
	 * exactly as stored.
	 *
	 * @param array<int, array<string, mixed>> $stored   The stored flags.
	 * @param array<int, array<string, mixed>> $incoming The flags in the write.
	 *
	 * @return string|null
	 */
	private function removalRefusal(array $stored, array $incoming): ?string {
		$incomingById = $this->byId(flags: $incoming);
		foreach ($stored as $old) {
			$flagId = $this->flagIdOf(flag: $old);
			if ($flagId === null && $this->containsExactly(flags: $incoming, flag: $old) === false) {
				return self::NO_CHANGE;
			}

			if ($flagId !== null && isset($incomingById[$flagId]) === false) {
				return self::NO_REMOVAL;
			}
		}

		return null;
	}//end removalRefusal()

	/**
	 * A flag the write appends.
	 *
	 * @param array<string, mixed> $flag  The flag in the write.
	 * @param string               $uid   The writer.
	 * @param bool                 $staff Whether the writer is staff.
	 * @param DateTimeImmutable    $now   Server time.
	 *
	 * @return array<string, mixed>|string
	 */
	private function newFlag(array $flag, string $uid, bool $staff, DateTimeImmutable $now): array|string {
		if ($this->decisionOf(flag: $flag) === self::PENDING) {
			return $this->withReview(flag: $flag, reviewedBy: null, reviewedAt: null);
		}

		return $this->decided(flag: $flag, uid: $uid, staff: $staff, now: $now);
	}//end newFlag()

	/**
	 * A flag that is already stored.
	 *
	 * @param array<string, mixed>|null $old   The stored flag, or null.
	 * @param array<string, mixed>      $flag  The flag in the write.
	 * @param string                    $uid   The writer.
	 * @param bool                      $staff Whether the writer is staff.
	 * @param DateTimeImmutable         $now   Server time.
	 *
	 * @return array<string, mixed>|string
	 */
	private function storedFlag(?array $old, array $flag, string $uid, bool $staff, DateTimeImmutable $now): array|string {
		if ($old === null) {
			return $flag;
		}

		if ($this->normalised(value: $this->withoutReview(flag: $old)) !== $this->normalised(value: $this->withoutReview(flag: $flag))) {
			return self::NO_CHANGE;
		}

		$oldDecision = $this->decisionOf(flag: $old);
		if ($this->decisionOf(flag: $flag) === $oldDecision) {
			return $this->withReview(flag: $flag, reviewedBy: ($old['reviewedBy'] ?? null), reviewedAt: ($old['reviewedAt'] ?? null));
		}

		if ($staff === true && $oldDecision !== self::PENDING) {
			return self::DECIDED;
		}

		return $this->decided(flag: $flag, uid: $uid, staff: $staff, now: $now);
	}//end storedFlag()

	/**
	 * A decision: staff only, allowed or annulled, stamped by the server.
	 *
	 * @param array<string, mixed> $flag  The flag in the write.
	 * @param string               $uid   The writer.
	 * @param bool                 $staff Whether the writer is staff.
	 * @param DateTimeImmutable    $now   Server time.
	 *
	 * @return array<string, mixed>|string
	 */
	private function decided(array $flag, string $uid, bool $staff, DateTimeImmutable $now): array|string {
		if ($staff === false) {
			return self::ONLY_STAFF;
		}

		if (in_array($this->decisionOf(flag: $flag), self::DECISIONS, true) === false) {
			return self::BAD_DECISION;
		}

		return $this->withReview(flag: $flag, reviewedBy: $uid, reviewedAt: $now->format(DATE_ATOM));
	}//end decided()

	/**
	 * The flag with the server-owned review fields set.
	 *
	 * @param array<string, mixed> $flag       The flag.
	 * @param mixed                $reviewedBy The reviewer, or null.
	 * @param mixed                $reviewedAt The review time, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function withReview(array $flag, mixed $reviewedBy, mixed $reviewedAt): array {
		$flag['reviewDecision'] = $this->decisionOf(flag: $flag);
		$flag['reviewedBy'] = $reviewedBy;
		$flag['reviewedAt'] = $reviewedAt;

		return $flag;
	}//end withReview()

	/**
	 * The flag without its decision and the server-owned review fields.
	 *
	 * @param array<string, mixed> $flag The flag.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutReview(array $flag): array {
		unset($flag['reviewDecision'], $flag['reviewedBy'], $flag['reviewedAt']);

		return $flag;
	}//end withoutReview()

	/**
	 * The flag's decision, `pending` when absent or empty.
	 *
	 * @param array<string, mixed> $flag The flag.
	 *
	 * @return string
	 */
	private function decisionOf(array $flag): string {
		$decision = ($flag['reviewDecision'] ?? null);
		if (is_string($decision) === false || $decision === '') {
			return self::PENDING;
		}

		return $decision;
	}//end decisionOf()

	/**
	 * The flag's id, or null when it has none.
	 *
	 * @param array<string, mixed> $flag The flag.
	 *
	 * @return string|null
	 */
	private function flagIdOf(array $flag): ?string {
		$flagId = ($flag['flagId'] ?? null);
		if (is_scalar($flagId) === false || (string)$flagId === '') {
			return null;
		}

		return (string)$flagId;
	}//end flagIdOf()

	/**
	 * The flags that have an id, keyed by it.
	 *
	 * @param array<int, array<string, mixed>> $flags The flags.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function byId(array $flags): array {
		$byId = [];
		foreach ($flags as $flag) {
			$flagId = $this->flagIdOf(flag: $flag);
			if ($flagId !== null) {
				$byId[$flagId] = $flag;
			}
		}

		return $byId;
	}//end byId()

	/**
	 * Whether the list holds this exact flag.
	 *
	 * @param array<int, array<string, mixed>> $flags The flags.
	 * @param array<string, mixed>             $flag  The flag to find.
	 *
	 * @return bool
	 */
	private function containsExactly(array $flags, array $flag): bool {
		foreach ($flags as $candidate) {
			if ($this->normalised(value: $candidate) === $this->normalised(value: $flag)) {
				return true;
			}
		}

		return false;
	}//end containsExactly()

	/**
	 * A value with its object keys sorted, so two flags compare by content.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalised(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$value = array_map(fn ($item): mixed => $this->normalised(value: $item), $value);
		if (array_is_list($value) === false) {
			ksort($value);
		}

		return $value;
	}//end normalised()
}//end class
