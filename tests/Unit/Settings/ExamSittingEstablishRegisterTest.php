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
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/exam-board/spec.md#requirement-the-committee-establishes-the-results-of-a-sitting
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

		self::assertSame('planned', $transition['from']);
		self::assertSame('established', $transition['to']);
		self::assertSame('OCA\Learniq\Lifecycle\ExamSittingEstablishGuard', $transition['requires']);
		self::assertSame(
			['actorField' => 'establishedBy', 'timeField' => 'establishedAt'],
			$transition['actions'][0]['actionParameters']
		);
		self::assertContains('established', $sitting['properties']['lifecycle']['enum']);
	}//end testEstablishIsGuardedAndStampsWhoAndWhen()

	/**
	 * A result knows its sitting and when and by whom it was established.
	 *
	 * @return void
	 */
	public function testAResultCarriesItsSittingAndEstablishment(): void {
		$properties = self::register()['components']['schemas']['GradeEntry']['properties'];

		self::assertSame('ExamSitting', $properties['examSittingId']['$ref']);
		self::assertSame('date-time', $properties['establishedAt']['format']);
		self::assertTrue($properties['establishedAt']['nullable']);
		self::assertArrayHasKey('establishedBy', $properties);
	}//end testAResultCarriesItsSittingAndEstablishment()
}//end class
