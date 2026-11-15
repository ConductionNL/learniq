<?php

/**
 * A learner reads their own GradeEntry only once it is released.
 *
 * Released means what the portal shows (PortalResultReader): the entry is
 * `published` or `revised`, and its `visibleFrom` has passed or is not set.
 * OpenRegister applies the schema's read rules on the objects API, the list
 * query and every page built on them (such as /my-grades), so the rule holds
 * on every learner read path.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-grade-read-follows-visible-from/specs/grading/spec.md#requirement-a-learner-reads-their-own-grade-only-once-it-is-released
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Pins the learner read rules of GradeEntry.
 */
class GradeEntryLearnerReadTest extends TestCase {

	private const NOW = '2026-11-05 12:00:00';

	/**
	 * The learner (non-group) read rules of GradeEntry in the shipped register.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function learnerRules(): array {
		$register = json_decode(
			json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/learniq_register.json'),
			associative: true
		);

		$read = $register['components']['schemas']['GradeEntry']['authorization']['read'];

		return array_values(array_filter($read, 'is_array'));
	}//end learnerRules()

	/**
	 * Whether any learner rule grants the learner `alice` this entry at NOW.
	 *
	 * Evaluates `$userId`, `$now`, `$in`, `$lte` and `$exists` with
	 * OpenRegister's semantics: a missing value never satisfies a comparison,
	 * and `$exists: false` matches only a missing value. Any other operator
	 * fails the test, so a rule this evaluator cannot read is never passed.
	 *
	 * @param array<string, mixed> $entry The GradeEntry.
	 *
	 * @return bool
	 */
	private function grants(array $entry): bool {
		foreach (self::learnerRules() as $rule) {
			$this->assertSame(expected: 'authenticated', actual: $rule['group']);
			$all = true;
			foreach ($rule['match'] as $field => $expected) {
				$all = $all && $this->conditionHolds(value: ($entry[$field] ?? null), expected: $expected);
			}

			if ($all === true) {
				return true;
			}
		}

		return false;
	}//end grants()

	/**
	 * One match condition.
	 *
	 * @param mixed $value    The entry's value.
	 * @param mixed $expected The condition.
	 *
	 * @return bool
	 */
	private function conditionHolds(mixed $value, mixed $expected): bool {
		if ($expected === '$userId') {
			return $value === 'alice';
		}

		$this->assertIsArray(actual: $expected, message: 'A learner rule compares with an operator or $userId.');
		$operator = (string)array_key_first($expected);
		$operand  = $expected[$operator];

		return match ($operator) {
			'$in' => $value !== null && in_array($value, $operand, true),
			'$lte' => $value !== null && $operand === '$now' && strtotime((string)$value) <= strtotime(self::NOW),
			'$exists' => ($value !== null) === $operand,
			default => $this->fail(message: 'Unexpected operator ' . $operator . ' in a GradeEntry learner rule.'),
		};
	}//end conditionHolds()

	/**
	 * Which of the learner's own entries the learner reads.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function entries(): array {
		return [
			'published, visible since yesterday' => [['lifecycle' => 'published', 'visibleFrom' => '2026-11-04T08:00:00+00:00'], true],
			'published, no visibleFrom'          => [['lifecycle' => 'published'], true],
			'revised, visible'                   => [['lifecycle' => 'revised', 'visibleFrom' => '2026-11-01T08:00:00+00:00'], true],
			'published, visible from tomorrow'   => [['lifecycle' => 'published', 'visibleFrom' => '2026-11-06T08:00:00+00:00'], false],
			'published, visible later today'     => [['lifecycle' => 'published', 'visibleFrom' => '2026-11-05T16:00:00+00:00'], false],
			'concept'                            => [['lifecycle' => 'concept'], false],
			'invalidated'                        => [['lifecycle' => 'invalidated', 'visibleFrom' => '2026-11-01T08:00:00+00:00'], false],
			'no lifecycle'                       => [[], false],
		];
	}//end entries()

	/**
	 * A learner reads their own entry only once it is released.
	 *
	 * @param array<string, mixed> $entry    The entry, learner `alice`.
	 * @param bool                 $readable Whether alice reads it.
	 *
	 * @return void
	 *
	 * @dataProvider entries
	 */
	public function testALearnerReadsTheirOwnEntryOnlyOnceReleased(array $entry, bool $readable): void {
		$this->assertSame(expected: $readable, actual: $this->grants(entry: ['learnerId' => 'alice'] + $entry));
	}//end testALearnerReadsTheirOwnEntryOnlyOnceReleased()

	/**
	 * A released entry of another learner stays out.
	 *
	 * @return void
	 */
	public function testALearnerNeverReadsAnotherLearnersEntry(): void {
		$this->assertFalse(
			condition: $this->grants(entry: ['learnerId' => 'bob', 'lifecycle' => 'published', 'visibleFrom' => '2026-11-01T08:00:00+00:00'])
		);
	}//end testALearnerNeverReadsAnotherLearnersEntry()
}//end class
