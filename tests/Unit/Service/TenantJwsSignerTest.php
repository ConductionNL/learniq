<?php

/**
 * TenantJwsSigner and DetachedJws unit tests: a detached RS256 signature over the canonical payload.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\DetachedJws;
use OCA\Learniq\Service\JcsCanonicaliser;
use OCA\Learniq\Service\SigningKeyConfigKey;
use OCA\Learniq\Service\TenantJwsSigner;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Signing with a tenant's stored key and verifying the result.
 */
#[CoversClass(TenantJwsSigner::class)]
#[CoversClass(DetachedJws::class)]
#[UsesClass(JcsCanonicaliser::class)]
#[UsesClass(SigningKeyConfigKey::class)]
class TenantJwsSignerTest extends TestCase {

	/**
	 * A generated key pair, shared by the tests.
	 *
	 * @var array{0: string, 1: string}|null
	 */
	private static ?array $keys = null;

	/**
	 * A 2048-bit RSA key pair as PEM: private, public.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function keys(): array {
		if (self::$keys === null) {
			$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			openssl_pkey_export($key, $private);
			self::$keys = [$private, openssl_pkey_get_details($key)['key']];
		}

		return self::$keys;
	}//end keys()

	/**
	 * A signer over stored values for tenant t-1.
	 *
	 * @param string $encryptedPrivate The stored, encrypted private key.
	 * @param string $public           The stored public key.
	 * @param bool   $decryptFails     Whether decryption throws.
	 *
	 * @return TenantJwsSigner
	 */
	private function signer(string $encryptedPrivate, string $public, bool $decryptFails = false): TenantJwsSigner {
		$stored = [
			SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PRIVATE, tenantId: 't-1') => $encryptedPrivate,
			SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PUBLIC, tenantId: 't-1') => $public,
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'learniq' ? ($stored[$key] ?? $default) : 'wrong app')
		);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $value) use ($decryptFails): string {
				if ($decryptFails === true) {
					throw new RuntimeException('bad secret');
				}

				return substr($value, strlen('enc:'));
			}
		);

		return new TenantJwsSigner(appConfig: $config, crypto: $crypto);
	}//end signer()

	/**
	 * A signed payload verifies against the tenant's public key, with or without its proof.
	 *
	 * @return void
	 */
	public function testASignatureVerifiesAgainstThePublicKey(): void {
		[$private, $public] = self::keys();
		$payload = ['name' => 'Diploma', 'credentialSubject' => ['id' => 'did:x', 'score' => 8]];

		$jws = $this->signer(encryptedPrivate: 'enc:' . $private, public: $public)->sign(payload: $payload, tenantId: 't-1');
		self::assertIsString($jws);
		$detached = DetachedJws::parse(jws: $jws);
		self::assertNotNull($detached);

		$header = json_decode((string)DetachedJws::base64UrlDecode(encoded: $detached->header), true);
		self::assertSame(['alg' => 'RS256', 'b64' => false, 'crit' => ['b64'], 'kid' => substr(hash('sha256', $public), 0, 32)], $header);
		self::assertTrue($detached->verifies(payload: $payload, publicKeyPem: $public));
		self::assertTrue($detached->verifies(payload: ['credentialSubject' => ['score' => 8, 'id' => 'did:x'], 'name' => 'Diploma', 'proof' => ['jws' => $jws]], publicKeyPem: $public));
	}//end testASignatureVerifiesAgainstThePublicKey()

	/**
	 * A changed payload, another key or a broken signature does not verify.
	 *
	 * @return void
	 */
	public function testATamperedPayloadOrKeyDoesNotVerify(): void {
		[$private, $public] = self::keys();
		$other = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))['key'];
		$payload = ['name' => 'Diploma'];
		$detached = DetachedJws::parse(jws: (string)$this->signer(encryptedPrivate: 'enc:' . $private, public: $public)->sign(payload: $payload, tenantId: 't-1'));

		self::assertFalse($detached->verifies(payload: ['name' => 'Diplomas'], publicKeyPem: $public));
		self::assertFalse($detached->verifies(payload: $payload, publicKeyPem: $other));
		self::assertFalse($detached->verifies(payload: $payload, publicKeyPem: 'not a key'));
		self::assertFalse((new DetachedJws(header: $detached->header, signature: '!!!'))->verifies(payload: $payload, publicKeyPem: $public));
		self::assertFalse($detached->verifies(payload: ['bad' => "\xB1"], publicKeyPem: $public));
	}//end testATamperedPayloadOrKeyDoesNotVerify()

	/**
	 * Without a usable private key nothing is signed.
	 *
	 * @return void
	 */
	public function testWithoutAUsableKeyNothingIsSigned(): void {
		[, $public] = self::keys();

		self::assertNull($this->signer(encryptedPrivate: '', public: $public)->sign(payload: ['a' => 1], tenantId: 't-1'));
		self::assertNull($this->signer(encryptedPrivate: 'enc:x', public: $public, decryptFails: true)->sign(payload: ['a' => 1], tenantId: 't-1'));
		self::assertNull($this->signer(encryptedPrivate: 'enc:' . self::keys()[0], public: $public)->sign(payload: ['a' => 1], tenantId: 't-other'));
		self::assertNull($this->signer(encryptedPrivate: 'enc:' . self::keys()[0], public: $public)->sign(payload: ['bad' => "\xB1"], tenantId: 't-1'));
	}//end testWithoutAUsableKeyNothingIsSigned()

	/**
	 * The key id and issuer DID derive from the public key; without one there is none.
	 *
	 * @return void
	 */
	public function testKeyIdAndIssuerDeriveFromThePublicKey(): void {
		$withKey = $this->signer(encryptedPrivate: '', public: 'PUBLIC-PEM');
		$fingerprint = substr(hash('sha256', 'PUBLIC-PEM'), 0, 32);

		self::assertSame('PUBLIC-PEM', $withKey->publicKeyPem(tenantId: 't-1'));
		self::assertSame($fingerprint, $withKey->keyId(tenantId: 't-1'));
		self::assertSame('did:web:learniq:t-1:' . $fingerprint, $withKey->issuerDid(tenantId: 't-1'));
		self::assertSame('unknown', $withKey->keyId(tenantId: 't-2'));
		self::assertNull($withKey->issuerDid(tenantId: 't-2'));
	}//end testKeyIdAndIssuerDeriveFromThePublicKey()

	/**
	 * A JWS is parsed only in its detached form, with both halves present.
	 *
	 * @return void
	 */
	public function testOnlyACompleteDetachedJwsParses(): void {
		$parsed = DetachedJws::parse(jws: 'aGVhZA..c2ln');

		self::assertSame(['aGVhZA', 'c2ln'], [$parsed?->header, $parsed?->signature]);
		self::assertNull(DetachedJws::parse(jws: 'aGVhZA.cGF5.c2ln'));
		self::assertNull(DetachedJws::parse(jws: '..c2ln'));
		self::assertNull(DetachedJws::parse(jws: 'aGVhZA..'));
		self::assertNull(DetachedJws::parse(jws: ''));
	}//end testOnlyACompleteDetachedJwsParses()

	/**
	 * Base64url drops padding and maps the URL-unsafe characters; decoding reverses it and refuses junk.
	 *
	 * @return void
	 */
	public function testBase64UrlRoundTrips(): void {
		$bytes = "\xFB\xFF\xBE?";

		self::assertSame('-_--Pw', DetachedJws::base64UrlEncode(bytes: $bytes));
		self::assertSame($bytes, DetachedJws::base64UrlDecode(encoded: '-_--Pw'));
		self::assertSame('', DetachedJws::base64UrlDecode(encoded: ''));
		self::assertNull(DetachedJws::base64UrlDecode(encoded: 'a*b'));
	}//end testBase64UrlRoundTrips()
}//end class
