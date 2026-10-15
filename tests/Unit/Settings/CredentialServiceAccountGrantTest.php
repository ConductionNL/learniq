<?php

/**
 * The credential schema grants the service account, and only staff and it may update.
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
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Service\ServiceAccount;
use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped register.
 *
 * @coversNothing
 */
class CredentialServiceAccountGrantTest extends TestCase {

	/**
	 * The Credential schema as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private static function credential(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		return $register['components']['schemas']['Credential'];
	}//end credential()

	/**
	 * The service group reads and updates; staff keep their rights; the holder only reads.
	 *
	 * @return void
	 */
	public function testTheServiceGroupMayRunTheOfferAndTheHolderOnlyReads(): void {
		$authorization = self::credential()['authorization'];

		self::assertContains(ServiceAccount::GROUP, $authorization['read']);
		self::assertSame(['hr', 'compliance-officers', ServiceAccount::GROUP], $authorization['update']);
		self::assertSame(['hr', 'compliance-officers'], $authorization['create']);
		self::assertNotContains('authenticated', $authorization['update']);
		self::assertSame(
			['group' => 'authenticated', 'match' => ['learnerUserId' => '$userId']],
			$authorization['read'][3]
		);
	}//end testTheServiceGroupMayRunTheOfferAndTheHolderOnlyReads()

	/**
	 * The staff path is unchanged: `offerToWallet` is still the self-loop on issued with its guard.
	 *
	 * @return void
	 */
	public function testTheStaffTransitionIsUnchanged(): void {
		$transition = self::credential()['x-openregister-lifecycle']['transitions']['offerToWallet'];

		self::assertSame(
			['from' => ['issued'], 'to' => 'issued', 'requires' => 'OCA\\Learniq\\Service\\WalletOfferDelegationService'],
			$transition
		);
	}//end testTheStaffTransitionIsUnchanged()
}//end class
