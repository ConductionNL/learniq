<?php

/**
 * A learner reads the feedback summary of their own Submission, once a
 * PeerReview on it is released, and never another learner's.
 *
 * OpenRegister applies the schema's read rules on the objects API and every
 * list built on it, so the rule is what keeps a summary away from other
 * learners. The summary itself carries no reviewer identity (see
 * PeerFeedbackAggregatorTest); this pins who may read it.
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
 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/assignments/spec.md#requirement-a-learner-reads-the-feedback-summary-of-their-own-submission-with-reviewers-anonymised
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Pins the read rules of PeerFeedbackSummary.
 */
class PeerFeedbackSummaryLearnerReadTest extends TestCase {

	/**
	 * The shipped PeerFeedbackSummary schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		$register = json_decode(
			json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/learniq_register.json'),
			associative: true
		);

		return $register['components']['schemas']['PeerFeedbackSummary'];
	}//end schema()

	/**
	 * Whether any learner (non-group) rule grants the learner `fenna` this summary.
	 *
	 * Evaluates `$contains` with `$userId` and `$gte` with OpenRegister's
	 * semantics: a missing value never satisfies a comparison. Any other
	 * operator fails the test, so a rule this evaluator cannot read is never
	 * passed.
	 *
	 * @param array<string, mixed> $summary The PeerFeedbackSummary.
	 *
	 * @return bool
	 */
	private function grants(array $summary): bool {
		$rules = array_values(array_filter(self::schema()['authorization']['read'], 'is_array'));
		$this->assertNotEmpty(actual: $rules, message: 'PeerFeedbackSummary has no learner read rule.');

		foreach ($rules as $rule) {
			$this->assertSame(expected: 'authenticated', actual: $rule['group']);
			$all = true;
			foreach ($rule['match'] as $field => $expected) {
				$all = $all && $this->conditionHolds(value: ($summary[$field] ?? null), expected: $expected);
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
	 * @param mixed $value    The summary's value.
	 * @param mixed $expected The condition.
	 *
	 * @return bool
	 */
	private function conditionHolds(mixed $value, mixed $expected): bool {
		$this->assertIsArray(actual: $expected, message: 'A learner rule compares with an operator.');
		$operator = (string)array_key_first($expected);
		$operand  = $expected[$operator];

		return match ($operator) {
			'$contains' => $operand === '$userId' && is_array($value) && in_array('fenna', $value, true),
			'$gte' => is_numeric($value) && $value >= $operand,
			default => $this->fail(message: 'Unexpected operator ' . $operator . ' in a PeerFeedbackSummary learner rule.'),
		};
	}//end conditionHolds()

	/**
	 * Which summaries the learner `fenna` reads.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function summaries(): array {
		return [
			'her own, two released reviews'        => [['learnerIds' => ['fenna'], 'reviewCount' => 2], true],
			'her group work, one released review'  => [['learnerIds' => ['daan', 'fenna'], 'reviewCount' => 1], true],
			'her own, nothing released'            => [['learnerIds' => ['fenna'], 'reviewCount' => 0], false],
			'her own, no count yet'                => [['learnerIds' => ['fenna']], false],
			'another learner\'s, released'         => [['learnerIds' => ['daan'], 'reviewCount' => 1], false],
			'no learners named'                    => [['learnerIds' => [], 'reviewCount' => 1], false],
			'learnerIds missing'                   => [['reviewCount' => 1], false],
		];
	}//end summaries()

	/**
	 * A learner reads the summary of their own Submission only once a review is released.
	 *
	 * @param array<string, mixed> $summary  The summary.
	 * @param bool                 $readable Whether fenna reads it.
	 *
	 * @return void
	 *
	 * @dataProvider summaries
	 */
	public function testALearnerReadsOnlyTheirOwnReleasedSummary(array $summary, bool $readable): void {
		$this->assertSame(expected: $readable, actual: $this->grants(summary: $summary));
	}//end testALearnerReadsOnlyTheirOwnReleasedSummary()

	/**
	 * The groups that read the Submission keep reading its summary.
	 *
	 * @return void
	 */
	public function testStaffWhoReadTheSubmissionReadItsSummary(): void {
		$register = json_decode(
			json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/learniq_register.json'),
			associative: true
		);
		$submissionGroups = array_values(array_filter($register['components']['schemas']['Submission']['authorization']['read'], 'is_string'));
		$summaryGroups    = array_values(array_filter(self::schema()['authorization']['read'], 'is_string'));

		$this->assertSame(expected: $submissionGroups, actual: $summaryGroups);
	}//end testStaffWhoReadTheSubmissionReadItsSummary()

	/**
	 * Nothing in the summary's shape can carry a reviewer's identity.
	 *
	 * @return void
	 */
	public function testTheSummaryHasNoReviewerField(): void {
		$encoded = (string)json_encode(self::schema()['properties']);

		$this->assertStringNotContainsString(needle: 'reviewerId', haystack: $encoded);
		$this->assertStringNotContainsString(needle: 'peerReviewId', haystack: $encoded);
	}//end testTheSummaryHasNoReviewerField()
}//end class
