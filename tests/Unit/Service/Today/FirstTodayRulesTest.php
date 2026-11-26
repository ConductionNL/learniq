<?php

/**
 * The First today rule list: who reads which rules, the order, and the empty case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
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
		self::assertSame(expected: ['register-not-filled', 'bookings-to-confirm', 'nearing-limit'], actual: FirstTodayRules::ORDER['group-teacher']);
		self::assertSame(expected: ['lesson-register-open', 'nearing-limit', 'missing-hand-ins'], actual: FirstTodayRules::ORDER['subject-teacher']);
		self::assertSame(expected: ['unauthorised-absence', 'mentor-talks-closing'], actual: FirstTodayRules::ORDER['mentor']);
		self::assertSame(expected: ['plan-ending', 'signal-not-taken-up'], actual: FirstTodayRules::ORDER['care-coordinator']);
		self::assertSame(
			expected: ['leave-past-half', 'over-limit-not-reported', 'registers-not-filled-at-930'],
			actual: FirstTodayRules::ORDER['head']
		);
		self::assertSame(
			expected: ['placement-without-company', 'visit-without-date', 'hours-waiting'],
			actual: FirstTodayRules::ORDER['bpv-coach']
		);
		self::assertSame(expected: ['objection-past-half', 'results-waiting'], actual: FirstTodayRules::ORDER['exam-committee']);
		self::assertCount(expectedCount: 17, haystack: (new FirstTodayRules())->allRules());
	}//end testEachRoleReadsItsRulesInTheDesignsOrder()

	/**
	 * A teacher is a group teacher in primary education, a subject teacher elsewhere, a mentor in the Mentor view.
	 *
	 * @return void
	 */
	public function testTheRoleFollowsThePrimaryRoleTheSchoolKindAndTheView(): void {
		$rules = new FirstTodayRules();
		self::assertSame(expected: 'group-teacher', actual: $rules->roleFor(primaryRole: 'instructor', segment: 'po'));
		self::assertSame(expected: 'subject-teacher', actual: $rules->roleFor(primaryRole: 'instructor', segment: 'vo'));
		self::assertSame(expected: 'subject-teacher', actual: $rules->roleFor(primaryRole: 'instructor', segment: 'mbo'));
		self::assertSame(expected: 'mentor', actual: $rules->inView(role: $rules->roleFor(primaryRole: 'instructor', segment: 'vo'), view: 'mentor'));
		self::assertSame(expected: 'care-coordinator', actual: $rules->roleFor(primaryRole: 'coordinator', segment: 'po'));
		self::assertSame(expected: 'head', actual: $rules->roleFor(primaryRole: 'administration-manager', segment: 'po'));
		self::assertSame(expected: 'head', actual: $rules->roleFor(primaryRole: 'team-lead', segment: 'vo'));
		self::assertSame(expected: 'head', actual: $rules->roleFor(primaryRole: 'admin', segment: 'vo'));
		self::assertNull(actual: $rules->roleFor(primaryRole: 'learner', segment: 'vo'));
		self::assertNull(actual: $rules->inView(role: $rules->roleFor(primaryRole: 'hr', segment: 'corporate'), view: 'mentor'));
		self::assertSame(expected: 'head', actual: $rules->inView(role: 'head', view: 'mentor'));
		self::assertSame(expected: 'bpv-coach', actual: $rules->inView(role: 'subject-teacher', view: 'bpv'));
		self::assertSame(expected: 'head', actual: $rules->inView(role: 'head', view: 'bpv'));
		self::assertSame(expected: 'exam-committee', actual: $rules->roleFor(primaryRole: 'compliance-officer', segment: 'mbo'));
		self::assertSame(expected: 'exam-committee', actual: $rules->roleFor(primaryRole: 'compliance-officer', segment: 'vo'));
		self::assertNull(actual: $rules->roleFor(primaryRole: 'compliance-officer', segment: 'corporate'));
		self::assertSame(expected: 'group-teacher', actual: $rules->inView(role: 'group-teacher', view: ''));
	}//end testTheRoleFollowsThePrimaryRoleTheSchoolKindAndTheView()

	/**
	 * The first rule with a row wins, and the rules after it are not asked.
	 *
	 * @return void
	 */
	public function testTheFirstRuleWithARowWinsAndLaterRulesAreNotRead(): void {
		$asked = [];
		$first = (new FirstTodayRules())->first(
			role: 'group-teacher',
			probe: static function (string $rule) use (&$asked): array {
				$asked[] = $rule;
				return ['count' => (int)($rule === 'bookings-to-confirm') * 2];
			}
		);

		self::assertSame(expected: ['count' => 2, 'role' => 'group-teacher', 'rule' => 'bookings-to-confirm'], actual: $first);
		self::assertSame(expected: ['register-not-filled', 'bookings-to-confirm'], actual: $asked);
	}//end testTheFirstRuleWithARowWinsAndLaterRulesAreNotRead()

	/**
	 * An earlier rule wins over a later one that also has rows.
	 *
	 * @return void
	 */
	public function testAnEarlierRuleWinsOverALaterOne(): void {
		$first = (new FirstTodayRules())->first(role: 'head', probe: static fn (string $rule): array => ['count' => 1, 'which' => $rule]);

		self::assertSame(expected: 'leave-past-half', actual: $first['rule']);
		self::assertSame(expected: 'leave-past-half', actual: $first['which']);
	}//end testAnEarlierRuleWinsOverALaterOne()

	/**
	 * Nothing first: no rule has a row, or a rule cannot be read.
	 *
	 * @return void
	 */
	public function testNoRuleWithARowMeansNoCard(): void {
		self::assertNull(actual: (new FirstTodayRules())->first(role: 'mentor', probe: static fn (string $rule): array => ['count' => 0]));
		self::assertNull(actual: (new FirstTodayRules())->first(role: 'care-coordinator', probe: static fn (string $rule): ?array => null));
		self::assertNull(actual: (new FirstTodayRules())->first(role: 'no-such-role', probe: static fn (string $rule): array => ['count' => 5]));
	}//end testNoRuleWithARowMeansNoCard()
}//end class
