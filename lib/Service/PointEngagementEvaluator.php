<?php

/**
 * Learniq Point Engagement Evaluator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Stateless calculation engine that sums a learner's PointAward.points,
 * resolves the highest EngagementLevel the learner has reached, and computes
 * their current/longest activity streak from distinct PointAward.awardedAt
 * calendar dates.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * A grep of every "metric": value across lib/Settings/learniq_register.json
 * returns only count and count_distinct -- never sum, anywhere in this
 * register. FinalGrade.value (GradeFormulaEvaluator) and BsaTrajectory's
 * ectsEarned (BsaProgressEvaluator) both hit this exact limitation and both
 * resolved it the same way: a constructor-injected PHP evaluator. This class
 * follows the identical, precedented path for LearnerEngagement.totalPoints.
 *
 * Unlike GradeFormulaEvaluator/BsaProgressEvaluator, this evaluator also
 * needs an injectable "now" to determine whether a streak is still alive
 * (ending today or yesterday) -- ITimeFactory is constructor-injected for
 * the same deterministic-testing reason BsaProgressFlagHandler injects it.
 * longestStreakDays is computed by scanning the learner's FULL PointAward
 * date history for the longest run of consecutive calendar days anywhere in
 * it, not by comparing against a previously-stored value -- because
 * PointAward is appendOnly (rows are never deleted or edited), this is
 * equivalent to max(existing longestStreakDays, currentStreakDays) while
 * keeping the evaluator a pure function of its inputs (no state, no reads of
 * the LearnerEngagement row it is about to overwrite).
 *
 * Consumed by:
 *   - LearnerEngagementRollupHandler (via ObjectCreatedEvent on PointAward)
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\PointEngagementEvaluatorInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Sums a learner's PointAward.points, resolves their EngagementLevel, and
 * computes their activity streak.
 *
 * @spec openspec/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */
class PointEngagementEvaluator implements PointEngagementEvaluatorInterface {

	private const LEARNIQ_REGISTER = 'learniq';
	private const POINT_AWARD_SCHEMA = 'point-award';
	private const ENGAGEMENT_LEVEL_SCHEMA = 'engagement-level';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param ITimeFactory $timeFactory NC time source (injectable "now" for tests).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Compute a learner's totalPoints, levelId, and streak days.
	 *
	 * @param string $learnerId Nextcloud user ID of the learner.
	 * @param string $tenantId Tenant identifier the EngagementLevel rows are scoped to.
	 *
	 * @return array{totalPoints: float, levelId: ?string, currentStreakDays: int, longestStreakDays: int, lastActivityDate: ?string}
	 *
	 * @spec openspec/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
	 */
	public function evaluate(string $learnerId, string $tenantId): array {
		if ($learnerId === '') {
			return $this->emptyResult();
		}

		$awards = $this->fetchAwards(learnerId: $learnerId);

		if (empty($awards) === true) {
			$result = $this->emptyResult();
			$result['levelId'] = $this->resolveLevelId(totalPoints: 0.0, tenantId: $tenantId);
			return $result;
		}

		$totalPoints = 0.0;
		foreach ($awards as $award) {
			$totalPoints += (float)($award['points'] ?? 0);
		}

		$distinctDates = self::activityDates(awards: $awards);

		$levelId = $this->resolveLevelId(totalPoints: $totalPoints, tenantId: $tenantId);
		$currentStreakDays = $this->computeCurrentStreak(distinctDates: $distinctDates);
		$longestStreakDays = $this->computeLongestStreak(distinctDates: $distinctDates);
		$lastActivityDate = null;
		if (empty($distinctDates) === false) {
			$lastActivityDate = $distinctDates[count($distinctDates) - 1];
		}

		return [
			'totalPoints' => $totalPoints,
			'levelId' => $levelId,
			'currentStreakDays' => $currentStreakDays,
			'longestStreakDays' => $longestStreakDays,
			'lastActivityDate' => $lastActivityDate,
		];

	}//end evaluate()

	/**
	 * The distinct days the awards were given on, oldest first.
	 *
	 * @param list<array<string,mixed>> $awards The awards.
	 *
	 * @return list<string> `Y-m-d` dates.
	 */
	private static function activityDates(array $awards): array {
		$dates = [];
		foreach ($awards as $award) {
			$date = self::day(awardedAt: ($award['awardedAt'] ?? null));
			if ($date !== null) {
				$dates[$date] = true;
			}
		}

		$distinctDates = array_map('strval', array_keys($dates));
		sort($distinctDates);

		return $distinctDates;
	}//end activityDates()

	/**
	 * The day of an award time, or null when it is not a readable time.
	 *
	 * @param mixed $awardedAt The award's `awardedAt`.
	 *
	 * @return string|null
	 */
	private static function day(mixed $awardedAt): ?string {
		if (is_string($awardedAt) === false || $awardedAt === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($awardedAt))->format('Y-m-d');
		} catch (\Exception) {
			return null;
		}
	}//end day()

	/**
	 * Fetch every PointAward for the learner.
	 *
	 * @param string $learnerId NC user ID.
	 *
	 * @return array<int, array>
	 */
	private function fetchAwards(string $learnerId): array {
		$results = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::POINT_AWARD_SCHEMA,
					'learnerId' => $learnerId,
				],
			]
		);

		return array_map(
			static function ($award) {
				if (is_array($award) === true) {
					return $award;
				}

				return $award->jsonSerialize();
			},
			$results
		);

	}//end fetchAwards()

	/**
	 * Resolve the highest EngagementLevel whose minPoints is at most totalPoints.
	 *
	 * @param float $totalPoints The learner's summed points.
	 * @param string $tenantId Tenant identifier.
	 *
	 * @return string|null
	 */
	private function resolveLevelId(float $totalPoints, string $tenantId): ?string {
		$best = ['minPoints' => null, 'id' => null];
		foreach ($this->levels(tenantId: $tenantId) as $level) {
			if (self::reached(level: $level, totalPoints: $totalPoints) === true && self::higher(level: $level, best: $best) === true) {
				$best = self::rank(level: $level);
			}
		}

		return $best['id'];
	}//end resolveLevelId()

	/**
	 * The tenant's engagement levels as arrays.
	 *
	 * @param string $tenantId The tenant.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function levels(string $tenantId): array {
		$levels = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::ENGAGEMENT_LEVEL_SCHEMA,
					'tenant_id' => $tenantId,
				],
			]
		);

		$rows = [];
		foreach ($levels as $level) {
			if (is_array($level) === false) {
				$level = $level->jsonSerialize();
			}

			$rows[] = $level;
		}

		return $rows;
	}//end levels()

	/**
	 * Whether the points reach the level: it has a threshold and the points are at or above it.
	 *
	 * @param array<string,mixed> $level       The level.
	 * @param float               $totalPoints The learner's points.
	 *
	 * @return bool
	 */
	private static function reached(array $level, float $totalPoints): bool {
		$minPoints = $level['minPoints'] ?? null;

		return $minPoints !== null && (float)$minPoints <= $totalPoints;
	}//end reached()

	/**
	 * Whether the level's threshold is above the best so far; the first of equal thresholds stays.
	 *
	 * @param array<string,mixed>                     $level The level.
	 * @param array{minPoints: float|null, id: mixed} $best  The best level so far.
	 *
	 * @return bool
	 */
	private static function higher(array $level, array $best): bool {
		return $best['minPoints'] === null || (float)$level['minPoints'] > $best['minPoints'];
	}//end higher()

	/**
	 * A reached level's threshold and id.
	 *
	 * @param array<string,mixed> $level The level.
	 *
	 * @return array{minPoints: float, id: mixed}
	 */
	private static function rank(array $level): array {
		return ['minPoints' => (float)$level['minPoints'], 'id' => $level['id'] ?? ($level['uuid'] ?? null)];
	}//end rank()

	/**
	 * Count consecutive calendar days, ending today or yesterday, with at
	 * least one PointAward.
	 *
	 * A learner who was active yesterday but has not yet acted today keeps
	 * their streak alive through the day (the streak only breaks once a full
	 * day passes with no activity).
	 *
	 * @param array<int, string> $distinctDates Sorted ascending 'Y-m-d' dates with >=1 award.
	 *
	 * @return int
	 */
	private function computeCurrentStreak(array $distinctDates): int {
		if (empty($distinctDates) === true) {
			return 0;
		}

		$today = DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format('Y-m-d');
		$yesterday = DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->modify('-1 day')->format('Y-m-d');

		$dateSet = array_flip($distinctDates);
		$anchor = self::anchor(dateSet: $dateSet, days: [$today, $yesterday]);
		if ($anchor === null) {
			// No activity today or yesterday -- the streak has broken.
			return 0;
		}

		$count = 0;
		$cursor = new DateTimeImmutable($anchor);
		while (isset($dateSet[$cursor->format('Y-m-d')]) === true) {
			$count++;
			$cursor = $cursor->modify('-1 day');
		}

		return $count;
	}//end computeCurrentStreak()

	/**
	 * The first of the days (today, then yesterday) with activity, or null.
	 *
	 * @param array<string,int> $dateSet The activity days as keys.
	 * @param list<string>      $days    The days to try, in order.
	 *
	 * @return string|null
	 */
	private static function anchor(array $dateSet, array $days): ?string {
		foreach ($days as $day) {
			if (isset($dateSet[$day]) === true) {
				return $day;
			}
		}

		return null;
	}//end anchor()

	/**
	 * Find the longest run of consecutive calendar days anywhere in the
	 * learner's full award history.
	 *
	 * @param array<int, string> $distinctDates Sorted ascending 'Y-m-d' dates with >=1 award.
	 *
	 * @return int
	 */
	private function computeLongestStreak(array $distinctDates): int {
		if (empty($distinctDates) === true) {
			return 0;
		}

		$longest = 1;
		$running = 1;
		$previous = new DateTimeImmutable($distinctDates[0]);
		$dateCount = count($distinctDates);

		for ($i = 1; $i < $dateCount; $i++) {
			$current = new DateTimeImmutable($distinctDates[$i]);
			$expectedNext = $previous->modify('+1 day')->format('Y-m-d');

			$running = match ($current->format('Y-m-d') === $expectedNext) {
				true => $running + 1,
				default => 1,
			};

			$longest = max($longest, $running);
			$previous = $current;
		}

		return $longest;
	}//end computeLongestStreak()

	/**
	 * Return an empty result (no PointAwards yet).
	 *
	 * @return array{totalPoints: float, levelId: null, currentStreakDays: int, longestStreakDays: int, lastActivityDate: null}
	 */
	private function emptyResult(): array {
		return [
			'totalPoints' => 0.0,
			'levelId' => null,
			'currentStreakDays' => 0,
			'longestStreakDays' => 0,
			'lastActivityDate' => null,
		];

	}//end emptyResult()
}//end class
