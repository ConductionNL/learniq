<?php

/**
 * Unit tests for QualificationLabel.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use OCA\Learniq\Service\Pupil\QualificationLabel;
use PHPUnit\Framework\TestCase;

/**
 * The header line names the running placement's qualification and crebo.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
 */
class QualificationLabelTest extends TestCase {

	/**
	 * Milan's running placement gives the line; a finished one does not count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function testTheRunningPlacementsQualificationWithItsCrebo(): void {
		$placements = [
			['lifecycle' => 'completed', 'qualificationName' => 'Oud', 'crebo' => '11111'],
			['lifecycle' => 'active', 'qualificationName' => 'Monteur mechatronica', 'crebo' => '25290'],
		];

		self::assertSame(expected: 'Monteur mechatronica (crebo 25290)', actual: (new QualificationLabel())->forPlacements(placements: $placements));
	}//end testTheRunningPlacementsQualificationWithItsCrebo()

	/**
	 * Only a name, only a crebo, neither, or no running placement.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function testAPartLineOrNone(): void {
		$label = new QualificationLabel();
		$named = [['lifecycle' => 'active', 'qualificationName' => 'Monteur mechatronica']];
		self::assertSame(expected: 'Monteur mechatronica', actual: $label->forPlacements(placements: $named));
		self::assertSame(expected: 'crebo 25290', actual: $label->forPlacements(placements: [['lifecycle' => 'confirmed', 'crebo' => ' 25290 ']]));
		self::assertNull(actual: $label->forPlacements(placements: [['lifecycle' => 'active']]));
		self::assertNull(actual: $label->forPlacements(placements: [['lifecycle' => 'completed', 'qualificationName' => 'Oud']]));
		self::assertNull(actual: $label->forPlacements(placements: []));
	}//end testAPartLineOrNone()
}//end class
