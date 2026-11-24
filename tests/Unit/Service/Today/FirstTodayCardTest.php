<?php

/**
 * What the First today card says and opens per rule.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use OCA\Learniq\Service\Today\FirstTodayCard;
use OCA\Learniq\Service\Today\FirstTodayRules;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Today\FirstTodayCard
 */
class FirstTodayCardTest extends TestCase {

	/**
	 * The card writer with a translator that fills the English source.
	 *
	 * @return FirstTodayCard
	 */
	private function card(): FirstTodayCard {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $parameters=[]): string => vsprintf($text, (array) $parameters)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count): string => str_replace('%n', (string) $count, $count === 1 ? $one : $many)
		);

		return new FirstTodayCard(l10n: $l10n);
	}//end card()

	/**
	 * Meester Daan's open register names the two parent reports and opens the roll call.
	 *
	 * @return void
	 */
	public function testTheOpenRegisterNamesTheParentReports(): void {
		$card = $this->card()->describe(
			card: ['role' => 'group-teacher', 'rule' => 'register-not-filled', 'count' => 1, 'cohortName' => 'Groep 7', 'reportCount' => 2]
		);

		self::assertSame(expected: "Today's register is not filled in yet", actual: $card['title']);
		self::assertSame(expected: '2 pupils were reported absent by their parents.', actual: $card['reason']);
		self::assertSame(expected: 'RollCall', actual: $card['page']);
		self::assertSame(expected: 'Groep 7', actual: $card['cohortName']);
	}//end testTheOpenRegisterNamesTheParentReports()

	/**
	 * Without parent reports the line names the group whose first lesson started, and without a name it says so plainly.
	 *
	 * @return void
	 */
	public function testTheOpenRegisterWithoutReportsNamesTheGroup(): void {
		$named = $this->card()->describe(card: ['rule' => 'register-not-filled', 'count' => 1, 'cohortName' => 'Groep 7', 'reportCount' => 0]);
		$plain = $this->card()->describe(card: ['rule' => 'register-not-filled', 'count' => 1, 'cohortName' => ' ', 'reportCount' => 0]);

		self::assertSame(expected: 'The first lesson of Groep 7 has started.', actual: $named['reason']);
		self::assertSame(expected: 'The first lesson has started.', actual: $plain['reason']);
	}//end testTheOpenRegisterWithoutReportsNamesTheGroup()

	/**
	 * Every rule of a role the simple Today is for gets a sentence, a line and a page.
	 *
	 * @return void
	 */
	public function testEveryTodayRuleHasASentenceALineAndAPage(): void {
		$roles = [
			FirstTodayRules::ROLE_GROUP_TEACHER,
			FirstTodayRules::ROLE_SUBJECT_TEACHER,
			FirstTodayRules::ROLE_MENTOR,
			FirstTodayRules::ROLE_CARE_COORDINATOR,
			FirstTodayRules::ROLE_HEAD,
		];
		foreach ($roles as $role) {
			foreach (FirstTodayRules::ORDER[$role] as $rule) {
				$card = $this->card()->describe(card: ['role' => $role, 'rule' => $rule, 'count' => 3, 'total' => 5, 'missing' => 4]);

				self::assertNotSame(expected: '', actual: (string) ($card['title'] ?? ''), message: $rule);
				self::assertNotSame(expected: '', actual: (string) ($card['reason'] ?? ''), message: $rule);
				self::assertMatchesRegularExpression(pattern: '/^[A-Z][A-Za-z]+$/', string: (string) ($card['page'] ?? ''), message: $rule);
				self::assertSame(expected: 3, actual: $card['count'], message: $rule);
			}
		}
	}//end testEveryTodayRuleHasASentenceALineAndAPage()

	/**
	 * The counts go into the sentence, in the singular for one.
	 *
	 * @return void
	 */
	public function testTheCountIsInTheSentence(): void {
		$one = $this->card()->describe(card: ['rule' => 'leave-past-half', 'count' => 1]);
		$two = $this->card()->describe(card: ['rule' => 'leave-past-half', 'count' => 2]);
		$groups = $this->card()->describe(card: ['rule' => 'registers-not-filled-at-930', 'count' => 2, 'total' => 9]);
		$hands = $this->card()->describe(card: ['rule' => 'missing-hand-ins', 'count' => 1, 'missing' => 6]);

		self::assertSame(expected: '1 leave request is past half its decision time', actual: $one['title']);
		self::assertSame(expected: '2 leave requests are past half their decision time', actual: $two['title']);
		self::assertSame(expected: 'LeaveRequests', actual: $two['page']);
		self::assertSame(expected: '7 of 9 groups with lessons today filled theirs in.', actual: $groups['reason']);
		self::assertSame(expected: '6 hand-ins are still missing.', actual: $hands['reason']);
	}//end testTheCountIsInTheSentence()

	/**
	 * The lesson and the round are named when the facts carry them.
	 *
	 * @return void
	 */
	public function testTheLessonAndTheRoundAreNamedWhenKnown(): void {
		$lesson = $this->card()->describe(card: ['rule' => 'lesson-register-open', 'count' => 1, 'sessionTitle' => 'Economie H5a']);
		$noLesson = $this->card()->describe(card: ['rule' => 'lesson-register-open', 'count' => 1, 'sessionTitle' => '']);
		$round = $this->card()->describe(card: ['rule' => 'mentor-talks-closing', 'count' => 11, 'roundName' => 'Mentorgesprekken']);
		$noRound = $this->card()->describe(card: ['rule' => 'mentor-talks-closing', 'count' => 11]);

		self::assertSame(expected: 'The first is Economie H5a.', actual: $lesson['reason']);
		self::assertSame(expected: 'The lesson has ended.', actual: $noLesson['reason']);
		self::assertSame(expected: 'Booking for Mentorgesprekken closes soon.', actual: $round['reason']);
		self::assertSame(expected: 'Booking closes soon.', actual: $noRound['reason']);
		self::assertSame(expected: 'ConferenceRounds', actual: $round['page']);
	}//end testTheLessonAndTheRoundAreNamedWhenKnown()

	/**
	 * No rule, or a rule of a role the simple Today is not for, keeps the answer as it was: no title, no card.
	 *
	 * @return void
	 */
	public function testNoRuleOrARuleWithoutACardAddsNothing(): void {
		$none = ['role' => 'group-teacher', 'rule' => null, 'count' => 0];
		$placement = ['role' => 'bpv-coach', 'rule' => FirstTodayRules::HOURS_WAITING, 'count' => 2];

		self::assertSame(expected: $none, actual: $this->card()->describe(card: $none));
		self::assertSame(expected: $placement, actual: $this->card()->describe(card: $placement));
	}//end testNoRuleOrARuleWithoutACardAddsNothing()

	/**
	 * The page file names only rules the rule list knows, and skips its comment.
	 *
	 * @return void
	 */
	public function testThePagesNameKnownRules(): void {
		$pages = $this->card()->pages();

		self::assertCount(expectedCount: 12, haystack: $pages);
		self::assertSame(expected: [], actual: array_diff(array_keys($pages), (new FirstTodayRules())->allRules()));
	}//end testThePagesNameKnownRules()
}//end class
