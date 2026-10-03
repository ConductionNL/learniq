<?php

/**
 * The register carries a programme's mandatory parts, and the enrolments the
 * two programme paths write fit the Enrolment schema.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Programme.mandatoryCourseIds in the shipped register.
 */
class ProgrammeMandatoryPartsRegisterTest extends TestCase {
	use RegisterSchemaPayloads;

	/**
	 * The list is an array of Course uuids the generic form edits like
	 * courseIds, empty by default so existing programmes keep their meaning.
	 *
	 * @return void
	 */
	public function testAProgrammeListsItsMandatoryCourses(): void {
		$programme = self::shippedSchema(slug: 'programme');
		$property = ($programme['properties']['mandatoryCourseIds'] ?? null);

		self::assertIsArray($property, 'Programme.mandatoryCourseIds is declared');
		self::assertSame('array', $property['type']);
		self::assertSame([], $property['default']);
		self::assertSame(['type' => 'string', 'format' => 'uuid', '$ref' => 'Course'], $property['items']);
		self::assertNotContains('mandatoryCourseIds', $programme['required']);
	}//end testAProgrammeListsItsMandatoryCourses()

	/**
	 * A programme with and without the list fits the schema; a list of
	 * course names instead of uuids does not.
	 *
	 * @return void
	 */
	public function testProgrammePayloadsFitTheSchema(): void {
		$base = ['name' => 'Safety basics', 'level' => 'corporate', 'tenant_id' => '00000000-0000-4000-8000-000000000000', 'courseIds' => ['ee040008-0000-4000-8000-000000000001', 'ee040008-0000-4000-8000-000000000002']];

		self::assertNull(self::schemaError(slug: 'programme', payload: $base));
		self::assertNull(self::schemaError(slug: 'programme', payload: $base + ['mandatoryCourseIds' => ['ee040008-0000-4000-8000-000000000001']]));
		self::assertNotNull(self::schemaError(slug: 'programme', payload: $base + ['mandatoryCourseIds' => ['Site tour']]), 'control: a name is not a course uuid');
	}//end testProgrammePayloadsFitTheSchema()
}//end class
