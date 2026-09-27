<?php

/**
 * Unit tests for WalletClaimSyncService.
 *
 * Covers the `recordWalletClaim` guard contract per
 * `specs/certification/spec.md`: writes `walletOfferStatus=claimed` and
 * `walletClaimedAt` into the transition context and always allows the
 * transition.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\WalletClaimSyncService;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WalletClaimSyncService: the recordWalletClaim guard and claim().
 */
class WalletClaimSyncServiceTest extends TestCase {

	/**
	 * The recordWalletClaim guard is one OpenRegister can run, and it always allows.
	 *
	 * @return void
	 */
	public function testGuardAlwaysAllowsTheClaim(): void {
		$service = new WalletClaimSyncService();

		self::assertInstanceOf(LifecycleGuardInterface::class, $service);
		self::assertTrue($service->check(['id' => 'credential-1', 'lifecycle' => 'issued'], 'recordWalletClaim', '')->isAllowed());
	}//end testGuardAlwaysAllowsTheClaim()

	/**
	 * A claimed offer writes `walletOfferStatus=claimed` and `walletClaimedAt`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#scenario-a-claimed-wallet-offer-updates-the-credentials-wallet-offer-status
	 */
	public function testClaimWritesStatusAndTimestamp(): void {
		$credential = [
			'id' => 'credential-1',
			'lifecycle' => 'issued',
			'walletOfferStatus' => 'offered',
			'walletClaimedAt' => null,
		];

		$saved = (new WalletClaimSyncService())->claim(credential: $credential);

		self::assertSame('claimed', $saved['walletOfferStatus']);
		self::assertNotEmpty($saved['walletClaimedAt']);
		self::assertSame('issued', $saved['lifecycle']);
	}//end testClaimWritesStatusAndTimestamp()
}//end class
