<?php

/**
 * Learniq ExcuseRequest register test.
 *
 * OpenRegister validates a schema's `required` list before any listener runs,
 * so a field the portal cannot send must not be in it, or every portal absence
 * report is refused before ExcuseRequestOwnerStamp can fill it.
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
 * @spec openspec/changes/settings-and-excuse-authorization/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the ExcuseRequest required list fits what the portal sends.
 */
class ExcuseRequestRegisterTest extends TestCase {

	/**
	 * The ExcuseRequest schema as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return $register['components']['schemas']['ExcuseRequest'];
	}//end schema()

	/**
	 * The fields a portal create action lets the caller send, plus the field
	 * portaliq stamps from the subject.
	 *
	 * @param string $audience The portal audience.
	 *
	 * @return array<int, string>
	 */
	private function portalFields(string $audience): array {
		$manifest = (new PortalContributionProvider())->getContribution(
			subject: ['audience' => $audience, 'subjectRef' => '00000000-0000-4000-8000-000000000000', 'trustLevel' => 'high']
		);

		foreach ($manifest['actions'] as $action) {
			if ($action['id'] === 'createExcuseRequest') {
				return [...$action['fields'], $action['scopeField']];
			}
		}

		self::fail('No createExcuseRequest action for ' . $audience);
	}//end portalFields()

	/**
	 * Required holds only what every caller sends.
	 *
	 * @return void
	 */
	public function testRequiredHoldsOnlyWhatEveryCallerSends(): void {
		self::assertSame(['dateFrom', 'dateTo', 'reason', 'reasonKind'], $this->schema()['required']);
	}//end testRequiredHoldsOnlyWhatEveryCallerSends()

	/**
	 * A pupil's and a guardian's portal report both carry every required field,
	 * so OpenRegister's validator lets them reach the owner stamp.
	 *
	 * @return void
	 */
	public function testEveryPortalReportCarriesTheRequiredFields(): void {
		foreach (['student', 'parent'] as $audience) {
			$missing = array_values(array_diff($this->schema()['required'], $this->portalFields(audience: $audience)));
			self::assertSame([], $missing, $audience);
		}
	}//end testEveryPortalReportCarriesTheRequiredFields()

	/**
	 * The owner fields the stamp fills are still declared, so the stamp writes
	 * properties OpenRegister stores.
	 *
	 * @return void
	 */
	public function testTheStampedFieldsAreDeclared(): void {
		foreach (['learnerId', 'learnerRef', 'submittedBy', 'submittedByRef', 'submittedAuthLevel', 'tenant_id'] as $field) {
			self::assertArrayHasKey($field, $this->schema()['properties'], $field);
		}

		self::assertSame(
			['none', 'basic', 'substantial', 'high'],
			$this->schema()['properties']['submittedAuthLevel']['enum']
		);
	}//end testTheStampedFieldsAreDeclared()
}//end class
