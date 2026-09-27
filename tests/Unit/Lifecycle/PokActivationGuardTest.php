<?php

/**
 * Learniq PokActivationGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\PokActivationGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the PokActivationGuard lifecycle guard (pending-signatures → active).
 */
class PokActivationGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard whose ObjectService::findAll() returns the given PokSignature rows.
	 *
	 * @param array<int, array<string, mixed>> $signatures Rows to return for any pok-signature query.
	 *
	 * @return PokActivationGuard
	 */
	private function makeGuard(array $signatures): PokActivationGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($signatures) {
				if ($config['schema'] === 'pok-signature') {
					return $signatures;
				}

				return [];
			}
		);

		return new PokActivationGuard($objectService, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * Build a Praktijkovereenkomst as OpenRegister hands it to the guard: at its target state.
	 *
	 * @param int $version POK version.
	 *
	 * @return array<string, mixed>
	 */
	private function pokObject(int $version = 1): array {
		return ['id' => 'pok-1', 'version' => $version, 'tenant_id' => 'tenant-a', 'lifecycle' => 'active'];
	}//end pokObject()

	/**
	 * All three roles signed → activation allowed.
	 *
	 * @return void
	 */
	public function testAllThreeRolesSignedAllowsActivation(): void {
		$signatures = [
			['signerRole' => 'student'],
			['signerRole' => 'school'],
			['signerRole' => 'praktijkopleider'],
		];

		$object = $this->pokObject();
		self::assertAllowed($this->makeGuard($signatures)->check($object, 'activate', ''));

	}//end testAllThreeRolesSignedAllowsActivation()

	/**
	 * Zero, one, or two of three roles signed → activation blocked.
	 *
	 * @return void
	 */
	public function testIncompleteSignaturesBlockActivation(): void {
		$cases = [
			[],
			[['signerRole' => 'student']],
			[['signerRole' => 'student'], ['signerRole' => 'school']],
		];

		foreach ($cases as $signatures) {
			$object = $this->pokObject();
			self::assertDenied($this->makeGuard($signatures)->check($object, 'activate', ''));
		}

	}//end testIncompleteSignaturesBlockActivation()

	/**
	 * A duplicate signerRole (e.g. two student signatures) still counts as one distinct role —
	 * two duplicated roles is NOT the same as three distinct roles, so activation stays blocked.
	 *
	 * @return void
	 */
	public function testDuplicateRoleStillCountsAsOneDistinctRole(): void {
		$signatures = [
			['signerRole' => 'student'],
			['signerRole' => 'student'],
			['signerRole' => 'school'],
		];

		$object = $this->pokObject();
		self::assertDenied($this->makeGuard($signatures)->check($object, 'activate', ''));

	}//end testDuplicateRoleStillCountsAsOneDistinctRole()

	/**
	 * A missing object id fails closed without querying.
	 *
	 * @return void
	 */
	public function testMissingIdFailsClosedWithoutQuerying(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');

		$guard = new PokActivationGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = ['version' => 1, 'lifecycle' => 'active'];

		self::assertDenied($guard->check($object, 'activate', ''));

	}//end testMissingIdFailsClosedWithoutQuerying()
}//end class
