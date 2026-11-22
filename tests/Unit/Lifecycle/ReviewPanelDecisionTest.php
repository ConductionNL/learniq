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
	 * Three members advise place: the panel placed the applicant.
	 *
	 * @return void
	 */
	public function testThreeMembersAdvisingPlaceLetThePlacementThrough(): void {
		$rows = [$this->advice('anna', 'place'), $this->advice('bram', 'place'), $this->advice('cem', 'place')];

		self::assertNull((new ReviewPanelDecision())->refusal(self::PANEL, $rows, 'placed'));
	}//end testThreeMembersAdvisingPlaceLetThePlacementThrough()

	/**
	 * One member's advice is not the panel's decision.
	 *
	 * @return void
	 */
	public function testOneMemberCannotDecideAlone(): void {
		$refusal = (new ReviewPanelDecision())->refusal(self::PANEL, [$this->advice('anna', 'place')], 'placed');

		self::assertSame('The review panel has not decided yet: 1 of 3 members gave their advice.', $refusal);
	}//end testOneMemberCannotDecideAlone()

	/**
	 * Two of three advising place is a majority; one of three is not.
	 *
	 * @return void
	 */
	public function testTheMajorityOfThePanelDecides(): void {
		$rows = [$this->advice('anna', 'place'), $this->advice('bram', 'place'), $this->advice('cem', 'waitlist')];
		$rule = new ReviewPanelDecision();

		self::assertNull($rule->refusal(self::PANEL, $rows, 'placed'));
		self::assertSame(
			'The review panel did not advise this decision: 1 of 3 members advised it.',
			$rule->refusal(self::PANEL, $rows, 'waitlisted')
		);
		self::assertSame(
			'The review panel did not advise this decision: 0 of 3 members advised it.',
			$rule->refusal(self::PANEL, $rows, 'rejected')
		);
	}//end testTheMajorityOfThePanelDecides()

	/**
	 * A tie on an even panel is no decision.
	 *
	 * @return void
	 */
	public function testATieIsNoDecision(): void {
		$panel = ['anna', 'bram'];
		$rows = [$this->advice('anna', 'place'), $this->advice('bram', 'reject')];

		self::assertNotNull((new ReviewPanelDecision())->refusal($panel, $rows, 'placed'));
		self::assertNotNull((new ReviewPanelDecision())->refusal($panel, $rows, 'rejected'));
	}//end testATieIsNoDecision()

	/**
	 * Advice from someone outside the panel, or an unknown value, is not counted.
	 *
	 * @return void
	 */
	public function testAdviceFromOutsideThePanelIsNotCounted(): void {
		$rows = [
			$this->advice('anna', 'place'),
			$this->advice('bram', 'place'),
			$this->advice('dirk', 'place'),
			$this->advice('cem', 'maybe'),
		];

		self::assertSame(
			'The review panel has not decided yet: 2 of 3 members gave their advice.',
			(new ReviewPanelDecision())->refusal(self::PANEL, $rows, 'placed')
		);
	}//end testAdviceFromOutsideThePanelIsNotCounted()

	/**
	 * A round without a panel, or a state that is no decision, has no panel rule.
	 *
	 * @return void
	 */
	public function testWithoutAPanelThereIsNoRule(): void {
		$rule = new ReviewPanelDecision();

		self::assertNull($rule->refusal([], [], 'placed'));
		self::assertNull($rule->refusal(null, [], 'placed'));
		self::assertNull($rule->refusal(self::PANEL, [], 'converted'));
	}//end testWithoutAPanelThereIsNoRule()

	/**
	 * The panel's members are read without blanks, repeats or other values.
	 *
	 * @return void
	 */
	public function testMembersAreReadCleanly(): void {
		self::assertSame(['anna', 'bram'], (new ReviewPanelDecision())->members(['anna', '', 'anna', 7, 'bram']));
		self::assertSame([], (new ReviewPanelDecision())->members('anna'));
	}//end testMembersAreReadCleanly()
}//end class
