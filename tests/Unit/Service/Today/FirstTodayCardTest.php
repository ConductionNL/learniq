<?php

/**
 * What the First today card says and opens per rule.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
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
			static fn (string $one, string $many, int $count): string => str_replace('%n', (string) $count, [$one, $many][(int) ($count !== 1)])
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
			FirstTodayRules::ROLE_BPV_COACH,
			FirstTodayRules::ROLE_EXAM_COMMITTEE,
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
		$unknown = ['role' => 'head', 'rule' => 'no-such-rule', 'count' => 2];

		self::assertSame(expected: $none, actual: $this->card()->describe(card: $none));
		self::assertSame(expected: $unknown, actual: $this->card()->describe(card: $unknown));
	}//end testNoRuleOrARuleWithoutACardAddsNothing()

	/**
	 * The BPV-begeleider's cards say how late, how many hours wait and since when, and open the BPV lists.
	 *
	 * @return void
	 */
	public function testTheBpvCardsNameTheLateLineAndTheHours(): void {
		$daan = $this->card()->describe(
			card: ['role' => 'bpv-coach', 'rule' => 'placement-without-company', 'count' => 1, 'lateLabel' => '5 weken te laat']
		);
		$plain = $this->card()->describe(card: ['role' => 'bpv-coach', 'rule' => 'placement-without-company', 'count' => 2, 'lateLabel' => '']);
		$visit = $this->card()->describe(card: ['role' => 'bpv-coach', 'rule' => 'visit-without-date', 'count' => 1]);
		$hours = $this->card()->describe(card: ['role' => 'bpv-coach', 'rule' => 'hours-waiting', 'count' => 2, 'hours' => 30.5, 'isoWeek' => '2026-W40']);
		$noWeek = $this->card()->describe(card: ['role' => 'bpv-coach', 'rule' => 'hours-waiting', 'count' => 1, 'hours' => 24.0, 'isoWeek' => '']);

		self::assertSame(expected: '1 student of yours has no BPV company yet', actual: $daan['title']);
		self::assertSame(expected: 'The BPV period is running: 5 weken te laat.', actual: $daan['reason']);
		self::assertSame(expected: 'BpvPlacements', actual: $daan['page']);
		self::assertSame(expected: '2 students of yours have no BPV company yet', actual: $plain['title']);
		self::assertSame(expected: 'The BPV period is running.', actual: $plain['reason']);
		self::assertSame(expected: '1 company visit of yours has no date yet', actual: $visit['title']);
		self::assertSame(expected: 'Propose a date to the workplace trainer.', actual: $visit['reason']);
		self::assertSame(expected: 'BpvVisits', actual: $visit['page']);
		self::assertSame(expected: '2 hour weeks have waited more than a week for their company', actual: $hours['title']);
		self::assertSame(expected: '30.5 hours wait for approval, the longest since 2026-W40.', actual: $hours['reason']);
		self::assertSame(expected: 'BpvPlacements', actual: $hours['page']);
		self::assertSame(expected: '24 hours wait for approval.', actual: $noWeek['reason']);
	}//end testTheBpvCardsNameTheLateLineAndTheHours()

	/**
	 * The page file names only rules the rule list knows, and skips its comment.
	 *
	 * @return void
	 */
	public function testThePagesNameKnownRules(): void {
		$pages = $this->card()->pages();

		self::assertCount(expectedCount: 17, haystack: $pages);
		self::assertSame(expected: [], actual: array_diff(array_keys($pages), (new FirstTodayRules())->allRules()));
	}//end testThePagesNameKnownRules()

	/**
	 * The committee's cards name the day to decide by and the examiner's answer, and the sittings the results come from.
	 *
	 * @return void
	 */
	public function testTheExamCommitteeCardsNameTheTermAndTheSittings(): void {
		$responded = $this->card()->describe(
			card: ['role' => 'exam-committee', 'rule' => 'objection-past-half', 'count' => 1, 'decideBy' => '2026-10-09T23:59:00+02:00', 'responded' => true]
		);
		$open = $this->card()->describe(
			card: ['role' => 'exam-committee', 'rule' => 'objection-past-half', 'count' => 2, 'decideBy' => '2026-10-09', 'responded' => false]
		);
		$noDay = $this->card()->describe(card: ['role' => 'exam-committee', 'rule' => 'objection-past-half', 'count' => 1, 'decideBy' => null]);
		$results = $this->card()->describe(card: ['role' => 'exam-committee', 'rule' => 'results-waiting', 'count' => 14, 'sittings' => 3]);

		self::assertSame(expected: '1 objection is past half its decision time', actual: $responded['title']);
		self::assertSame(expected: 'Decide before 2026-10-09. The examiner has responded.', actual: $responded['reason']);
		self::assertSame(expected: 'ExamObjections', actual: $responded['page']);
		self::assertSame(expected: '2 objections are past half their decision time', actual: $open['title']);
		self::assertSame(expected: 'Decide before 2026-10-09.', actual: $open['reason']);
		self::assertSame(expected: 'Decide within the term.', actual: $noDay['reason']);
		self::assertSame(expected: '14 results have waited five working days to be established', actual: $results['title']);
		self::assertSame(expected: 'From 3 sittings.', actual: $results['reason']);
		self::assertSame(expected: 'ExamSittings', actual: $results['page']);
	}//end testTheExamCommitteeCardsNameTheTermAndTheSittings()
}//end class
