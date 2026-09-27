<?php

/**
 * Learniq PortalAssertionVerifier unit tests.
 *
 * Tokens are minted here byte for byte the way portaliq's
 * `PortalJwtService::createAssertion()` does (same header, claim order,
 * JSON_UNESCAPED_SLASHES, unpadded base64url, HMAC-SHA256 over "header.claims"),
 * so a format drift on either side fails this suite instead of every forward.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalAssertionVerifier::verify().
 */
class PortalAssertionVerifierTest extends TestCase {

	private const SECRET = 'learniq-test-secret-0123456789ab';
	private const OTHER_SECRET = 'attacker-secret-9876543210abcdef';
	private const SUBJECT = '00000000-0000-0000-0000-000000000000';

	/**
	 * Mint an assertion exactly the way portaliq does.
	 *
	 * @param string $secret The HMAC secret.
	 * @param array<string, mixed> $overrides Claim overrides; null removes a claim.
	 * @param string $alg Header algorithm.
	 *
	 * @return string
	 */
	private function mint(string $secret = self::SECRET, array $overrides = [], string $alg = 'HS256'): string {
		$iat = time();
		$claims = [
			'sub' => self::SUBJECT,
			'audience' => 'student',
			'organisation' => '11111111-1111-1111-1111-111111111111',
			'trust' => 'low',
			'jti' => 'sessionjti0000000000000000000000',
			'use' => 'assertion',
			'iat' => $iat,
			'exp' => ($iat + 60),
			'iss' => 'portaliq',
		];
		foreach ($overrides as $claim => $value) {
			if ($value === null) {
				unset($claims[$claim]);
				continue;
			}

			$claims[$claim] = $value;
		}

		$hPart = $this->b64(bytes: (string)json_encode(['alg' => $alg, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
		$cPart = $this->b64(bytes: (string)json_encode($claims, JSON_UNESCAPED_SLASHES));

		return $hPart . '.' . $cPart . '.' . $this->b64(bytes: hash_hmac('sha256', $hPart . '.' . $cPart, $secret, true));
	}//end mint()

	/**
	 * Unpadded base64url, portaliq's encoding.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64()

	/**
	 * A verifier over a plain secret.
	 *
	 * @return PortalAssertionVerifier
	 */
	private function verifier(): PortalAssertionVerifier {
		return new PortalAssertionVerifier(config: null, secretOverride: self::SECRET);
	}//end verifier()

	/**
	 * A portaliq-style assertion verifies to its claims.
	 *
	 * @return void
	 */
	public function testAPortaliqAssertionVerifies(): void {
		$claims = $this->verifier()->verify(jwt: $this->mint());

		self::assertIsArray($claims);
		self::assertSame(self::SUBJECT, $claims['sub']);
		self::assertSame('student', $claims['audience']);
		self::assertSame('assertion', $claims['use']);
	}//end testAPortaliqAssertionVerifies()

	/**
	 * A token signed with another secret is refused.
	 *
	 * @return void
	 */
	public function testAForgedSignatureIsRefused(): void {
		self::assertNull($this->verifier()->verify(jwt: $this->mint(secret: self::OTHER_SECRET)));
	}//end testAForgedSignatureIsRefused()

	/**
	 * `none` and any algorithm other than HS256 are refused.
	 *
	 * @return void
	 */
	public function testAnotherAlgorithmIsRefused(): void {
		self::assertNull($this->verifier()->verify(jwt: $this->mint(alg: 'none')));
		self::assertNull($this->verifier()->verify(jwt: $this->mint(alg: 'RS256')));
		self::assertNull($this->verifier()->verify(jwt: $this->mint(alg: 'hs256')));
	}//end testAnotherAlgorithmIsRefused()

	/**
	 * Expired, exp-less and future-issued tokens are refused.
	 *
	 * @return void
	 */
	public function testTimeClaimsAreEnforced(): void {
		$now = time();

		self::assertNull($this->verifier()->verify(jwt: $this->mint(overrides: ['iat' => ($now - 120), 'exp' => ($now - 60)])));
		self::assertNull($this->verifier()->verify(jwt: $this->mint(overrides: ['exp' => null])));
		self::assertNull($this->verifier()->verify(jwt: $this->mint(overrides: ['exp' => (string)($now + 60)])));
		self::assertNull($this->verifier()->verify(jwt: $this->mint(overrides: ['iat' => ($now + 600), 'exp' => ($now + 660)])));
	}//end testTimeClaimsAreEnforced()

	/**
	 * A session token (no `use: assertion`), another issuer or no subject is refused.
	 *
	 * @return void
	 */
	public function testSessionTokensAndForeignIssuersAreRefused(): void {
		self::assertNull($this->verifier()->verify(jwt: $this->mint(overrides: ['use' => null])));
		self::assertNull($this->verifier()->verify(jwt: $this->mint(overrides: ['use' => 'session'])));
		self::assertNull($this->verifier()->verify(jwt: $this->mint(overrides: ['iss' => 'someone-else'])));
		self::assertNull($this->verifier()->verify(jwt: $this->mint(overrides: ['sub' => ''])));
	}//end testSessionTokensAndForeignIssuersAreRefused()

	/**
	 * Malformed input never throws and never verifies.
	 *
	 * @return void
	 */
	public function testMalformedTokensAreRefused(): void {
		self::assertNull($this->verifier()->verify(jwt: ''));
		self::assertNull($this->verifier()->verify(jwt: 'a.b'));
		self::assertNull($this->verifier()->verify(jwt: 'a..c'));
		self::assertNull($this->verifier()->verify(jwt: 'not-a-token'));
	}//end testMalformedTokensAreRefused()

	/**
	 * Only portaliq's dedicated secret counts: a short or missing one refuses
	 * everything, and the instance secret is never a fallback (portaliq no
	 * longer mints with it).
	 *
	 * @return void
	 */
	public function testOnlyTheDedicatedSecretCounts(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'portaliq' && $key === 'jwt_signing_secret') ? self::SECRET : $default
		);
		self::assertIsArray((new PortalAssertionVerifier(config: $config))->verify(jwt: $this->mint()));

		$short = $this->createMock(IConfig::class);
		$short->method('getAppValue')->willReturn('too-short');
		$short->method('getSystemValue')->willReturn(self::SECRET);
		self::assertNull((new PortalAssertionVerifier(config: $short))->verify(jwt: $this->mint()));

		self::assertNull((new PortalAssertionVerifier())->verify(jwt: $this->mint()));
	}//end testOnlyTheDedicatedSecretCounts()
}//end class
