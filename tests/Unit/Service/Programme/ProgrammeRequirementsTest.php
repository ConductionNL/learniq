<?php

/**
 * Which parts of a programme are mandatory by default.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Programme
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
 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#requirement-mandatory-and-optional-parts-of-a-programme
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Programme;

use OCA\Learniq\Service\Programme\ProgrammeRequirements;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProgrammeRequirements.
 */
class ProgrammeRequirementsTest extends TestCase {

	/**
	 * A course the author marked mandatory is mandatory; the others are optional.
	 *
	 * @return void
	 */
	public function testMarkedCoursesAreMandatoryAndTheRestOptional(): void {
		$programme = ['courseIds' => ['c-intro', 'c-rules', 'c-tour'], 'mandatoryCourseIds' => ['c-intro', 'c-rules']];
		$requirements = new ProgrammeRequirements();

		self::assertTrue($requirements->mandatoryFor(programme: $programme, courseId: 'c-intro'));
		self::assertTrue($requirements->mandatoryFor(programme: $programme, courseId: 'c-rules'));
		self::assertFalse($requirements->mandatoryFor(programme: $programme, courseId: 'c-tour'));
	}//end testMarkedCoursesAreMandatoryAndTheRestOptional()

	/**
	 * A programme without the list, or with an empty one, marks nothing
	 * mandatory: today's behaviour (design D2).
	 *
	 * @return void
	 */
	public function testAProgrammeWithoutTheListMarksNothingMandatory(): void {
		$requirements = new ProgrammeRequirements();

		self::assertFalse($requirements->mandatoryFor(programme: ['courseIds' => ['c-intro']], courseId: 'c-intro'));
		self::assertFalse($requirements->mandatoryFor(programme: ['courseIds' => ['c-intro'], 'mandatoryCourseIds' => []], courseId: 'c-intro'));
		self::assertFalse($requirements->mandatoryFor(programme: ['courseIds' => ['c-intro'], 'mandatoryCourseIds' => null], courseId: 'c-intro'));
	}//end testAProgrammeWithoutTheListMarksNothingMandatory()

	/**
	 * A course on the list that is not part of the programme is not a part of
	 * it, so it is never reported mandatory for it.
	 *
	 * @return void
	 */
	public function testACourseOutsideTheProgrammeIsNeverMandatoryForIt(): void {
		$programme = ['courseIds' => ['c-intro'], 'mandatoryCourseIds' => ['c-intro', 'c-elsewhere']];

		self::assertFalse((new ProgrammeRequirements())->mandatoryFor(programme: $programme, courseId: 'c-elsewhere'));
	}//end testACourseOutsideTheProgrammeIsNeverMandatoryForIt()
}//end class
