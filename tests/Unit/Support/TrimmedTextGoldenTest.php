<?php

/**
 * Learniq text value golden tests.
 *
 * Pins how each of these classes reads a payload value as text: the string trimmed,
 * and the empty string for anything that is not a string.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Support;

use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use OCA\Learniq\Service\CourseSharePackageBuilder;
use OCA\Learniq\Service\CourseSharingGate;
use OCA\Learniq\Service\CourseStore\CourseStoreRegistryObject;
use OCA\Learniq\Service\Portal\PortalEmployerBookings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Golden tests for the text reading of each class.
 */
class TrimmedTextGoldenTest extends TestCase {

	/**
	 * The classes and the method that reads text.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function readers(): array {
		return [
			'CourseSharePackageBuilder' => [CourseSharePackageBuilder::class, 'text'],
			'CourseSharingGate' => [CourseSharingGate::class, 'text'],
			'CourseStoreRegistryObject' => [CourseStoreRegistryObject::class, 'text'],
			'PortalEmployerBookings' => [PortalEmployerBookings::class, 'text'],
			'AssessmentAccessPolicy' => [AssessmentAccessPolicy::class, 'normaliseCode'],
			'CompetencyAlignmentNormaliser' => [CompetencyAlignmentNormaliser::class, 'stringOrEmpty'],
		];
	}//end readers()

	/**
	 * A string is read as text, anything else as ''.
	 *
	 * @param class-string $class The class.
	 * @param string $method The reading method.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testAValueIsReadTheSameWay(string $class, string $method): void {
		$reader = new ReflectionMethod($class, $method);
		$instance = null;
		if ($reader->isStatic() === false) {
			$instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		}

		$read = static fn (mixed $value): mixed => $reader->invoke($instance, $value);

		self::assertSame('abc', $read('abc'));
		self::assertSame('abc', $read('  abc  '));
		self::assertSame('', $read(''));
		foreach ([null, 42, 4.2, true, ['abc'], new stdClass()] as $value) {
			self::assertSame('', $read($value));
		}
	}//end testAValueIsReadTheSameWay()
}//end class
