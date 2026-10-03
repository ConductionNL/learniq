<?php

/**
 * Tests for the attempts rule of AssessmentAccessPolicy, shared by the portal
 * catalogue and the in-app attempt gate.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\AssessmentAccessPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AssessmentAccessPolicy::attemptsBlock().
 */
class AssessmentAccessPolicyAttemptsTest extends TestCase {

	/**
	 * Cases: the test's maxAttempts, attempts used, and whether another is refused.
	 *
	 * @return array<string, array{0: mixed, 1: int, 2: bool}>
	 */
	public static function cases(): array {
		return [
			'first attempt of a one-attempt test' => [1, 0, false],
			'second attempt of a one-attempt test' => [1, 1, true],
			'no maxAttempts means one' => [null, 1, true],
			'third attempt of a three-attempt test' => [3, 2, false],
			'fourth attempt of a three-attempt test' => [3, 3, true],
			'zero is read as one' => [0, 1, true],
			'a non-number is read as one' => ['many', 1, true],
		];
	}//end cases()

	/**
	 * Another attempt is refused once maxAttempts are used.
	 *
	 * @param mixed $maxAttempts Assessment.maxAttempts.
	 * @param int $used Attempts the learner already has.
	 * @param bool $refused Whether another attempt is refused.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 *
	 * @spec openspec/specs/assessment/spec.md#scenario-a-second-attempt-on-a-one-attempt-test-is-refused
	 */
	public function testAttemptsBlock(mixed $maxAttempts, int $used, bool $refused): void {
		$assessment = ['title' => 'Toets'];
		if ($maxAttempts !== null) {
			$assessment['maxAttempts'] = $maxAttempts;
		}

		$block = (new AssessmentAccessPolicy())->attemptsBlock(assessment: $assessment, attemptsUsed: $used);

		if ($refused === false) {
			self::assertNull($block);
			return;
		}

		self::assertSame(AssessmentAccessPolicy::REASON_ATTEMPTS_USED, $block['reason']);
		self::assertStringContainsString('all attempts', $block['message']);
	}//end testAttemptsBlock()
}//end class
