<?php

/**
 * Golden tests for the tenant RS256 detached-JWS signing and verification
 * shared by CredentialSigningService, LearningRecordExportSigningService and
 * JwsProofVerifier.
 *
 * Pins the exact JWS both signers produce (against a reference built here
 * from the RFC 7515/7797/8785 rules), every null/false branch, and the
 * accepted and refused verification outcomes.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CredentialSigningService;
use OCA\Learniq\Service\JwsProofVerifier;
use OCA\Learniq\Service\KeyManagementService;
use OCA\Learniq\Service\LearningRecordExportSigningService;
use OCA\Learniq\Service\SigningKeyConfigKey;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the shared signing rules.
 */
class SigningServicesGoldenTest extends TestCase {

	/**
	 * Test private key PEM.
	 *
	 * @var string
	 */
	private static string $privateKeyPem = '';

	/**
	 * Test public key PEM.
	 *
	 * @var string
	 */
	private static string $publicKeyPem = '';

	/**
	 * Generate one RSA keypair for the class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
		$pem = '';
		openssl_pkey_export($resource, $pem);
		self::$privateKeyPem = $pem;
		self::$publicKeyPem = openssl_pkey_get_details($resource)['key'];
	}//end setUpBeforeClass()

	/**
	 * App config returning the given stored values for tenant t1.
	 *
	 * @param string $encryptedPrivate Stored private key value.
	 * @param string $publicPem        Stored public key value.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $encryptedPrivate, string $publicPem): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($encryptedPrivate, $publicPem): string {
				self::assertSame('learniq', $app);
				return match ($key) {
					SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PRIVATE, tenantId: 't1') => $encryptedPrivate,
					SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PUBLIC, tenantId: 't1') => $publicPem,
					default => $default,
				};
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Crypto that decrypts to the test key, or throws.
	 *
	 * @param bool $fails Whether decrypt throws.
	 *
	 * @return ICrypto
	 */
	private function crypto(bool $fails = false): ICrypto {
		$crypto = $this->createMock(ICrypto::class);
		if ($fails === true) {
			$crypto->method('decrypt')->willThrowException(new \Exception('bad key'));
			return $crypto;
		}

		$crypto->method('decrypt')->with('enc')->willReturn(self::$privateKeyPem);

		return $crypto;
	}//end crypto()

	/**
	 * Both signers over the same stored keys.
	 *
	 * @param string $encryptedPrivate Stored private key value.
	 * @param string $publicPem        Stored public key value.
	 * @param bool   $decryptFails     Whether decrypt throws.
	 *
	 * @return array<string,callable(array<string,mixed>):(string|null)>
	 */
	private function signers(string $encryptedPrivate, string $publicPem, bool $decryptFails = false): array {
		$credential = new CredentialSigningService(
			$this->appConfig($encryptedPrivate, $publicPem),
			$this->crypto($decryptFails),
			$this->createMock(IURLGenerator::class)
		);
		$export = new LearningRecordExportSigningService($this->appConfig($encryptedPrivate, $publicPem), $this->crypto($decryptFails));

		return [
			'credential' => fn (array $payload): ?string => $credential->signPayload($payload, 't1'),
			'export' => fn (array $payload): ?string => $export->sign($payload, 't1'),
		];
	}//end signers()

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private static function b64url(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64url()

	/**
	 * The reference JWS for a payload already in canonical key order.
	 *
	 * @param string $canonicalJson The canonical payload JSON.
	 * @param string $kid           Expected kid.
	 *
	 * @return string
	 */
	private static function referenceJws(string $canonicalJson, string $kid): string {
		$header = self::b64url('{"alg":"RS256","b64":false,"crit":["b64"],"kid":"' . $kid . '"}');
		$signature = '';
		openssl_sign($header . '.' . $canonicalJson, $signature, self::$privateKeyPem, OPENSSL_ALGO_SHA256);

		return $header . '..' . self::b64url($signature);
	}//end referenceJws()

	/**
	 * A nested payload in scrambled order and its canonical JSON.
	 *
	 * @return array{0:array<string,mixed>,1:string}
	 */
	private static function payload(): array {
		return [
			['z' => 1, 'a' => ['y' => 'é/x', 'b' => [3, 1, ['q' => true, 'c' => null]]], 'm' => 'x'],
			'{"a":{"b":[3,1,{"c":null,"q":true}],"y":"é/x"},"m":"x","z":1}',
		];
	}//end payload()

	/**
	 * Both signers produce exactly the reference JWS, with the public-key fingerprint as kid.
	 *
	 * @return void
	 */
	public function testBothSignersProduceTheReferenceJws(): void {
		[$payload, $json] = self::payload();
		$expected = self::referenceJws($json, substr(hash('sha256', self::$publicKeyPem), 0, 32));

		foreach ($this->signers('enc', self::$publicKeyPem) as $name => $sign) {
			self::assertSame($expected, $sign($payload), $name);
		}
	}//end testBothSignersProduceTheReferenceJws()

	/**
	 * Without a stored public key the kid is 'unknown'.
	 *
	 * @return void
	 */
	public function testKidIsUnknownWithoutPublicKey(): void {
		[$payload, $json] = self::payload();
		$expected = self::referenceJws($json, 'unknown');

		foreach ($this->signers('enc', '') as $name => $sign) {
			self::assertSame($expected, $sign($payload), $name);
		}
	}//end testKidIsUnknownWithoutPublicKey()

	/**
	 * No private key, a failing decrypt, an unusable key or an unencodable payload sign nothing.
	 *
	 * @return void
	 */
	public function testSignersReturnNullWhenTheyCannotSign(): void {
		[$payload] = self::payload();
		foreach ($this->signers('', self::$publicKeyPem) as $name => $sign) {
			self::assertNull($sign($payload), $name . ' without key');
		}

		foreach ($this->signers('enc', self::$publicKeyPem, true) as $name => $sign) {
			self::assertNull($sign($payload), $name . ' failing decrypt');
		}

		foreach ($this->signers('enc', self::$publicKeyPem) as $name => $sign) {
			self::assertNull($sign(['bad' => "\xB1\x31"]), $name . ' unencodable payload');
		}
	}//end testSignersReturnNullWhenTheyCannotSign()

	/**
	 * The export issuer DID is the tenant plus the public-key fingerprint, or null without key.
	 *
	 * @return void
	 */
	public function testExportIssuerDid(): void {
		$export = new LearningRecordExportSigningService($this->appConfig('enc', self::$publicKeyPem), $this->crypto());
		self::assertSame('did:web:learniq:t1:' . substr(hash('sha256', self::$publicKeyPem), 0, 32), $export->resolveIssuerDid('t1'));

		$none = new LearningRecordExportSigningService($this->appConfig('enc', ''), $this->crypto());
		self::assertNull($none->resolveIssuerDid('t1'));
	}//end testExportIssuerDid()

	/**
	 * The export verifier accepts its own signature, ignores proof, and refuses every malformed or tampered input.
	 *
	 * @return void
	 */
	public function testExportVerifyOutcomes(): void {
		[$payload] = self::payload();
		$export = new LearningRecordExportSigningService($this->appConfig('enc', self::$publicKeyPem), $this->crypto());
		$jws = $export->sign($payload, 't1');
		[$header, $signature] = explode('..', $jws);

		self::assertTrue($export->verify($jws, $payload, 't1'));
		self::assertTrue($export->verify($jws, $payload + ['proof' => ['jws' => 'x']], 't1'));
		self::assertFalse($export->verify($jws, ['z' => 2] + $payload, 't1'));
		self::assertFalse($export->verify($header . '.' . $signature, $payload, 't1'));
		self::assertFalse($export->verify('..' . $signature, $payload, 't1'));
		self::assertFalse($export->verify($header . '..', $payload, 't1'));
		self::assertFalse($export->verify($header . '..!!!', $payload, 't1'));
		self::assertFalse($export->verify($jws, ['bad' => "\xB1\x31"], 't1'));

		$noKey = new LearningRecordExportSigningService($this->appConfig('enc', ''), $this->crypto());
		self::assertFalse($noKey->verify($jws, $payload, 't1'));

		$badPem = new LearningRecordExportSigningService($this->appConfig('enc', 'not a pem'), $this->crypto());
		self::assertFalse($badPem->verify($jws, $payload, 't1'));
	}//end testExportVerifyOutcomes()

	/**
	 * The proof verifier accepts a credential signature through its kid and refuses everything else.
	 *
	 * @return void
	 */
	public function testProofVerifierOutcomes(): void {
		[$payload] = self::payload();
		$credential = new CredentialSigningService(
			$this->appConfig('enc', self::$publicKeyPem),
			$this->crypto(),
			$this->createMock(IURLGenerator::class)
		);
		$jws = $credential->signPayload($payload, 't1');
		$kid = substr(hash('sha256', self::$publicKeyPem), 0, 32);
		[$header, $signature] = explode('..', $jws);

		$keys = $this->createMock(KeyManagementService::class);
		$keys->method('resolvePublicKeyByFingerprint')->willReturnCallback(
			fn (string $tenantId, string $fingerprint): ?string => match ([$tenantId, $fingerprint]) {
				['t1', $kid] => self::$publicKeyPem,
				['t1', 'badpem'] => 'not a pem',
				default => null,
			}
		);
		$verifier = new JwsProofVerifier($keys);

		self::assertTrue($verifier->verify($payload + ['proof' => ['jws' => $jws]], 't1'));
		self::assertFalse($verifier->verify(['z' => 2] + $payload + ['proof' => ['jws' => $jws]], 't1'));
		self::assertFalse($verifier->verify($payload + ['proof' => ['jws' => $jws]], ''));
		self::assertFalse($verifier->verify($payload, 't1'));
		self::assertFalse($verifier->verify($payload + ['proof' => ['jws' => '']], 't1'));
		self::assertFalse($verifier->verify($payload + ['proof' => ['jws' => 'nodots']], 't1'));
		self::assertFalse($verifier->verify($payload + ['proof' => ['jws' => $header . '.' . $signature]], 't1'));
		self::assertFalse($verifier->verify($payload + ['proof' => ['jws' => $header . '..']], 't1'));
		self::assertFalse($verifier->verify($payload + ['proof' => ['jws' => $header . '..!!!']], 't1'));

		$otherKid = self::b64url('{"alg":"RS256","kid":"other"}');
		self::assertFalse($verifier->verify($payload + ['proof' => ['jws' => $otherKid . '..' . $signature]], 't1'));
		$badPem = self::b64url('{"alg":"RS256","kid":"badpem"}');
		self::assertFalse($verifier->verify($payload + ['proof' => ['jws' => $badPem . '..' . $signature]], 't1'));

		self::assertSame(['a' => [2, 1], 'b' => ['c' => 1, 'd' => 2]], $verifier->canonical(['b' => ['d' => 2, 'c' => 1], 'a' => [2, 1]]));
	}//end testProofVerifierOutcomes()
}//end class
