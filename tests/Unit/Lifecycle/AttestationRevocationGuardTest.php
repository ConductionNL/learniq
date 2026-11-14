<?php

/**
 * Unit tests for AttestationRevocationGuard (compliance-attestation-locked-after-signing).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-revoking-a-signed-attestation-records-a-reason-and-changes-nothing-else
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AttestationRevocationGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Revoking a signed attestation needs a reason; the register wires the guard and accepts the reason.
 */
class AttestationRevocationGuardTest extends TestCase {

	/**
	 * Revoke with a reason is allowed.
	 *
	 * @return void
	 */
	public function testRevokeWithAReasonIsAllowed(): void {
		$result = (new AttestationRevocationGuard())->check(
			['lifecycle' => 'revoked', 'revocationReason' => 'Signed for the wrong course'],
			'revoke',
			'officer1'
		);

		self::assertTrue($result->isAllowed());
	}//end testRevokeWithAReasonIsAllowed()

	/**
	 * A missing, empty, blank or non-string reason is refused with the ask for one.
	 *
	 * @return array<string, array{0: array<string,mixed>}>
	 */
	public static function noReason(): array {
		return [
			'absent' => [['lifecycle' => 'revoked']],
			'null' => [['lifecycle' => 'revoked', 'revocationReason' => null]],
			'empty' => [['lifecycle' => 'revoked', 'revocationReason' => '']],
			'blank' => [['lifecycle' => 'revoked', 'revocationReason' => "  \n"]],
			'not a string' => [['lifecycle' => 'revoked', 'revocationReason' => ['wrong']]],
		];
	}//end noReason()

	/**
	 * @param array<string,mixed> $object The attestation at its target state.
	 *
	 * @return void
	 */
	#[DataProvider('noReason')]
	public function testRevokeWithoutAReasonIsRefused(array $object): void {
		$result = (new AttestationRevocationGuard())->check($object, 'revoke', 'officer1');

		self::assertFalse($result->isAllowed());
		self::assertSame(AttestationRevocationGuard::DENIAL, $result->getMessage());
	}//end testRevokeWithoutAReasonIsRefused()

	/**
	 * The register names this guard on `revoke` and accepts the reason as a required input.
	 *
	 * @return void
	 */
	public function testTheRegisterWiresTheGuardAndTheInput(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$revoke = $register['components']['schemas']['Attestation']['x-openregister-lifecycle']['transitions']['revoke'];

		self::assertSame(AttestationRevocationGuard::class, $revoke['requires']);
		self::assertSame([['field' => 'revocationReason', 'required' => true]], $revoke['inputs']);
		self::assertSame('signed', $revoke['from']);
	}//end testTheRegisterWiresTheGuardAndTheInput()
}//end class
