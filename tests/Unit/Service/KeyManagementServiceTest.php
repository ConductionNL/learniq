<?php

/**
 * Learniq KeyManagementService tests.
 *
 * Pins, over a real RSA keypair and an in-memory app config, what the key
 * service writes and in which order when a tenant key is generated or
 * rotated, how the archive of earlier public keys is kept, and which public
 * key a fingerprint resolves to.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Learniq\Service\KeyManagementService;
use OCA\Learniq\Service\SigningKeyConfigKey;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * The key service over an in-memory app config.
 */
class KeyManagementServiceTest extends TestCase {

	/**
	 * Stored values by key.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * Every write, as `key` in order.
	 *
	 * @var list<string>
	 */
	private array $writes = [];

	/**
	 * The service over the in-memory config; ICrypto prefixes `enc:`.
	 *
	 * @return KeyManagementService
	 */
	private function service(): KeyManagementService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'learniq' ? ($this->values[$key] ?? $default) : $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				self::assertSame('learniq', $app);
				$this->values[$key] = $value;
				$this->writes[] = $key;
				return true;
			}
		);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:'.$plain);

		return new KeyManagementService($config, $crypto);
	}//end service()

	/**
	 * The config key of one purpose for tenant t1.
	 *
	 * @param string $purpose The purpose.
	 *
	 * @return string
	 */
	private static function key(string $purpose): string {
		return SigningKeyConfigKey::forTenant(purpose: $purpose, tenantId: 't1');
	}//end key()

	/**
	 * An empty tenant is refused before anything is touched.
	 *
	 * @return void
	 */
	public function testAnEmptyTenantIsRefused(): void {
		try {
			$this->service()->generateTenantKeypair(tenantId: '');
			self::fail('expected a refusal');
		} catch (InvalidArgumentException $exception) {
			self::assertSame('tenantId must not be empty.', $exception->getMessage());
		}

		self::assertSame([], $this->writes);
	}//end testAnEmptyTenantIsRefused()

	/**
	 * A first key stores the encrypted private key, the public key and its
	 * 32-character fingerprint, in that order, and archives nothing; a
	 * rotation archives the previous public key first.
	 *
	 * @return void
	 */
	public function testGenerationStoresTheKeyAndRotationArchivesThePreviousOne(): void {
		$service = $this->service();
		$first = $service->generateTenantKeypair(tenantId: 't1');

		self::assertSame([self::key('private'), self::key('public'), self::key('fingerprint')], $this->writes);
		self::assertSame(['fingerprint', 'publicKey'], array_keys($first));
		self::assertSame(substr(hash('sha256', $first['publicKey']), 0, 32), $first['fingerprint']);
		self::assertSame($first['publicKey'], $this->values[self::key('public')]);
		self::assertSame($first['fingerprint'], $this->values[self::key('fingerprint')]);
		self::assertStringStartsWith('enc:-----BEGIN PRIVATE KEY-----', $this->values[self::key('private')]);

		$this->writes = [];
		$second = $service->generateTenantKeypair(tenantId: 't1');

		self::assertSame([self::key('archived'), self::key('private'), self::key('public'), self::key('fingerprint')], $this->writes);
		self::assertSame([$first['publicKey']], json_decode($this->values[self::key('archived')], true));
		self::assertNotSame($first['publicKey'], $second['publicKey']);
		self::assertSame($first['publicKey'], $service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: $first['fingerprint']));
		self::assertSame($second['publicKey'], $service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: $second['fingerprint']));
	}//end testGenerationStoresTheKeyAndRotationArchivesThePreviousOne()

	/**
	 * The archive skips a key it already holds, starts afresh over unreadable
	 * JSON, and keeps only the newest 32 keys.
	 *
	 * @return void
	 */
	public function testTheArchiveIsDeduplicatedAndCapped(): void {
		$service = $this->service();

		$this->values[self::key('public')] = 'pem-current';
		$this->values[self::key('archived')] = json_encode(['pem-current', 'pem-old']);
		$service->generateTenantKeypair(tenantId: 't1');
		self::assertSame(['pem-current', 'pem-old'], json_decode($this->values[self::key('archived')], true));

		$this->values[self::key('public')] = 'pem-current';
		$this->values[self::key('archived')] = 'not json';
		$service->generateTenantKeypair(tenantId: 't1');
		self::assertSame(['pem-current'], json_decode($this->values[self::key('archived')], true));

		$full = array_map(static fn (int $n): string => 'pem-'.$n, range(1, 32));
		$this->values[self::key('public')] = 'pem-current';
		$this->values[self::key('archived')] = json_encode($full);
		$service->generateTenantKeypair(tenantId: 't1');
		self::assertSame(array_merge(array_slice($full, 1), ['pem-current']), json_decode($this->values[self::key('archived')], true));
	}//end testTheArchiveIsDeduplicatedAndCapped()

	/**
	 * A fingerprint resolves to the active key first, then to an archived
	 * string entry; anything else, or an unreadable archive, resolves to null.
	 *
	 * @return void
	 */
	public function testAFingerprintResolvesToItsPublicKey(): void {
		$service = $this->service();
		$fp = static fn (string $pem): string => substr(hash('sha256', $pem), 0, 32);

		self::assertNull($service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: $fp('')));

		$this->values[self::key('public')] = 'pem-active';
		$this->values[self::key('archived')] = json_encode([7, 'pem-a', ['pem-b'], 'pem-active']);
		self::assertSame('pem-active', $service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: $fp('pem-active')));
		self::assertSame('pem-a', $service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: $fp('pem-a')));
		self::assertNull($service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: $fp('pem-b')));
		self::assertNull($service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: $fp('7')));
		self::assertNull($service->resolvePublicKeyByFingerprint(tenantId: 't2', fingerprint: $fp('pem-active')));
		self::assertNull($service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: 'unknown'));

		$this->values[self::key('archived')] = '"pem-a"';
		self::assertNull($service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: $fp('pem-a')));

		unset($this->values[self::key('public')]);
		$this->values[self::key('archived')] = json_encode(['pem-a']);
		self::assertSame('pem-a', $service->resolvePublicKeyByFingerprint(tenantId: 't1', fingerprint: $fp('pem-a')));
		self::assertSame([], $this->writes);
	}//end testAFingerprintResolvesToItsPublicKey()
}//end class
