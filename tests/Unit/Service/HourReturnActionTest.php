<?php

/**
 * Tests for HourReturnAction.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\HourReturnAction;
use PHPUnit\Framework\TestCase;

/**
 * The link under a returned week's message opens her hours page on that week.
 */
class HourReturnActionTest extends TestCase {

	/**
	 * A returned week links to the hours page with the week chosen.
	 *
	 * @return void
	 */
	public function testAReturnedWeekLinksToItsHoursPage(): void {
		self::assertSame(
			['label' => 'Uren aanpassen', 'href' => '/mijn/learniq/studentHourWeeks/ee030040-0000-4000-8000-000000000001'],
			HourReturnAction::of(weekId: 'ee030040-0000-4000-8000-000000000001')
		);
	}//end testAReturnedWeekLinksToItsHoursPage()

	/**
	 * A week without an id, or with one that is not a plain id, gets no link
	 * rather than an address that could leave the page.
	 *
	 * @return void
	 */
	public function testAWeekWithoutAPlainIdGetsNoLink(): void {
		foreach (['', '../../settings', 'a/b', '//evil.example', "ee03\n"] as $id) {
			self::assertNull(HourReturnAction::of(weekId: $id), $id);
		}
	}//end testAWeekWithoutAPlainIdGetsNoLink()
}//end class
