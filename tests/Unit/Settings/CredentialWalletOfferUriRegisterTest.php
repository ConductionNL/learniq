<?php

/**
 * The credential schema takes the wallet offer link integriq answers with.
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
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-credential-keeps-the-wallet-offer-link-while-the-offer-is-open
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * Validates real credential payloads against the shipped fragment with Opis.
 *
 * @coversNothing
 */
class CredentialWalletOfferUriRegisterTest extends TestCase {

	use RegisterSchemaPayloads;

	/**
	 * A credential from the training example set, with the link integriq's
	 * createOffer answers with, fits the schema; so does one without a link.
	 *
	 * @return void
	 */
	public function testTheWalletLinkFitsTheCredentialSchema(): void {
		$offerUri = 'https://learniq.example/index.php/apps/integriq/api/eudi/credential-offers/0b6e3c1e-1f7a-4a52-9a43-3c1d2b5e8f10';
		$credential = self::credential(walletOfferUri: 'openid-credential-offer://?credential_offer_uri=' . rawurlencode($offerUri));
		self::assertNull(self::schemaError(slug: 'credential', payload: $credential));
		self::assertNull(self::schemaError(slug: 'credential', payload: self::credential(walletOfferUri: null)));
	}//end testTheWalletLinkFitsTheCredentialSchema()

	/**
	 * Text that is no link is refused.
	 *
	 * @return void
	 */
	public function testTextThatIsNoLinkIsRefused(): void {
		self::assertStringContainsString('walletOfferUri', (string)self::schemaError(slug: 'credential', payload: self::credential(walletOfferUri: 'scan me')));
	}//end testTextThatIsNoLinkIsRefused()

	/**
	 * A credential as the training example set ships it, with an offer link.
	 *
	 * @param string|null $walletOfferUri The offer link.
	 *
	 * @return array<string, mixed>
	 */
	private static function credential(?string $walletOfferUri): array {
		return [
			'learnerId' => 'ee06000c-0000-4000-8000-000000000042',
			'learnerUserId' => 'training-deelnemer-036',
			'courseId' => 'ee060004-0000-4000-8000-000000000002',
			'kind' => 'certificate',
			'issuedAt' => '2024-08-04T10:00:00+02:00',
			'issuerDid' => 'did:web:warmtepompacademie.example',
			'signature' => 'voorbeeld.training-credential-001.niet-geldig',
			'openbadges3Payload' => ['credentialSubject' => ['id' => 'urn:learniq:learner:ee06000c-0000-4000-8000-000000000042']],
			'issuedBy' => 'Warmtepompacademie',
			'expiresAt' => '2025-08-04T10:00:00+02:00',
			'lifecycle' => 'issued',
			'tenant_id' => '00000000-0000-4000-8000-000000000000',
			'walletOfferStatus' => 'offered',
			'walletOfferedAt' => '2026-10-07T14:18:00+02:00',
			'walletAttestationRef' => '0b6e3c1e-1f7a-4a52-9a43-3c1d2b5e8f10',
			'walletOfferUri' => $walletOfferUri,
		];
	}//end credential()
}//end class
