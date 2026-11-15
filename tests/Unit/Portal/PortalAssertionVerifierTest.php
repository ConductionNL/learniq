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
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '', bool $lazy = false): string => ($app === 'portaliq' && $key === 'jwt_signing_secret' && $lazy === false) ? self::SECRET : $default
		);
		self::assertIsArray((new PortalAssertionVerifier(config: $config))->verify(jwt: $this->mint()));

		$short = $this->createMock(IAppConfig::class);
		$short->method('getValueString')->willReturn('too-short');
		self::assertNull((new PortalAssertionVerifier(config: $short))->verify(jwt: $this->mint()));

		self::assertNull((new PortalAssertionVerifier())->verify(jwt: $this->mint()));
	}//end testOnlyTheDedicatedSecretCounts()

	/**
	 * REGRESSION (13-14 Oct 2026, 401 on every portal action): portaliq stores
	 * the secret SENSITIVE, so the legacy `IConfig::getAppValue()` returns the
	 * encrypted value and only `IAppConfig::getValueString()` returns the real
	 * secret. The mock answers both ways, so the old legacy read fails here
	 * on the signature check.
	 *
	 * @return void
	 */
	public function testTheSensitiveSecretIsReadDecrypted(): void {
		$ciphertext = '$AppConfigEncryption$' . str_repeat('a1b2c3d4e5f6', 22);
		$config = $this->createMockForIntersectionOfInterfaces([IConfig::class, IAppConfig::class]);
		$config->method('getAppValue')->willReturn($ciphertext);
		$config->method('getValueString')->willReturn(self::SECRET);

		self::assertIsArray((new PortalAssertionVerifier(config: $config))->verify(jwt: $this->mint()));
		self::assertNull((new PortalAssertionVerifier(config: $config))->verify(jwt: $this->mint(secret: $ciphertext)));
	}//end testTheSensitiveSecretIsReadDecrypted()

	/**
	 * The reason a verifier logs for one token, or '' when it accepted it.
	 *
	 * @param string $jwt The token.
	 *
	 * @return string
	 */
	private function refusalOf(string $jwt): string {
		$reasons = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function (string $message, array $context) use (&$reasons): void {
				$reasons[] = $message.': '.$context['reason'];
			}
		);
		$verifier = new PortalAssertionVerifier(config: null, secretOverride: self::SECRET, logger: $logger);

		$claims = $verifier->verify(jwt: $jwt);
		self::assertSame($claims === null, $reasons !== []);

		return implode(' | ', $reasons);
	}//end refusalOf()

	/**
	 * Every check refuses with its own reason, in the documented order, and
	 * a valid assertion is accepted without a log line.
	 *
	 * @return void
	 */
	public function testEachRefusalNamesItsCheck(): void {
		$now = time();
		$prefix = 'Learniq: portal assertion rejected: ';
		$signed = function (string $claims): string {
			$hPart = $this->b64(bytes: '{"alg":"HS256","typ":"JWT"}');
			$cPart = $this->b64(bytes: $claims);
			return $hPart.'.'.$cPart.'.'.$this->b64(bytes: hash_hmac('sha256', $hPart.'.'.$cPart, self::SECRET, true));
		};

		$cases = [
			'accepted'          => [$this->mint(), ''],
			'empty'             => ['', 'malformed structure'],
			'two parts'         => ['a.b', 'malformed structure'],
			'four parts'        => ['a.b.c.d', 'malformed structure'],
			'empty part'        => ['a..c', 'malformed structure'],
			'header not json'   => ['a.b.c', 'unexpected algorithm'],
			'alg none'          => [$this->mint(alg: 'none'), 'unexpected algorithm'],
			'alg lower'         => [$this->mint(alg: 'hs256'), 'unexpected algorithm'],
			'forged'            => [$this->mint(secret: self::OTHER_SECRET), 'signature mismatch'],
			'forged and wrong'  => [$this->mint(secret: self::OTHER_SECRET, overrides: ['use' => null]), 'signature mismatch'],
			'claims not json'   => [$signed('not json'), 'malformed claims'],
			'claims scalar'     => [$signed('42'), 'malformed claims'],
			'no use'            => [$this->mint(overrides: ['use' => null, 'iss' => 'x']), 'not an assertion'],
			'session'           => [$this->mint(overrides: ['use' => 'session']), 'not an assertion'],
			'issuer'            => [$this->mint(overrides: ['iss' => 'someone-else', 'sub' => '']), 'unexpected issuer'],
			'no subject'        => [$this->mint(overrides: ['sub' => null, 'exp' => null]), 'missing subject'],
			'empty subject'     => [$this->mint(overrides: ['sub' => '']), 'missing subject'],
			'numeric subject'   => [$this->mint(overrides: ['sub' => 7]), 'missing subject'],
			'no exp'            => [$this->mint(overrides: ['exp' => null, 'iat' => null]), 'expired or missing exp'],
			'string exp'        => [$this->mint(overrides: ['exp' => (string)($now + 60)]), 'expired or missing exp'],
			'expired'           => [$this->mint(overrides: ['iat' => ($now - 120), 'exp' => ($now - 60)]), 'expired or missing exp'],
			'no iat'            => [$this->mint(overrides: ['iat' => null]), 'implausible iat'],
			'string iat'        => [$this->mint(overrides: ['iat' => (string)$now]), 'implausible iat'],
			'future iat'        => [$this->mint(overrides: ['iat' => ($now + 600), 'exp' => ($now + 660)]), 'implausible iat'],
			'iat after exp'     => [$this->mint(overrides: ['iat' => ($now + 30), 'exp' => ($now + 20)]), 'implausible iat'],
			'iat in leeway'     => [$this->mint(overrides: ['iat' => ($now + 30), 'exp' => ($now + 60)]), ''],
		];

		foreach ($cases as $name => [$jwt, $reason]) {
			$expected = '';
			if ($reason !== '') {
				$expected = $prefix.$reason;
			}

			self::assertSame($expected, $this->refusalOf(jwt: $jwt), $name);
		}
	}//end testEachRefusalNamesItsCheck()

	/**
	 * Without a usable secret nothing is even parsed: a missing, short or
	 * config-less secret refuses first; a plain override wins over config.
	 *
	 * @return void
	 */
	public function testTheSecretIsCheckedFirstAndTheOverrideWins(): void {
		$reasons = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function (string $message, array $context) use (&$reasons): void {
				$reasons[] = $context['reason'];
			}
		);
		$wrong = $this->createMock(IAppConfig::class);
		$wrong->method('getValueString')->willReturn(self::OTHER_SECRET);
		$short = $this->createMock(IAppConfig::class);
		$short->method('getValueString')->willReturn('too-short');

		self::assertIsArray((new PortalAssertionVerifier(config: $wrong, secretOverride: self::SECRET, logger: $logger))->verify(jwt: $this->mint()));
		self::assertNull((new PortalAssertionVerifier(config: null, secretOverride: 'short', logger: $logger))->verify(jwt: ''));
		self::assertNull((new PortalAssertionVerifier(config: $short, logger: $logger))->verify(jwt: ''));
		self::assertNull((new PortalAssertionVerifier(logger: $logger))->verify(jwt: ''));
		self::assertNull((new PortalAssertionVerifier(config: $wrong, logger: $logger))->verify(jwt: $this->mint()));
		self::assertSame(
			['no usable signing secret', 'no usable signing secret', 'no usable signing secret', 'signature mismatch'],
			$reasons
		);
	}//end testTheSecretIsCheckedFirstAndTheOverrideWins()
}//end class
