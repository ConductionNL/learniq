<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ExamObjectionDecisionGuard;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Lifecycle\ExamObjectionDecisionGuard
 */
class ExamObjectionDecisionGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * An upheld result with a rationale is decided.
	 *
	 * @return void
	 */
	public function testAnUpheldResultWithARationaleIsAllowed(): void {
		$verdict = (new ExamObjectionDecisionGuard())->check(
			['decisionOutcome' => 'result-upheld', 'decisionRationale' => 'De beoordeling volgt het beoordelingsmodel.'],
			'decide',
			'committee-1'
		);

		self::assertAllowed(verdict: $verdict);
	}//end testAnUpheldResultWithARationaleIsAllowed()

	/**
	 * A decision without a rationale is refused in words.
	 *
	 * @return void
	 */
	public function testADecisionWithoutARationaleIsRefused(): void {
		$guard = new ExamObjectionDecisionGuard();

		$verdict = $guard->check(['decisionOutcome' => 'result-upheld', 'decisionRationale' => '  '], 'decide', 'committee-1');

		self::assertDenied(verdict: $verdict);
		self::assertSame(expected: 'A decision on an objection needs a rationale.', actual: $verdict->getMessage());
	}//end testADecisionWithoutARationaleIsRefused()

	/**
	 * A decision must say whether the result is upheld or changed.
	 *
	 * @return void
	 */
	public function testADecisionWithoutAnOutcomeIsRefused(): void {
		$verdict = (new ExamObjectionDecisionGuard())->check(['decisionRationale' => 'Gegrond.'], 'decide', 'committee-1');

		self::assertDenied(verdict: $verdict);
		self::assertSame(expected: 'Say whether the result is upheld or changed.', actual: $verdict->getMessage());
	}//end testADecisionWithoutAnOutcomeIsRefused()

	/**
	 * A changed result needs the new result, and is allowed with one.
	 *
	 * @return void
	 */
	public function testAChangedResultNeedsTheNewResult(): void {
		$guard  = new ExamObjectionDecisionGuard();
		$object = ['decisionOutcome' => 'result-changed', 'decisionRationale' => 'Het tweede criterium is onjuist gescoord.'];

		$missing = $guard->check($object, 'decide', 'committee-1');
		self::assertDenied(verdict: $missing);
		self::assertSame(expected: 'A decision that changes the result needs the new result.', actual: $missing->getMessage());

		self::assertAllowed(verdict: $guard->check($object + ['newValue' => 6.5], 'decide', 'committee-1'));
	}//end testAChangedResultNeedsTheNewResult()
}//end class
