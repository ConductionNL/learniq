<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\CourseMetadataFilter;
use PHPUnit\Framework\TestCase;

/**
 * CourseMetadataFilter keeps only the source course metadata the Course schema accepts.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\CourseMetadataFilter
 */
class CourseMetadataFilterTest extends TestCase {

	/**
	 * The filter under test.
	 *
	 * @var CourseMetadataFilter
	 */
	private CourseMetadataFilter $filter;

	/**
	 * Build the filter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->filter = new CourseMetadataFilter();
	}//end setUp()

	/**
	 * Every valid value passes, in the order enums, language, texts, levels.
	 *
	 * @return void
	 */
	public function testValidMetadataIsKeptInOrder(): void {
		$this->assertSame(
			[
				'level' => 'hbo',
				'license' => 'CC-BY-SA-4.0',
				'language' => 'nl',
				'description' => 'Een cursus',
				'author' => 'Anna',
				'subject' => 'Wiskunde',
				'educationalLevels' => ['havo', 'vwo'],
			],
			$this->filter->filter(
				course: [
					'educationalLevels' => ['havo', 'vwo'],
					'subject' => 'Wiskunde',
					'author' => 'Anna',
					'description' => 'Een cursus',
					'language' => 'nl',
					'license' => 'CC-BY-SA-4.0',
					'level' => 'hbo',
					'name' => 'Not metadata',
					'code' => 'X-1',
				]
			)
		);
	}//end testValidMetadataIsKeptInOrder()

	/**
	 * An empty source yields no metadata at all.
	 *
	 * @return void
	 */
	public function testEmptyCourseYieldsNothing(): void {
		$this->assertSame([], $this->filter->filter(course: []));
	}//end testEmptyCourseYieldsNothing()

	/**
	 * Level and licence outside their enums, or not strings, are dropped independently.
	 *
	 * @return void
	 */
	public function testEnumValuesOutsideTheirEnumAreDropped(): void {
		$this->assertSame([], $this->filter->filter(course: ['level' => 'other', 'license' => 'MIT']));
		$this->assertSame([], $this->filter->filter(course: ['level' => 'HBO', 'license' => 'cc0-1.0']));
		$this->assertSame([], $this->filter->filter(course: ['level' => ['hbo'], 'license' => null]));
		$this->assertSame(['license' => 'all-rights-reserved'], $this->filter->filter(course: ['level' => 'x', 'license' => 'all-rights-reserved']));
		$this->assertSame(['level' => 'corporate'], $this->filter->filter(course: ['level' => 'corporate', 'license' => 'GPL']));
	}//end testEnumValuesOutsideTheirEnumAreDropped()

	/**
	 * Only exactly two lowercase letters pass as a language.
	 *
	 * @return void
	 */
	public function testLanguageMustBeTwoLowercaseLetters(): void {
		$this->assertSame(['language' => 'de'], $this->filter->filter(course: ['language' => 'de']));
		foreach (['EN', 'eng', 'e', 'nl-NL', '', 12] as $invalid) {
			$this->assertSame([], $this->filter->filter(course: ['language' => $invalid]), var_export($invalid, true));
		}
	}//end testLanguageMustBeTwoLowercaseLetters()

	/**
	 * Free text is trimmed, capped per field in characters, and dropped when blank or not a string.
	 *
	 * @return void
	 */
	public function testTextsAreTrimmedCappedAndBlankDropped(): void {
		$result = $this->filter->filter(
			course: [
				'description' => '  ' . str_repeat('é', 10005) . '  ',
				'author' => str_repeat('a', 250),
				'subject' => "\t" . str_repeat('ß', 130),
			]
		);

		$this->assertSame(str_repeat('é', 10000), $result['description']);
		$this->assertSame(str_repeat('a', 200), $result['author']);
		$this->assertSame(str_repeat('ß', 120), $result['subject']);

		$this->assertSame(
			['subject' => 'Biology'],
			$this->filter->filter(course: ['description' => "  \n ", 'author' => 42, 'subject' => ' Biology '])
		);
	}//end testTextsAreTrimmedCappedAndBlankDropped()

	/**
	 * NL-LOM levels are filtered to the enum, de-duplicated and re-indexed.
	 *
	 * @return void
	 */
	public function testEducationalLevelsAreFilteredUniqueAndReindexed(): void {
		$this->assertSame(
			['educationalLevels' => ['mbo-2', 'wo', 'adult-education']],
			$this->filter->filter(course: ['educationalLevels' => ['bogus', 'mbo-2', 'wo', 'mbo-2', 'MBO-3', 'adult-education']])
		);
	}//end testEducationalLevelsAreFilteredUniqueAndReindexed()

	/**
	 * Levels that are not an array, or that hold no valid value, yield no key.
	 *
	 * @return void
	 */
	public function testEducationalLevelsWithoutValidValueAreAbsent(): void {
		$this->assertSame([], $this->filter->filter(course: ['educationalLevels' => 'havo']));
		$this->assertSame([], $this->filter->filter(course: ['educationalLevels' => ['mbo', 'bachelor']]));
		$this->assertSame([], $this->filter->filter(course: ['educationalLevels' => []]));
	}//end testEducationalLevelsWithoutValidValueAreAbsent()
}//end class
