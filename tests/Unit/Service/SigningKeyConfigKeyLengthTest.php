<?php

/**
 * The credential signing key must be storable for any UUID tenant.
 *
 * learniq#1232: the signing keys were stored under
 * `learniq.credential.signing.<purpose>.<tenantId>`. With a UUID tenant id
 * that is 70 to 77 characters, and Nextcloud refuses an app config key over
 * 64 (`\OC\AppConfig::KEY_MAX_LENGTH`, checked in `assertParams()`), so every
 * read and write threw and no tenant could ever hold a key. The unit tests
 * passed because their IAppConfig double had no length limit.
 *
 * This test gives the three services that share the key family
 * (KeyManagementService, CredentialSigningService,
 * LearningRecordExportSigningService) an IAppConfig that behaves like the
 * real one on this point: it throws the same InvalidArgumentException for a
 * key over 64 characters, and stores what is written. It then walks the whole
 * life of a key for many random UUID tenants: generate, read the status,
 * rotate, resolve the archived key, sign and verify.
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Learniq\Service\CredentialSigningService;
use OCA\Learniq\Service\KeyManagementService;
use OCA\Learniq\Service\LearningRecordExportSigningService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Walks the signing-key lifecycle against an app config with Nextcloud's key limit.
 */
class SigningKeyConfigKeyLengthTest extends TestCase {

	/**
	 * Nextcloud's `\OC\AppConfig::KEY_MAX_LENGTH`.
	 */
	private const KEY_MAX_LENGTH = 64;

	/**
	 * The app config store, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Every key the services asked for, read or write.
	 *
	 * @var array<string, true>
	 */
	private array $keysSeen = [];

	/**
	 * An IAppConfig that refuses long keys the way \OC\AppConfig::assertParams() does.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$guard = function (string $key): void {
			$this->keysSeen[$key] = true;
			if (strlen($key) > self::KEY_MAX_LENGTH) {
				throw new InvalidArgumentException('Value (' . $key . ') for key is too long (' . self::KEY_MAX_LENGTH . ')');
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($guard): string {
				$guard($key);
				return ($this->store[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use ($guard): bool {
				$guard($key);
				$this->store[$key] = $value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * An ICrypto whose encrypt/decrypt round-trip.
	 *
	 * @return ICrypto
	 */
	private function crypto(): ICrypto {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:' . base64_encode($plain));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $cipher): string => (string)base64_decode(substr($cipher, 4)));
		return $crypto;
	}//end crypto()

	/**
	 * The seeded school's tenant, plus random UUIDs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function tenantIds(): array {
		$cases = ['seeded school tenant' => ['ee010001-0000-4000-8000-000000000001']];
		for ($i = 0; $i < 8; $i++) {
			$bytes = random_bytes(16);
			$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
			$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
			$uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
			$cases['random uuid ' . $i] = [$uuid];
		}

		return $cases;
	}//end tenantIds()

	/**
	 * Generate, read, rotate, resolve, sign and verify: every key fits and the services agree.
	 *
	 * @param string $tenantId A UUID tenant id.
	 *
	 * @return void
	 *
	 * @dataProvider tenantIds
	 */
	public function testSigningKeyLifecycleFitsNextcloudKeyLimit(string $tenantId): void {
		$appConfig = $this->appConfig();
		$crypto = $this->crypto();
		$keys = new KeyManagementService($appConfig, $crypto);

		$this->assertNull($keys->getTenantKeyStatus(tenantId: $tenantId));

		$first = $keys->generateTenantKeypair(tenantId: $tenantId);
		$this->assertSame($first, $keys->getTenantKeyStatus(tenantId: $tenantId));

		$second = $keys->generateTenantKeypair(tenantId: $tenantId);
		$this->assertNotSame($first['fingerprint'], $second['fingerprint']);
		$this->assertSame($first['publicKey'], $keys->resolvePublicKeyByFingerprint(tenantId: $tenantId, fingerprint: $first['fingerprint']));

		$credentialSigner = new CredentialSigningService($appConfig, $crypto, $this->createMock(IURLGenerator::class));
		$this->assertNotNull($credentialSigner->signPayload(payload: ['id' => 'urn:uuid:' . $tenantId], tenantId: $tenantId));

		$exportSigner = new LearningRecordExportSigningService($appConfig, $crypto);
		$bundle = ['learner' => 'l-1', 'records' => []];
		$jws = $exportSigner->sign(bundle: $bundle, tenantId: $tenantId);
		$this->assertNotNull($jws);
		$this->assertTrue($exportSigner->verify(jws: $jws, bundle: $bundle, tenantId: $tenantId));
		$this->assertStringEndsWith(':' . $second['fingerprint'], (string)$exportSigner->resolveIssuerDid(tenantId: $tenantId));

		foreach (array_keys($this->keysSeen) as $key) {
			$this->assertLessThanOrEqual(self::KEY_MAX_LENGTH, strlen($key), $key);
		}
	}//end testSigningKeyLifecycleFitsNextcloudKeyLimit()
}//end class
