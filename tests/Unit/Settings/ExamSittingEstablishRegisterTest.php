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
 * @spec openspec/specs/exam-board/spec.md#requirement-the-committee-establishes-the-results-of-a-sitting
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The register declares the sitting's establish and the result's establishment.
 *
 * @coversNothing
 */
class ExamSittingEstablishRegisterTest extends TestCase {

	/**
	 * The shipped register.
	 *
	 * @return array<string, mixed>
	 */
	private static function register(): array {
		return json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/learniq_register.json'), true);
	}//end register()

	/**
	 * The committee's establish is guarded and stamps who and when.
	 *
	 * @return void
	 */
	public function testEstablishIsGuardedAndStampsWhoAndWhen(): void {
		$sitting    = self::register()['components']['schemas']['ExamSitting'];
		$transition = $sitting['x-openregister-lifecycle']['transitions']['establish'];

		self::assertSame(expected: 'planned', actual: $transition['from']);
		self::assertSame(expected: 'established', actual: $transition['to']);
		self::assertSame(expected: 'OCA\Learniq\Lifecycle\ExamSittingEstablishGuard', actual: $transition['requires']);
		self::assertSame(
			expected: ['actorField' => 'establishedBy', 'timeField' => 'establishedAt'],
			actual: $transition['actions'][0]['actionParameters']
		);
		self::assertContains(needle: 'established', haystack: $sitting['properties']['lifecycle']['enum']);
	}//end testEstablishIsGuardedAndStampsWhoAndWhen()

	/**
	 * A result knows its sitting and when and by whom it was established.
	 *
	 * @return void
	 */
	public function testAResultCarriesItsSittingAndEstablishment(): void {
		$properties = self::register()['components']['schemas']['GradeEntry']['properties'];

		self::assertSame(expected: 'ExamSitting', actual: $properties['examSittingId']['$ref']);
		self::assertSame(expected: 'date-time', actual: $properties['establishedAt']['format']);
		self::assertTrue(condition: $properties['establishedAt']['nullable']);
		self::assertArrayHasKey(key: 'establishedBy', array: $properties);
	}//end testAResultCarriesItsSittingAndEstablishment()
}//end class
