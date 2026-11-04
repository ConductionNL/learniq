<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling;

use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Timetabling\SessionOverlapEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins which overlaps two lessons have and what evidences each kind, against
 * golden/SessionOverlapEvaluator.json.
 *
 * @covers \OCA\Learniq\Timetabling\SessionOverlapEvaluator
 */
class SessionOverlapEvaluatorGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/SessionOverlapEvaluator.json';

	/**
	 * Every pair answers as recorded.
	 *
	 * @return void
	 */
	public function testPairsMatchGolden(): void {
		$evaluator = new SessionOverlapEvaluator();
		$actual = [];
		foreach ($this->intervals() as $name => [$first, $second]) {
			$actual['overlaps: ' . $name] = [$evaluator->overlaps(sessionA: $first, sessionB: $second), $evaluator->overlaps(sessionA: $second, sessionB: $first)];
		}

		foreach ($this->kinds() as $name => [$first, $second, $cohortA, $cohortB]) {
			$actual['kinds: ' . $name] = $evaluator->overlapKinds(sessionA: $first, sessionB: $second, cohortA: $cohortA, cohortB: $cohortB);
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testPairsMatchGolden()

	/**
	 * Interval pairs.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>}>
	 */
	private function intervals(): array {
		$at = static fn (string $from, string $to): array => ['startsAt' => '2026-10-08T' . $from . ':00+02:00', 'endsAt' => '2026-10-08T' . $to . ':00+02:00'];

		return [
			'apart' => [$at('09:00', '10:00'), $at('11:00', '12:00')],
			'touching' => [$at('09:00', '10:00'), $at('10:00', '11:00')],
			'partly' => [$at('09:00', '10:00'), $at('09:30', '10:30')],
			'inside' => [$at('09:00', '12:00'), $at('10:00', '11:00')],
			'the same' => [$at('09:00', '10:00'), $at('09:00', '10:00')],
			'other offset' => [$at('09:00', '10:00'), ['startsAt' => '2026-10-08T07:30:00Z', 'endsAt' => '2026-10-08T08:30:00Z']],
			'first start missing' => [['endsAt' => '2026-10-08T10:00:00Z'], $at('09:00', '10:00')],
			'first end missing' => [['startsAt' => '2026-10-08T10:00:00Z'], $at('09:00', '10:00')],
			'second start unreadable' => [$at('09:00', '10:00'), ['startsAt' => 'nonsense', 'endsAt' => '2026-10-08T10:00:00Z']],
			'second end unreadable' => [$at('09:00', '10:00'), ['startsAt' => '2026-10-08T10:00:00Z', 'endsAt' => 'nonsense']],
		];
	}//end intervals()

	/**
	 * Lesson and group pairs.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>|null,3:array<string,mixed>|null}>
	 */
	private function kinds(): array {
		$jan = ['teacherIds' => ['jan', 'piet'], 'learnerIds' => ['a', 'b']];
		$piet = ['teacherIds' => ['piet', 'jan'], 'learnerIds' => ['b', 'c', 'a']];
		$other = ['teacherIds' => ['kees'], 'learnerIds' => ['x']];

		return [
			'nothing shared' => [['cohortId' => 'c-1', 'roomId' => 'r-1'], ['cohortId' => 'c-2', 'roomId' => 'r-2'], $jan, $other],
			'no groups' => [['cohortId' => 'c-1'], ['cohortId' => 'c-2'], null, null],
			'one group unknown' => [['cohortId' => 'c-1'], ['cohortId' => 'c-2'], $jan, null],
			'other group unknown' => [['cohortId' => 'c-1'], ['cohortId' => 'c-2'], null, $jan],
			'teachers through the groups' => [['cohortId' => 'c-1'], ['cohortId' => 'c-2'], $jan, $piet],
			'own teachers' => [['cohortId' => 'c-1', 'teacherUserId' => 'jan'], ['cohortId' => 'c-2', 'teacherUserId' => 'jan'], $other, $other],
			'own teacher over the group' => [['cohortId' => 'c-1', 'teacherUserId' => 'kees'], ['cohortId' => 'c-2'], $jan, $other],
			'substitute over own teacher' => [['substituteTeacherId' => 'sub', 'teacherUserId' => 'jan'], ['teacherUserId' => 'sub'], null, null],
			'substitute takes the teacher away' => [['substituteTeacherId' => 'sub', 'teacherUserId' => 'jan'], ['teacherUserId' => 'jan'], null, null],
			'the same room' => [['roomId' => 'r-1'], ['roomId' => 'r-1'], null, null],
			'one room id only' => [['roomId' => 'r-1', 'roomReference' => 'A1'], ['roomReference' => 'A1'], null, null],
			'the other room id only' => [['roomReference' => 'A1'], ['roomId' => 'r-1', 'roomReference' => 'A1'], null, null],
			'room ids differ, codes match' => [['roomId' => 'r-1', 'roomReference' => 'A1'], ['roomId' => 'r-2', 'roomReference' => 'A1'], null, null],
			'the same code' => [['roomReference' => 'A1'], ['roomReference' => 'A1'], null, null],
			'other codes' => [['roomReference' => 'A1'], ['roomReference' => 'A2'], null, null],
			'blank codes' => [['roomReference' => ''], ['roomReference' => ''], null, null],
			'the same group' => [['cohortId' => 'c-1'], ['cohortId' => 'c-1'], $jan, $jan],
			'blank groups' => [['cohortId' => ''], ['cohortId' => ''], $jan, $jan],
			'a shared learner' => [['cohortId' => 'c-1'], ['cohortId' => 'c-2'], ['learnerIds' => ['a', 'b']], ['learnerIds' => ['b', 'a']]],
			'learners without a second group id' => [['cohortId' => 'c-1'], [], ['learnerIds' => ['a']], ['learnerIds' => ['a']]],
			'learners without a first group id' => [[], ['cohortId' => 'c-2'], ['learnerIds' => ['a']], ['learnerIds' => ['a']]],
			'odd learner lists' => [['cohortId' => 'c-1'], ['cohortId' => 'c-2'], ['learnerIds' => 'a'], ['learnerIds' => ['a', 7, '']]],
			'all-digit teacher ids through the groups' => [['cohortId' => 'c-1'], ['cohortId' => 'c-2'], ['teacherIds' => ['123']], ['teacherIds' => ['123']]],
			'an all-digit own teacher against a group' => [['cohortId' => 'c-1', 'teacherUserId' => '123'], ['cohortId' => 'c-2'], null, ['teacherIds' => ['123']]],
			'all-digit learner ids' => [['cohortId' => 'c-1'], ['cohortId' => 'c-2'], ['learnerIds' => ['123']], ['learnerIds' => ['123']]],
			'all-digit group ids' => [['cohortId' => '7'], ['cohortId' => '7'], null, null],
			'all-digit room ids' => [['roomId' => '7'], ['roomId' => '7'], null, null],
			'everything' => [
				['cohortId' => 'c-1', 'roomId' => 'r-1', 'teacherUserId' => 'jan'],
				['cohortId' => 'c-1', 'roomId' => 'r-1', 'teacherUserId' => 'jan'],
				$jan,
				$jan,
			],
		];
	}//end kinds()
}//end class
