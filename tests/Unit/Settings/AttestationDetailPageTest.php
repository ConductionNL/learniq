<?php

/**
 * Learniq attestation detail page test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-a-signed-attestation-cannot-be-edited
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The attestation detail page follows the freeze the server enforces.
 *
 * @coversNothing
 */
class AttestationDetailPageTest extends TestCase {

	/**
	 * The AttestationDetail page of the manifest fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function page(): array {
		$fragment = json_decode(json: (string)file_get_contents(filename: __DIR__ . '/../../../src/manifest.d/dashboard.json'), associative: true);
		$pages    = array_column(array: $fragment['pages'], column_key: null, index_key: 'id');

		return $pages['AttestationDetail'];
	}//end page()

	/**
	 * The Attestation schema of the register.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		$register = json_decode(json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/learniq_register.json'), associative: true);

		return $register['components']['schemas']['Attestation'];
	}//end schema()

	/**
	 * A drafted attestation is editable; any other state is read only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#scenario-a-drafted-attestation-is-corrected
	 */
	public function testTheFieldsAreReadOnlyOnceTheAttestationLeavesDrafted(): void {
		$config = self::page()['config'];

		self::assertArrayNotHasKey(key: 'readOnly', array: $config, message: 'a drafted attestation must stay editable');
		self::assertSame(
			expected: ['field' => 'lifecycle', 'op' => 'neq', 'value' => 'drafted'],
			actual: $config['readOnlyWhen']
		);
		self::assertSame(expected: 'drafted', actual: self::schema()['x-openregister-lifecycle']['initial']);
	}//end testTheFieldsAreReadOnlyOnceTheAttestationLeavesDrafted()

	/**
	 * Revoke is offered on the page and asks for the reason the server requires.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#scenario-revoke-without-a-reason
	 */
	public function testRevokeAsksForTheReason(): void {
		self::assertSame(expected: ['field' => 'lifecycle'], actual: self::page()['config']['lifecycleActions']);

		$revoke = self::schema()['x-openregister-lifecycle']['transitions']['revoke'];
		self::assertSame(expected: [['field' => 'revocationReason', 'required' => true]], actual: $revoke['inputs']);
	}//end testRevokeAsksForTheReason()
}//end class
