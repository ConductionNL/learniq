<?php

/**
 * Every schema in learniq's register that holds personal data declares its
 * processing (governance-processing-record-every-schema).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://learniq.app
 *
 * @spec openspec/changes/governance-processing-record-every-schema/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The register's personal-data schemas, their activities and the read-log opt-in.
 *
 * @coversNothing
 */
class ProcessingCoverageTest extends TestCase {

	/**
	 * Property names that name or point at a person; the list of tests/validate-processing-coverage.js.
	 *
	 * @var array<int, string>
	 */
	private const PERSONAL_DATA_FIELDS = [
		'learnerId', 'learnerRef', 'learnerUserId', 'learnerIds', 'learnerRefs', 'ncUserId', 'teacherIds', 'managerId', 'employeeId',
		'parentIds', 'guardianRefs', 'givenName', 'familyName', 'birthDate', 'markedBy', 'submittedBy', 'email', 'phone',
	];

	/**
	 * The activities whose reads are logged (design D2): care and support, and results.
	 *
	 * @var array<int, string>
	 */
	private const LOGGED_ACTIVITIES = ['learniq-care-and-support', 'learniq-assessment-and-results'];

	/**
	 * The activities that do not log reads (design D2).
	 *
	 * @var array<int, string>
	 */
	private const UNLOGGED_ACTIVITIES = ['learniq-teaching-and-enrolment', 'learniq-parent-contact'];

	/**
	 * The register's schemas.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas;

	/**
	 * Load the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$decoded = json_decode((string) file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true);
		$this->assertIsArray($decoded);
		$this->schemas = $decoded['components']['schemas'];
	}//end setUp()

	/**
	 * Every schema with a personal-data field carries a complete processing block, drafted for review.
	 *
	 * @return void
	 */
	public function testEveryPersonalDataSchemaDeclaresItsProcessing(): void {
		$count = 0;
		foreach ($this->schemas as $name => $schema) {
			$personal = array_intersect(array_keys($schema['properties'] ?? []), self::PERSONAL_DATA_FIELDS);
			if ($personal === []) {
				continue;
			}

			$count++;
			$block = ($schema['x-openregister-processing'] ?? null);
			$this->assertIsArray($block, $name.' holds personal data and declares no x-openregister-processing');
			foreach (['code', 'naam', 'doelbinding', 'rechtsgrond', 'dataCategories', 'retentionReference'] as $field) {
				$this->assertNotEmpty($block[$field] ?? null, $name.' processing block lacks '.$field);
			}

			$this->assertSame($block['code'], ($block['attribution']['default'] ?? null), $name.' attributes to its own activity');
		}

		$this->assertGreaterThanOrEqual(68, $count, 'the 11 earlier personal-data declarations and the 57 of this change');
	}//end testEveryPersonalDataSchemaDeclaresItsProcessing()

	/**
	 * Care and support, and results, log reads; enrolment and parent contact do not.
	 *
	 * @return void
	 */
	public function testReadsAreLoggedWhereTheDataIsSensitive(): void {
		$seen = [];
		foreach ($this->schemas as $name => $schema) {
			$code = ($schema['x-openregister-processing']['code'] ?? null);
			if (in_array($code, self::LOGGED_ACTIVITIES, true) === true) {
				$this->assertTrue($schema['x-openregister-processing']['logReads'], $name.' ('.$code.') logs reads');
				$seen[$code] = true;
			}

			if (in_array($code, self::UNLOGGED_ACTIVITIES, true) === true) {
				$this->assertFalse($schema['x-openregister-processing']['logReads'], $name.' ('.$code.') does not log reads');
				$seen[$code] = true;
			}
		}

		$this->assertCount(4, $seen, 'every activity of design D2 has schemas');
	}//end testReadsAreLoggedWhereTheDataIsSensitive()

	/**
	 * Grades appear in the record under assessment and results, as public task.
	 *
	 * @return void
	 */
	public function testGradesAreAssessmentAndResults(): void {
		foreach (['GradeEntry', 'FinalGrade', 'ReportCard'] as $name) {
			$block = $this->schemas[$name]['x-openregister-processing'];
			$this->assertSame('learniq-assessment-and-results', $block['code'], $name);
			$this->assertSame('public-task', $block['rechtsgrond'], $name);
			$this->assertSame('draft', $block['lifecycle'], $name);
		}
	}//end testGradesAreAssessmentAndResults()
}//end class
