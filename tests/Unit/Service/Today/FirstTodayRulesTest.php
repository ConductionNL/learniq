<?php

/**
 * The First today rule list: who reads which rules, the order, and the empty case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use OCA\Learniq\Service\Today\FirstTodayRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Today\FirstTodayRules
 */
class FirstTodayRulesTest extends TestCase {

	/**
	 * The order of each role is the design's.
	 *
	 * @return void
	 */
	public function testEachRoleReadsItsRulesInTheDesignsOrder(): void {
		self::assertSame(['register-not-filled', 'bookings-to-confirm', 'nearing-limit'], FirstTodayRules::ORDER['group-teacher']);
		self::assertSame(['lesson-register-open', 'nearing-limit', 'missing-hand-ins'], FirstTodayRules::ORDER['subject-teacher']);
		self::assertSame(['unauthorised-absence', 'mentor-talks-closing'], FirstTodayRules::ORDER['mentor']);
		self::assertSame(['plan-ending', 'signal-not-taken-up'], FirstTodayRules::ORDER['care-coordinator']);
		self::assertSame(['leave-past-half', 'over-limit-not-reported', 'registers-not-filled-at-930'], FirstTodayRules::ORDER['head']);
		self::assertCount(12, FirstTodayRules::allRules());
	}//end testEachRoleReadsItsRulesInTheDesignsOrder()

	/**
	 * A teacher is a group teacher in primary education, a subject teacher elsewhere, a mentor in the Mentor view.
	 *
	 * @return void
	 */
	public function testTheRoleFollowsThePrimaryRoleTheSchoolKindAndTheView(): void {
		self::assertSame('group-teacher', FirstTodayRules::roleFor(primaryRole: 'instructor', segment: 'po', view: ''));
		self::assertSame('subject-teacher', FirstTodayRules::roleFor(primaryRole: 'instructor', segment: 'vo', view: ''));
		self::assertSame('subject-teacher', FirstTodayRules::roleFor(primaryRole: 'instructor', segment: 'mbo', view: ''));
		self::assertSame('mentor', FirstTodayRules::roleFor(primaryRole: 'instructor', segment: 'vo', view: 'mentor'));
		self::assertSame('care-coordinator', FirstTodayRules::roleFor(primaryRole: 'coordinator', segment: 'po', view: ''));
		self::assertSame('head', FirstTodayRules::roleFor(primaryRole: 'administration-manager', segment: 'po', view: ''));
		self::assertSame('head', FirstTodayRules::roleFor(primaryRole: 'team-lead', segment: 'vo', view: ''));
		self::assertSame('head', FirstTodayRules::roleFor(primaryRole: 'admin', segment: 'vo', view: ''));
		self::assertNull(FirstTodayRules::roleFor(primaryRole: 'learner', segment: 'vo', view: ''));
		self::assertNull(FirstTodayRules::roleFor(primaryRole: 'hr', segment: 'corporate', view: 'mentor'));
	}//end testTheRoleFollowsThePrimaryRoleTheSchoolKindAndTheView()

	/**
	 * The first rule with a row wins, and the rules after it are not asked.
	 *
	 * @return void
	 */
	public function testTheFirstRuleWithARowWinsAndLaterRulesAreNotRead(): void {
		$asked = [];
		$first = FirstTodayRules::first(
			role: 'group-teacher',
			probe: static function (string $rule) use (&$asked): array {
				$asked[] = $rule;
				return ['count' => ($rule === 'bookings-to-confirm' ? 2 : 0)];
			}
		);

		self::assertSame(['count' => 2, 'role' => 'group-teacher', 'rule' => 'bookings-to-confirm'], $first);
		self::assertSame(['register-not-filled', 'bookings-to-confirm'], $asked);
	}//end testTheFirstRuleWithARowWinsAndLaterRulesAreNotRead()

	/**
	 * An earlier rule wins over a later one that also has rows.
	 *
	 * @return void
	 */
	public function testAnEarlierRuleWinsOverALaterOne(): void {
		$first = FirstTodayRules::first(role: 'head', probe: static fn (string $rule): array => ['count' => 1, 'which' => $rule]);

		self::assertSame('leave-past-half', $first['rule']);
		self::assertSame('leave-past-half', $first['which']);
	}//end testAnEarlierRuleWinsOverALaterOne()

	/**
	 * Nothing first: no rule has a row, or a rule cannot be read.
	 *
	 * @return void
	 */
	public function testNoRuleWithARowMeansNoCard(): void {
		self::assertNull(FirstTodayRules::first(role: 'mentor', probe: static fn (string $rule): array => ['count' => 0]));
		self::assertNull(FirstTodayRules::first(role: 'care-coordinator', probe: static fn (string $rule): ?array => null));
		self::assertNull(FirstTodayRules::first(role: 'no-such-role', probe: static fn (string $rule): array => ['count' => 5]));
	}//end testNoRuleWithARowMeansNoCard()
}//end class
