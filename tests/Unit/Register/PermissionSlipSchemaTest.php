<?php

/**
 * Register test for the permission-request and permission-response schemas.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/permission-slips/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Silence is a refusal by default, a closed request is a state, and an answer
 * records who, when and at which assurance.
 */
final class PermissionSlipSchemaTest extends TestCase {

	/**
	 * The register's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas'];
	}

	public function testSilenceCountsAsARefusalUnlessTheSchoolSaysOtherwise(): void {
		$request = self::schemas()['PermissionRequest'];

		$this->assertTrue($request['properties']['treatSilenceAsRefusal']['default']);
		$this->assertSame(['title', 'happensOn', 'respondBy', 'tenant_id'], $request['required']);
	}

	public function testARequestIsOpenUntilItIsClosed(): void {
		$lifecycle = self::schemas()['PermissionRequest']['x-openregister-lifecycle'];

		$this->assertSame('open', $lifecycle['initial']);
		$this->assertSame(['close' => ['from' => 'open', 'to' => 'closed']], $lifecycle['transitions']);
		$this->assertSame(['open', 'closed'], self::schemas()['PermissionRequest']['properties']['lifecycle']['enum']);
	}

	public function testAnAnswerRecordsWhoWhenAndAtWhichAssurance(): void {
		$response = self::schemas()['PermissionResponse'];

		$this->assertSame('PermissionRequest', $response['properties']['permissionRequestId']['$ref']);
		$this->assertSame(['yes', 'no'], $response['properties']['answer']['enum']);
		$this->assertSame(['basic', 'substantial', 'high'], $response['properties']['assuranceLevel']['enum']);
		foreach (['permissionRequestId', 'learnerRef', 'answer', 'answeredByRef', 'answeredAt', 'assuranceLevel'] as $field) {
			$this->assertContains($field, $response['required']);
		}
	}

	public function testOnlyStaffReachTheRecordsThroughTheObjectApi(): void {
		foreach (['PermissionRequest', 'PermissionResponse'] as $schema) {
			foreach (self::schemas()[$schema]['authorization'] as $rules) {
				$this->assertNotContains('authenticated', $rules);
				foreach ($rules as $rule) {
					$this->assertIsString($rule);
				}
			}
		}
	}
}
