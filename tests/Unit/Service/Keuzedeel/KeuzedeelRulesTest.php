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
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-an-mbo-student-chooses-a-keuzedeel-inside-its-window
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Keuzedeel;

use DateTimeImmutable;
use OCA\Learniq\Service\Keuzedeel\KeuzedeelRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Keuzedeel\KeuzedeelRules
 * @uses   \OCA\Learniq\Service\Portal\CourseDayLines
 */
class KeuzedeelRulesTest extends TestCase {

	/**
	 * The period 3 offer of the scenario.
	 *
	 * @var array<string, mixed>
	 */
	private const OFFER = [
		'id' => 'kd-1', 'code' => 'K0125', 'name' => 'Verdieping Engels', 'period' => '3',
		'choiceOpensOn' => '2026-09-28', 'choiceClosesOn' => '2026-10-16', 'places' => 2, 'lifecycle' => 'offered',
	];

	/**
	 * Milan's page on 5 October: not yet chosen, until 16 October.
	 *
	 * @return void
	 */
	public function testNotYetChosen(): void {
		self::assertSame(
			'Keuzedeel periode 3: Nog niet gekozen, kan tot 16 oktober',
			(new KeuzedeelRules())->line([self::OFFER], [], new DateTimeImmutable('2026-10-05T10:00:00+02:00'))
		);
	}//end testNotYetChosen()

	/**
	 * After choosing, the line names the choice; without offers or choices there is no line.
	 *
	 * @return void
	 */
	public function testChosenAndNothing(): void {
		$rules = new KeuzedeelRules();
		$mine  = [['offerId' => 'kd-1', 'period' => '3', 'offerName' => 'K0125 Verdieping Engels', 'lifecycle' => 'chosen']];

		self::assertSame('Keuzedeel periode 3: K0125 Verdieping Engels gekozen', $rules->line([self::OFFER], $mine, new DateTimeImmutable('2026-10-05')));
		self::assertNull($rules->line([], [], new DateTimeImmutable('2026-10-05')));
	}//end testChosenAndNothing()

	/**
	 * Inside the window with a place free, she may choose.
	 *
	 * @return void
	 */
	public function testInsideTheWindowSheMayChoose(): void {
		$rules = new KeuzedeelRules();

		self::assertNull($rules->refusal(self::OFFER, [['lifecycle' => 'withdrawn']], [], new DateTimeImmutable('2026-10-16T23:30:00+02:00')));
		self::assertNull($rules->refusal(self::OFFER, [], [], new DateTimeImmutable('2026-09-28T00:10:00+02:00')));
	}//end testInsideTheWindowSheMayChoose()

	/**
	 * Every refusal is in words.
	 *
	 * @return void
	 */
	public function testRefusalsInWords(): void {
		$rules = new KeuzedeelRules();
		$day   = new DateTimeImmutable('2026-10-05T10:00:00+02:00');

		self::assertSame('Je kunt dit keuzedeel kiezen vanaf 28 september.', $rules->refusal(self::OFFER, [], [], new DateTimeImmutable('2026-09-27T12:00:00+02:00')));
		self::assertSame('De keuze voor dit keuzedeel is gesloten.', $rules->refusal(self::OFFER, [], [], new DateTimeImmutable('2026-10-17T00:30:00+02:00')));
		self::assertSame('Dit keuzedeel is vol.', $rules->refusal(self::OFFER, [['lifecycle' => 'chosen'], ['lifecycle' => 'placed']], [], $day));
		self::assertSame('Je hebt dit keuzedeel al gekozen.', $rules->refusal(self::OFFER, [], [['offerId' => 'kd-1', 'period' => '3']], $day));
		self::assertSame(
			'Je hebt voor periode 3 al een keuzedeel gekozen.',
			$rules->refusal(self::OFFER, [], [['offerId' => 'kd-2', 'period' => '3', 'lifecycle' => 'approved']], $day)
		);
		self::assertSame('Dit keuzedeel wordt niet meer aangeboden.', $rules->refusal(['lifecycle' => 'withdrawn'] + self::OFFER, [], [], $day));
	}//end testRefusalsInWords()

	/**
	 * An offer for a programme or groups is only for their students.
	 *
	 * @return void
	 */
	public function testIsFor(): void {
		$rules = new KeuzedeelRules();

		self::assertTrue($rules->isFor(self::OFFER, [], []));
		self::assertTrue($rules->isFor(['programmeId' => 'p-1', 'cohortIds' => ['mt3a']], ['p-1'], ['mt3a']));
		self::assertFalse($rules->isFor(['programmeId' => 'p-1'], ['p-2'], ['mt3a']));
		self::assertFalse($rules->isFor(['cohortIds' => ['mt3b']], ['p-1'], ['mt3a']));
	}//end testIsFor()
}//end class
