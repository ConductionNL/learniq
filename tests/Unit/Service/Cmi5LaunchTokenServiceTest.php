<?php

/**
 * Unit tests for Cmi5LaunchTokenService.
 *
 * Uses real openssl with an in-memory app config and a pass-through crypto
 * double, so the signature checks are genuine: a token minted here verifies,
 * a tampered or expired one does not, and nothing mints before a key exists.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#2-cmi5launchtokenservice
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\Cmi5LaunchTokenService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the cmi5 launch token service.
 */
class Cmi5LaunchTokenServiceTest extends TestCase {

	/**
	 * In-memory app config values.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Build the service over the in-memory store.
	 *
	 * @return Cmi5LaunchTokenService
	 */
	private function service(): Cmi5LaunchTokenService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->store[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:' . base64_encode($plain));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $cipher): string => (string)base64_decode(substr($cipher, 4)));

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://school.example' . $path);

		return new Cmi5LaunchTokenService(crypto: $crypto, appConfig: $appConfig, urlGenerator: $urls);
	}//end service()

	/**
	 * Disabled until a key is provisioned, enabled after.
	 *
	 * @return void
	 */
	public function testEnabledOnlyAfterKeyProvisioning(): void {
		$service = $this->service();
		self::assertFalse($service->isEnabled());
		self::assertNull($service->keyStatus());

		$result = $service->generateKeyPair();

		self::assertTrue($service->isEnabled());
		self::assertSame(hash('sha256', $result['publicKey']), $result['fingerprint']);
		self::assertStringStartsWith('enc:', $this->store[Cmi5LaunchTokenService::PRIVATE_KEY_NAME], 'the private key is stored encrypted');
	}//end testEnabledOnlyAfterKeyProvisioning()

	/**
	 * Minting without a key throws instead of returning an empty token.
	 *
	 * @return void
	 */
	public function testMintingWithoutAKeyThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->service()->mintLaunchToken(learnerId: 'pupil1', lessonId: 'l1', registrationId: 'r1', activityId: 'a1');
	}//end testMintingWithoutAKeyThrows()

	/**
	 * A minted token is an RS256 JWT with the documented claims, and verifies.
	 *
	 * @return void
	 */
	public function testMintedTokenCarriesTheClaimsAndVerifies(): void {
		$service = $this->service();
		$service->generateKeyPair();

		$token  = $service->mintLaunchToken(learnerId: 'pupil1', lessonId: 'lesson-1', registrationId: 'reg-1', activityId: 'https://school.example/a');
		$parts  = explode('.', $token);
		$header = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
		$claims = $service->verifyLaunchToken(token: $token);

		self::assertSame('RS256', $header['alg']);
		self::assertNotNull($claims);
		self::assertSame('pupil1', $claims['sub']);
		self::assertSame('lesson-1', $claims['aud']);
		self::assertSame('reg-1', $claims['jti']);
		self::assertSame('reg-1', $claims['registration']);
		self::assertSame('https://school.example/a', $claims['activityId']);
		self::assertSame('https://school.example/apps/learniq', $claims['iss']);
		self::assertSame(Cmi5LaunchTokenService::TOKEN_TTL_SECONDS, $claims['exp'] - $claims['iat']);
	}//end testMintedTokenCarriesTheClaimsAndVerifies()

	/**
	 * A token whose payload was altered, or signed by another key, is refused.
	 *
	 * @return void
	 */
	public function testTamperedOrForeignTokensAreRefused(): void {
		$service = $this->service();
		$service->generateKeyPair();
		$token = $service->mintLaunchToken(learnerId: 'pupil1', lessonId: 'l1', registrationId: 'r1', activityId: 'a1');

		$parts    = explode('.', $token);
		$claims   = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
		$claims['sub'] = 'pupil2';
		$parts[1] = rtrim(strtr(base64_encode((string)json_encode($claims)), '+/', '-_'), '=');
		self::assertNull($service->verifyLaunchToken(token: implode('.', $parts)), 'altered subject');

		// A rotation replaces the key: the old token no longer verifies.
		$service->generateKeyPair();
		self::assertNull($service->verifyLaunchToken(token: $token), 'signed by the previous key');
		self::assertNull($service->verifyLaunchToken(token: 'not.a.jwt'));
	}//end testTamperedOrForeignTokensAreRefused()

	/**
	 * The auth-token survives Nextcloud's Basic handling, and verifies.
	 *
	 * Nextcloud decodes every Basic header and attempts a login when the result
	 * splits on a colon (`OC::handleAuthHeaders()`, `Session::tryBasicAuthLogin()`),
	 * answering 401 before the LRS runs. A bare JWT splits; the auth-token must not.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#7-basic-auth-reachability
	 *
	 * @return void
	 */
	public function testAuthTokenIsNotReadAsANextcloudLoginAndVerifies(): void {
		$service = $this->service();
		$service->generateKeyPair();
		$jwt       = $service->mintLaunchToken(learnerId: 'pupil1', lessonId: 'lesson-1', registrationId: 'reg-1', activityId: 'a1');
		$authToken = $service->authToken(launchToken: $jwt);

		// The same split Nextcloud applies to `Authorization: Basic <credential>`.
		$asLogin = static fn (string $credential): int => count(explode(':', (string)base64_decode($credential), 2));
		self::assertSame(2, $asLogin($jwt), 'a bare JWT reads as user:password, which is the bug');
		self::assertSame(1, $asLogin($authToken), 'the auth-token carries no user:password pair');
		self::assertStringNotContainsString(':', (string)base64_decode($authToken, true));

		self::assertSame('pupil1', $service->verifyAuthToken(credential: $authToken)['sub'] ?? null, 'Basic auth-token');
		self::assertSame('pupil1', $service->verifyAuthToken(credential: $jwt)['sub'] ?? null, 'Bearer bare JWT');
	}//end testAuthTokenIsNotReadAsANextcloudLoginAndVerifies()

	/**
	 * Credentials that are neither a valid auth-token nor a valid JWT are refused.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#7-basic-auth-reachability
	 *
	 * @return void
	 */
	public function testInvalidAuthTokensAreRefused(): void {
		$service = $this->service();
		$service->generateKeyPair();

		self::assertNull($service->verifyAuthToken(credential: 'not base64 at all!'), 'not base64');
		self::assertNull($service->verifyAuthToken(credential: base64_encode('pupil1:secret')), 'a Nextcloud login pair');
		self::assertNull($service->verifyAuthToken(credential: base64_encode('not.a.jwt')), 'a wrapped non-JWT');
		self::assertNull($service->verifyAuthToken(credential: ''), 'empty');
	}//end testInvalidAuthTokensAreRefused()
}//end class
