<?php

/**
 * Unit tests for the CourseShareConsent schema.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lesson-sharing-consent-gate/tasks.md#task-2-share-export-service-consent-schema-and-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Service\CourseShareExportService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the CourseShareConsent schema, its access and its seed row.
 */
class CourseShareConsentRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path         = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * The schema's slug is the one the service writes to, and it carries
	 * every field the service writes.
	 *
	 * @return void
	 */
	public function testTheSchemaMatchesWhatTheServiceWrites(): void {
		$schema = $this->config['components']['schemas']['CourseShareConsent'];

		self::assertSame(CourseShareExportService::CONSENT_SCHEMA, $schema['slug']);
		foreach (['courseId', 'courseName', 'purpose', 'confirmedBy', 'confirmedAt', 'noPupilData', 'rightsCleared', 'license', 'tenant_id'] as $field) {
			self::assertArrayHasKey($field, $schema['properties']);
		}

		self::assertSame(
			[CourseShareExportService::PURPOSE_DOWNLOAD, CourseShareExportService::PURPOSE_STORE],
			$schema['properties']['purpose']['enum']
		);

	}//end testTheSchemaMatchesWhatTheServiceWrites()

	/**
	 * Staff and the confirming user read; the authoring groups create;
	 * administration managers alone correct; nobody deletes.
	 *
	 * @return void
	 */
	public function testAccessIsStaffAndTheConfirmingUser(): void {
		$authorization = $this->config['components']['schemas']['CourseShareConsent']['authorization'];

		self::assertSame(
			[
				'instructors',
				'team-leads',
				'coordinators',
				'administration-managers',
				'compliance-officers',
				['group' => 'authenticated', 'match' => ['confirmedBy' => '$userId']],
			],
			$authorization['read']
		);
		self::assertSame(['instructors', 'team-leads', 'coordinators', 'administration-managers'], $authorization['create']);
		self::assertSame(['administration-managers'], $authorization['update']);
		self::assertArrayNotHasKey('delete', $authorization);

	}//end testAccessIsStaffAndTheConfirmingUser()

	/**
	 * The seed row satisfies the required fields and both confirmations.
	 *
	 * @return void
	 */
	public function testTheSeedRowIsComplete(): void {
		$schema = $this->config['components']['schemas']['CourseShareConsent'];
		// Found by id, not by position: another change may seed this schema too.
		$seed = $this->seedById(schema: $schema, id: '00000000-0000-0000-0000-0000000f0101');

		foreach ($schema['required'] as $field) {
			self::assertArrayHasKey($field, $seed);
		}

		self::assertTrue($seed['noPupilData']);
		self::assertTrue($seed['rightsCleared']);

	}//end testTheSeedRowIsComplete()

	/**
	 * The seed row with the given id, failing the test when there is none.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @param string $id The seed row's id.
	 *
	 * @return array<string, mixed>
	 */
	private function seedById(array $schema, string $id): array {
		foreach (($schema['x-openregister-seed'] ?? []) as $seed) {
			if (($seed['id'] ?? null) === $id) {
				return $seed;
			}
		}

		self::fail('No seed row with id ' . $id);
	}//end seedById()
}//end class
