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
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The ExamObjection schema as the register ships it.
 *
 * @coversNothing
 */
class ExamObjectionRegisterTest extends TestCase {

	/**
	 * The register document.
	 *
	 * @return array<string, mixed>
	 */
	private static function register(): array {
		return json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/learniq_register.json'), true);
	}//end register()

	/**
	 * The schema is a member of the learniq register and its decide transition is guarded.
	 *
	 * @return void
	 */
	public function testTheObjectionIsAMemberAndItsDecisionIsGuarded(): void {
		$register = self::register();
		$schema   = $register['components']['schemas']['ExamObjection'];

		self::assertContains(needle: 'exam-objection', haystack: $register['components']['registers']['learniq']['schemas']);
		self::assertSame(expected: 'exam-objection', actual: $schema['slug']);
		self::assertSame(expected: ['submitted', 'examiner-responded', 'decided', 'withdrawn'], actual: $schema['properties']['lifecycle']['enum']);
		self::assertSame(
			expected: 'OCA\Learniq\Lifecycle\ExamObjectionDecisionGuard',
			actual: $schema['x-openregister-lifecycle']['transitions']['decide']['requires']
		);
		self::assertSame(expected: 'decided', actual: $schema['x-openregister-lifecycle']['transitions']['decide']['to']);
	}//end testTheObjectionIsAMemberAndItsDecisionIsGuarded()

	/**
	 * A grade entry names the objection that changed it.
	 *
	 * @return void
	 */
	public function testAGradeEntryNamesTheObjectionThatChangedIt(): void {
		$entry = self::register()['components']['schemas']['GradeEntry']['properties'];

		self::assertSame(expected: 'ExamObjection', actual: $entry['examObjectionId']['$ref']);
		self::assertTrue(condition: $entry['examObjectionId']['nullable']);
	}//end testAGradeEntryNamesTheObjectionThatChangedIt()
}//end class
