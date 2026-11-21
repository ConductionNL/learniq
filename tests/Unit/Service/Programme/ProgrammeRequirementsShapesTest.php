<?php

/**
 * ProgrammeRequirements: ids of other types and lists of other shapes.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Programme
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Programme;

use OCA\Learniq\Service\Programme\ProgrammeRequirements;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * How mandatoryFor() reads ids stored as numbers and lists stored as one value.
 */
#[CoversClass(ProgrammeRequirements::class)]
class ProgrammeRequirementsShapesTest extends TestCase {

	/**
	 * Numeric ids in either list match the same id as a string.
	 *
	 * @return void
	 */
	public function testNumericIdsMatchTheirStringForm(): void {
		$programme = ['courseIds' => [12, 13], 'mandatoryCourseIds' => [12]];
		$requirements = new ProgrammeRequirements();

		self::assertTrue($requirements->mandatoryFor(programme: $programme, courseId: '12'));
		self::assertFalse($requirements->mandatoryFor(programme: $programme, courseId: '13'));
	}//end testNumericIdsMatchTheirStringForm()

	/**
	 * A single id instead of a list counts as a list of one.
	 *
	 * @return void
	 */
	public function testASingleIdIsAListOfOne(): void {
		$programme = ['courseIds' => 'c-intro', 'mandatoryCourseIds' => 'c-intro'];

		self::assertTrue((new ProgrammeRequirements())->mandatoryFor(programme: $programme, courseId: 'c-intro'));
	}//end testASingleIdIsAListOfOne()

	/**
	 * A programme without parts makes nothing mandatory, and ids are compared exactly.
	 *
	 * @return void
	 */
	public function testWithoutPartsNothingIsMandatoryAndIdsMatchExactly(): void {
		$requirements = new ProgrammeRequirements();

		self::assertFalse($requirements->mandatoryFor(programme: ['mandatoryCourseIds' => ['c-intro']], courseId: 'c-intro'));
		self::assertFalse($requirements->mandatoryFor(programme: ['courseIds' => ['C-Intro'], 'mandatoryCourseIds' => ['C-Intro']], courseId: 'c-intro'));
		self::assertFalse($requirements->mandatoryFor(programme: ['courseIds' => ['c-intro'], 'mandatoryCourseIds' => ['c-intro']], courseId: ''));
	}//end testWithoutPartsNothingIsMandatoryAndIdsMatchExactly()
}//end class
