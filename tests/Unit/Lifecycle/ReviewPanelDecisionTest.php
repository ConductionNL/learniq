<?php

/**
 * Learniq ReviewPanelDecision unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ReviewPanelDecision;
use PHPUnit\Framework\TestCase;

/**
 * The panel decides together: every member advises, and more than half advise the outcome.
 */
class ReviewPanelDecisionTest extends TestCase {

	private const PANEL = ['anna', 'bram', 'cem'];

	/**
	 * One advice row.
	 *
	 * @param string $memberId The member.
	 * @param string $advice   place, waitlist or reject.
	 *
	 * @return array<string,string>
	 */
	private function advice(string $memberId, string $advice): array {
		return ['memberId' => $memberId, 'advice' => $advice, 'applicationId' => 'app-1'];
	}//end advice()

	/**
	 * The rule for the three-member panel.
	 *
	 * @return ReviewPanelDecision
	 */
	private function panel(): ReviewPanelDecision {
		return new ReviewPanelDecision(panelMemberIds: self::PANEL);
	}//end panel()

	/**
	 * Three members advise place: the panel placed the applicant.
	 *
	 * @return void
	 */
	public function testThreeMembersAdvisingPlaceLetThePlacementThrough(): void {
		$rows = [
			$this->advice(memberId: 'anna', advice: 'place'),
			$this->advice(memberId: 'bram', advice: 'place'),
			$this->advice(memberId: 'cem', advice: 'place'),
		];

		self::assertNull(actual: $this->panel()->refusal(adviceRows: $rows, target: 'placed'));
	}//end testThreeMembersAdvisingPlaceLetThePlacementThrough()

	/**
	 * One member's advice is not the panel's decision.
	 *
	 * @return void
	 */
	public function testOneMemberCannotDecideAlone(): void {
		$refusal = $this->panel()->refusal(adviceRows: [$this->advice(memberId: 'anna', advice: 'place')], target: 'placed');

		self::assertSame(expected: 'The review panel has not decided yet: 1 of 3 members gave their advice.', actual: $refusal);
	}//end testOneMemberCannotDecideAlone()

	/**
	 * Two of three advising place is a majority; one of three is not.
	 *
	 * @return void
	 */
	public function testTheMajorityOfThePanelDecides(): void {
		$rows = [
			$this->advice(memberId: 'anna', advice: 'place'),
			$this->advice(memberId: 'bram', advice: 'place'),
			$this->advice(memberId: 'cem', advice: 'waitlist'),
		];

		self::assertNull(actual: $this->panel()->refusal(adviceRows: $rows, target: 'placed'));
		self::assertSame(
			expected: 'The review panel did not advise this decision: 1 of 3 members advised it.',
			actual: $this->panel()->refusal(adviceRows: $rows, target: 'waitlisted')
		);
		self::assertSame(
			expected: 'The review panel did not advise this decision: 0 of 3 members advised it.',
			actual: $this->panel()->refusal(adviceRows: $rows, target: 'rejected')
		);
	}//end testTheMajorityOfThePanelDecides()

	/**
	 * A tie on an even panel is no decision.
	 *
	 * @return void
	 */
	public function testATieIsNoDecision(): void {
		$panel = new ReviewPanelDecision(panelMemberIds: ['anna', 'bram']);
		$rows = [$this->advice(memberId: 'anna', advice: 'place'), $this->advice(memberId: 'bram', advice: 'reject')];

		self::assertNotNull(actual: $panel->refusal(adviceRows: $rows, target: 'placed'));
		self::assertNotNull(actual: $panel->refusal(adviceRows: $rows, target: 'rejected'));
	}//end testATieIsNoDecision()

	/**
	 * Advice from someone outside the panel, or an unknown value, is not counted.
	 *
	 * @return void
	 */
	public function testAdviceFromOutsideThePanelIsNotCounted(): void {
		$rows = [
			$this->advice(memberId: 'anna', advice: 'place'),
			$this->advice(memberId: 'bram', advice: 'place'),
			$this->advice(memberId: 'dirk', advice: 'place'),
			$this->advice(memberId: 'cem', advice: 'maybe'),
		];

		self::assertSame(
			expected: 'The review panel has not decided yet: 2 of 3 members gave their advice.',
			actual: $this->panel()->refusal(adviceRows: $rows, target: 'placed')
		);
	}//end testAdviceFromOutsideThePanelIsNotCounted()

	/**
	 * A round without a panel, or a state that is no decision, has no panel rule.
	 *
	 * @return void
	 */
	public function testWithoutAPanelThereIsNoRule(): void {
		self::assertNull(actual: (new ReviewPanelDecision(panelMemberIds: []))->refusal(adviceRows: [], target: 'placed'));
		self::assertNull(actual: (new ReviewPanelDecision(panelMemberIds: null))->refusal(adviceRows: [], target: 'placed'));
		self::assertNull(actual: $this->panel()->refusal(adviceRows: [], target: 'converted'));
	}//end testWithoutAPanelThereIsNoRule()

	/**
	 * The panel's members are read without blanks, repeats or other values.
	 *
	 * @return void
	 */
	public function testMembersAreReadCleanly(): void {
		self::assertSame(expected: ['anna', 'bram'], actual: (new ReviewPanelDecision(panelMemberIds: ['anna', '', 'anna', 7, 'bram']))->members());
		self::assertSame(expected: [], actual: (new ReviewPanelDecision(panelMemberIds: 'anna'))->members());
	}//end testMembersAreReadCleanly()
}//end class
