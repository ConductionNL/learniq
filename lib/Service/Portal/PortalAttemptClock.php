<?php

/**
 * Learniq Portal Attempt Clock
 *
 * The server's clock for a timed attempt. Pure: no reads, no writes.
 *
 * deadline = startedAt + timeLimitMinutes x (1 + p / 100), where p is the
 * pupil's extra-time percentage from an approved or active ExamAccommodation.
 * An accommodation for this test wins over a generic one; among several of
 * one kind the largest applies; p is clamped to 0..300. The attempt closes 30
 * seconds after the deadline, the grace for the answer's round trip.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * Deadlines, extra time and the closing grace of a timed attempt.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalAttemptClock {

	/**
	 * Seconds an answer may arrive after the deadline.
	 */
	public const GRACE_SECONDS = 30;

	/**
	 * The accommodation kind that carries extra time.
	 */
	private const EXTRA_TIME_KIND = 'extra-time-percentage';

	/**
	 * Accommodation states that grant it.
	 */
	private const GRANTED_STATES = ['approved', 'active'];

	/**
	 * The largest extra time honoured, in percent.
	 */
	private const MAX_EXTRA_PERCENTAGE = 300.0;

	/**
	 * The extra-time percentage for one test from the pupil's accommodations.
	 *
	 * @param array<int, array<string, mixed>> $accommodations The pupil's ExamAccommodation rows.
	 * @param string $assessmentId The test.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function extraTimePercentage(array $accommodations, string $assessmentId): float {
		$specific = [];
		$generic = [];
		foreach ($accommodations as $row) {
			$value = $this->grantedValue(row: $row);
			if ($value === null) {
				continue;
			}

			$for = ($row['assessmentId'] ?? null);
			if ($for === null || $for === '') {
				$generic[] = $value;
				continue;
			}

			if ($for === $assessmentId) {
				$specific[] = $value;
			}
		}

		$values = $specific;
		if ($values === []) {
			$values = $generic;
		}

		if ($values === []) {
			return 0.0;
		}

		return min(self::MAX_EXTRA_PERCENTAGE, max(0.0, max($values)));
	}//end extraTimePercentage()

	/**
	 * The attempt's deadline, or null for an untimed test or an attempt with
	 * no known start.
	 *
	 * @param array<string, mixed> $attempt The AssessmentResult.
	 * @param array<string, mixed> $exam The Assessment.
	 * @param float $extraPercentage The pupil's extra time.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function deadline(array $attempt, array $exam, float $extraPercentage): ?DateTimeImmutable {
		$minutes = $this->timeLimit(exam: $exam);
		$start = $this->startOf(attempt: $attempt);
		if ($minutes === null || $start === null) {
			return null;
		}

		$seconds = (int)round($minutes * 60 * (1 + ($extraPercentage / 100)));

		return $start->add(new DateInterval('PT' . max(0, $seconds) . 'S'));
	}//end deadline()

	/**
	 * The extra minutes a pupil gets on a test, or null when there are none.
	 *
	 * @param array<string, mixed> $exam The Assessment.
	 * @param float $extraPercentage The pupil's extra time.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function extraMinutes(array $exam, float $extraPercentage): ?float {
		$minutes = $this->timeLimit(exam: $exam);
		if ($minutes === null || $extraPercentage <= 0.0) {
			return null;
		}

		return round($minutes * $extraPercentage / 100, 2);
	}//end extraMinutes()

	/**
	 * Whether an attempt is closed by time: past the deadline plus the grace.
	 *
	 * @param DateTimeImmutable|null $deadline The deadline, null when untimed.
	 * @param DateTimeInterface $now The current time.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function isClosed(?DateTimeImmutable $deadline, DateTimeInterface $now): bool {
		if ($deadline === null) {
			return false;
		}

		return $now->getTimestamp() > ($deadline->getTimestamp() + self::GRACE_SECONDS);
	}//end isClosed()

	/**
	 * The percentage a granted extra-time row carries, or null.
	 *
	 * @param array<string, mixed> $row An ExamAccommodation row.
	 *
	 * @return float|null
	 */
	private function grantedValue(array $row): ?float {
		if (($row['accommodationKind'] ?? '') !== self::EXTRA_TIME_KIND
			|| in_array(($row['lifecycle'] ?? ''), self::GRANTED_STATES, true) === false
		) {
			return null;
		}

		$value = ($row['value'] ?? null);
		if (is_numeric($value) === false) {
			return null;
		}

		return (float)$value;
	}//end grantedValue()

	/**
	 * The test's time limit in minutes, or null when untimed.
	 *
	 * @param array<string, mixed> $exam The Assessment.
	 *
	 * @return float|null
	 */
	private function timeLimit(array $exam): ?float {
		$minutes = ($exam['timeLimitMinutes'] ?? null);
		if (is_numeric($minutes) === false || (float)$minutes <= 0.0) {
			return null;
		}

		return (float)$minutes;
	}//end timeLimit()

	/**
	 * When the attempt started: its startedAt, else its creation time.
	 *
	 * @param array<string, mixed> $attempt The AssessmentResult.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function startOf(array $attempt): ?DateTimeImmutable {
		foreach ([($attempt['startedAt'] ?? null), ($attempt['@self']['created'] ?? null)] as $candidate) {
			if (is_string($candidate) === false || trim($candidate) === '') {
				continue;
			}

			try {
				return new DateTimeImmutable($candidate);
			} catch (Exception $exception) {
				continue;
			}
		}

		return null;
	}//end startOf()
}//end class
