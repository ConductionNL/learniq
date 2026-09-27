<?php

/**
 * Unit tests for CourseSharingGate: the "may this leave the school" rules.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lesson-sharing-consent-gate/tasks.md#task-1-gate-and-package-builder
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CourseSharingGate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\CourseSharingGate
 */
class CourseSharingGateTest extends TestCase {

	/**
	 * An openly licensed course with an author.
	 *
	 * @return array<string, mixed>
	 */
	private function openCourse(): array {
		return ['id' => 'course-1', 'name' => 'Nederlands havo 4', 'license' => 'CC-BY-SA-4.0', 'author' => 'Sectie Nederlands'];
	}//end openCourse()

	/**
	 * The codes of the blockers.
	 *
	 * @param array<int, array{code: string, id: string, name: string}> $blockers The gate's answer.
	 *
	 * @return array<int, string>
	 */
	private function codes(array $blockers): array {
		return array_column($blockers, 'code');
	}//end codes()

	/**
	 * An open course with both confirmations may leave.
	 *
	 * @return void
	 */
	public function testAnOpenCourseWithBothConfirmationsPasses(): void {
		$lessons   = [['id' => 'lesson-1', 'name' => 'Betoog', 'license' => 'CC-BY-4.0'], ['id' => 'lesson-2', 'name' => 'Beschouwing']];
		$materials = [['id' => 'm-1', 'title' => 'Uitleg', 'license' => 'cc-by-4.0'], ['id' => 'm-2', 'title' => 'Opdracht']];

		self::assertSame([], (new CourseSharingGate())->check($this->openCourse(), $lessons, $materials, true, true));
	}//end testAnOpenCourseWithBothConfirmationsPasses()

	/**
	 * No licence and no author are both named, with the course's id and name.
	 *
	 * @return void
	 */
	public function testMissingLicenceAndAuthorAreNamed(): void {
		$blockers = (new CourseSharingGate())->check(['id' => 'course-1', 'name' => 'Wiskunde'], [], [], true, true);

		self::assertSame([CourseSharingGate::LICENCE_MISSING, CourseSharingGate::AUTHOR_MISSING], $this->codes($blockers));
		self::assertSame(['code' => 'licence-missing', 'id' => 'course-1', 'name' => 'Wiskunde'], $blockers[0]);
	}//end testMissingLicenceAndAuthorAreNamed()

	/**
	 * All rights reserved is not an open licence.
	 *
	 * @return void
	 */
	public function testAllRightsReservedIsRefused(): void {
		$course = [...$this->openCourse(), 'license' => 'all-rights-reserved'];

		self::assertSame(
			[CourseSharingGate::LICENCE_NOT_OPEN],
			$this->codes((new CourseSharingGate())->check($course, [], [], true, true))
		);
	}//end testAllRightsReservedIsRefused()

	/**
	 * A lesson's own closed licence and a publisher-licensed material block,
	 * naming each object.
	 *
	 * @return void
	 */
	public function testClosedLessonAndMaterialLicencesAreNamed(): void {
		$lessons   = [['id' => 'lesson-9', 'name' => 'Methodeles', 'license' => 'all-rights-reserved']];
		$materials = [['id' => 'm-9', 'title' => 'Werkblad uit de methode', 'license' => '© Uitgeverij Voorbeeld']];

		$blockers = (new CourseSharingGate())->check($this->openCourse(), $lessons, $materials, true, true);

		self::assertSame(
			[CourseSharingGate::LESSON_LICENCE_NOT_OPEN, CourseSharingGate::MATERIAL_LICENCE_NOT_OPEN],
			$this->codes($blockers)
		);
		self::assertSame('Methodeles', $blockers[0]['name']);
		self::assertSame('m-9', $blockers[1]['id']);
	}//end testClosedLessonAndMaterialLicencesAreNamed()

	/**
	 * Both confirmations are required and named separately.
	 *
	 * @return void
	 */
	public function testMissingConfirmationsAreNamed(): void {
		self::assertSame(
			[CourseSharingGate::PUPIL_DATA_NOT_CONFIRMED, CourseSharingGate::RIGHTS_NOT_CONFIRMED],
			$this->codes((new CourseSharingGate())->check($this->openCourse(), [], [], false, false))
		);
	}//end testMissingConfirmationsAreNamed()

	/**
	 * A lesson without its own licence takes the course licence.
	 *
	 * @return void
	 */
	public function testLessonLicenceFallsBackToTheCourse(): void {
		$gate = new CourseSharingGate();

		self::assertSame('CC-BY-SA-4.0', $gate->lessonLicense(['name' => 'Betoog'], $this->openCourse()));
		self::assertSame('CC0-1.0', $gate->lessonLicense(['license' => 'CC0-1.0'], $this->openCourse()));
		self::assertSame('', $gate->lessonLicense([], []));
	}//end testLessonLicenceFallsBackToTheCourse()

	/**
	 * The open set is exactly CC0 and the CC 4.0 family.
	 *
	 * @return void
	 */
	public function testTheOpenSetIsCc0AndCc4(): void {
		self::assertTrue(CourseSharingGate::isOpen('CC-BY-NC-ND-4.0'));
		self::assertTrue(CourseSharingGate::isOpen(' cc0-1.0 '));
		self::assertFalse(CourseSharingGate::isOpen('CC-BY-3.0'));
		self::assertFalse(CourseSharingGate::isOpen('all-rights-reserved'));
		self::assertFalse(CourseSharingGate::isOpen(''));
	}//end testTheOpenSetIsCc0AndCc4()
}//end class
