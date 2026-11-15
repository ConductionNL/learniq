<?php

/**
 * Learniq Key Management Service
 *
 * Generates and stores RSA-2048 keypairs for per-tenant credential signing.
 * Legitimate PHP per ADR-031: "Cryptographic operations" exception. The private
 * key is encrypted at rest via OCP\Security\ICrypto; the public key and
 * fingerprint are stored plain for verification.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use InvalidArgumentException;
use OCA\Learniq\Kernel\Contracts\KeyManagementServiceInterface;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use RuntimeException;

/**
 * Generates and stores RSA-2048 per-tenant keypairs for OB3 credential signing.
 *
 * Keys are stored in an append-only history model: on rotation the new keypair
 * becomes active while the old keypair is retained as verification-only (so that
 * previously-issued credentials remain verifiable). Per OB3 spec, verification
 * keys must remain resolvable for the lifetime of issued credentials.
 *
 * Cryptographic operation — legitimate PHP seam per ADR-031.
 */
class KeyManagementService implements KeyManagementServiceInterface {

	/**
	 * Maximum number of archived public keys retained per tenant.
	 * Keys beyond this cap are pruned oldest-first (L2).
	 */
	private const MAX_ARCHIVED_KEYS = 32;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud application configuration.
	 * @param ICrypto $crypto Encrypt/decrypt wrapper for private key storage.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICrypto $crypto,
	) {
	}//end __construct()

	/**
	 * Generate an RSA-2048 keypair for a tenant and persist it.
	 *
	 * The private key is encrypted via ICrypto before storage. When a key already
	 * exists for the tenant the previous public key is archived (verification-only)
	 * before the new key becomes active — old credentials signed under the previous
	 * key remain verifiable. Fixes #176.
	 *
	 * @param string $tenantId Tenant UUID (must be non-empty). Fixes #211.
	 *
	 * @return array{fingerprint: string, publicKey: string} Public key data.
	 *
	 * @throws \InvalidArgumentException If tenantId is empty.
	 * @throws \RuntimeException If OpenSSL key generation fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 * @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-rotating-the-signing-key-keeps-earlier-certificates-checkable
	 */
	public function generateTenantKeypair(string $tenantId): array {
		// #211: validate tenantId before touching key storage.
		if ($tenantId === '') {
			throw new InvalidArgumentException('tenantId must not be empty.');
		}

		$pems = $this->exportKeypair(resource: $this->newKey());

		// #176: archive the current public key before overwriting the active key so that
		// previously-issued credentials (which embed the old kid) remain verifiable.
		$this->archiveCurrentPublicKey(tenantId: $tenantId);

		return $this->storeKeypair(tenantId: $tenantId, pems: $pems);
	}//end generateTenantKeypair()

	/**
	 * A new RSA-2048 key.
	 *
	 * @return \OpenSSLAsymmetricKey The key.
	 *
	 * @throws \RuntimeException If OpenSSL key generation fails.
	 */
	private function newKey(): \OpenSSLAsymmetricKey {
		$resource = openssl_pkey_new(
			[
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
				'private_key_bits' => 2048,
			]
		);

		if ($resource === false) {
			throw new RuntimeException('OpenSSL key generation failed: ' . openssl_error_string());
		}

		return $resource;
	}//end newKey()

	/**
	 * The private and public PEM of a key.
	 *
	 * @param \OpenSSLAsymmetricKey $resource The key.
	 *
	 * @return array{0: string, 1: string} The private PEM and the public PEM.
	 *
	 * @throws \RuntimeException If either cannot be exported.
	 */
	private function exportKeypair(\OpenSSLAsymmetricKey $resource): array {
		$privateKeyPem = '';
		// #212: check openssl_pkey_export return value — silent failure stores null key.
		if (openssl_pkey_export($resource, $privateKeyPem) === false) {
			throw new RuntimeException('openssl_pkey_export() failed: ' . openssl_error_string());
		}

		$details = openssl_pkey_get_details($resource);
		$publicKeyPem = $details['key'] ?? '';

		if ($privateKeyPem === '' || $publicKeyPem === '') {
			throw new RuntimeException('Failed to export keypair from OpenSSL resource.');
		}

		return [$privateKeyPem, $publicKeyPem];
	}//end exportKeypair()

	/**
	 * Store a tenant's new keypair as its active key: the private key
	 * encrypted, the public key and its fingerprint.
	 *
	 * @param string                      $tenantId The tenant.
	 * @param array{0: string, 1: string} $pems     The private PEM and the public PEM.
	 *
	 * @return array{fingerprint: string, publicKey: string} Public key data.
	 */
	private function storeKeypair(string $tenantId, array $pems): array {
		[$privateKeyPem, $publicKeyPem] = $pems;
		$fingerprint = $this->fingerprintOf(pem: $publicKeyPem);
		$encryptedPrivKey = $this->crypto->encrypt($privateKeyPem);

		$this->store(key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PRIVATE, tenantId: $tenantId), value: $encryptedPrivKey);
		$this->store(key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PUBLIC, tenantId: $tenantId), value: $publicKeyPem);
		$this->store(key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::FINGERPRINT, tenantId: $tenantId), value: $fingerprint);

		return [
			'fingerprint' => $fingerprint,
			'publicKey' => $publicKeyPem,
		];
	}//end storeKeypair()

	/**
	 * A public key's fingerprint.
	 *
	 * #193: the 32-char (full 128-bit) prefix of its SHA-256, to reduce
	 * birthday-attack collision risk.
	 *
	 * @param string $pem The public key PEM.
	 *
	 * @return string The fingerprint.
	 */
	private function fingerprintOf(string $pem): string {
		return substr(hash('sha256', $pem), 0, 32);
	}//end fingerprintOf()

	/**
	 * Return the public key and fingerprint for a tenant, or null if absent.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array{fingerprint: string, publicKey: string}|null Key data, or null if not configured.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	public function getTenantKeyStatus(string $tenantId): ?array {
		$publicKey = $this->appConfig->getValueString(
			app: 'learniq',
			key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PUBLIC, tenantId: $tenantId),
			default: ''
		);
		$fingerprint = $this->appConfig->getValueString(
			app: 'learniq',
			key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::FINGERPRINT, tenantId: $tenantId),
			default: ''
		);

		if ($publicKey === '') {
			return null;
		}

		return [
			'fingerprint' => $fingerprint,
			'publicKey' => $publicKey,
		];
	}//end getTenantKeyStatus()

	/**
	 * Resolve a historical (archived or active) public key by its fingerprint.
	 *
	 * Verifiers that receive a credential signed under a rotated key can call this
	 * method to locate the matching public key without holding prior state. Fixes #176.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $fingerprint 32-char hex fingerprint (first 32 chars of SHA-256 of PEM).
	 *
	 * @return string|null PEM string of the matching public key, or null if not found.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	public function resolvePublicKeyByFingerprint(string $tenantId, string $fingerprint): ?string {
		// Check the active key first.
		$activePublicKey = $this->activePublicKey(tenantId: $tenantId);
		if ($activePublicKey !== '' && $this->fingerprintOf(pem: $activePublicKey) === $fingerprint) {
			return $activePublicKey;
		}

		return $this->archivedKeyWithFingerprint(tenantId: $tenantId, fingerprint: $fingerprint);
	}//end resolvePublicKeyByFingerprint()

	/**
	 * The archived public key with this fingerprint, or null.
	 *
	 * @param string $tenantId    Tenant UUID.
	 * @param string $fingerprint 32-char hex fingerprint.
	 *
	 * @return string|null PEM string of the matching public key, or null if not found.
	 */
	private function archivedKeyWithFingerprint(string $tenantId, string $fingerprint): ?string {
		foreach (($this->archivedKeys(tenantId: $tenantId) ?? []) as $pemEntry) {
			if (is_string($pemEntry) === true && $this->fingerprintOf(pem: $pemEntry) === $fingerprint) {
				return $pemEntry;
			}
		}

		return null;
	}//end archivedKeyWithFingerprint()

	/**
	 * Archive the current active public key before rotation.
	 *
	 * Appends the current active public key (if any) to the tenant's archived-key
	 * list so previously-issued credentials can still be verified. Private keys are
	 * NOT archived — only the public key is needed for verification. Fixes #176.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return void
	 */
	private function archiveCurrentPublicKey(string $tenantId): void {
		$current = $this->activePublicKey(tenantId: $tenantId);
		if ($current === '') {
			// No active key to archive — first-time generation.
			return;
		}

		$archived = ($this->archivedKeys(tenantId: $tenantId) ?? []);

		// Avoid duplicates.
		if (in_array($current, $archived, strict: true) === false) {
			$archived[] = $current;
		}

		// L2: cap archive at MAX_ARCHIVED_KEYS — prune oldest entries first.
		if (count($archived) > self::MAX_ARCHIVED_KEYS) {
			$archived = array_slice($archived, count($archived) - self::MAX_ARCHIVED_KEYS);
		}

		$this->storeArchive(tenantId: $tenantId, archived: $archived);
	}//end archiveCurrentPublicKey()

	/**
	 * Store a tenant's archive of public keys.
	 *
	 * @param string                  $tenantId Tenant UUID.
	 * @param array<array-key, mixed> $archived The archive.
	 *
	 * @return void
	 */
	private function storeArchive(string $tenantId, array $archived): void {
		$this->store(
			key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::ARCHIVED, tenantId: $tenantId),
			value: (string)json_encode($archived)
		);
	}//end storeArchive()

	/**
	 * A tenant's active public key, or '' when it has none.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string The PEM, or ''.
	 */
	private function activePublicKey(string $tenantId): string {
		return $this->appConfig->getValueString(
			app: 'learniq',
			key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PUBLIC, tenantId: $tenantId),
			default: ''
		);
	}//end activePublicKey()

	/**
	 * A tenant's archived public keys as stored, or null when the stored
	 * value is not a JSON array.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<array-key, mixed>|null The archive.
	 */
	private function archivedKeys(string $tenantId): ?array {
		$archived = json_decode(
			$this->appConfig->getValueString(
				app: 'learniq',
				key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::ARCHIVED, tenantId: $tenantId),
				default: '[]'
			),
			true
		);
		if (is_array($archived) === false) {
			return null;
		}

		return $archived;
	}//end archivedKeys()

	/**
	 * Store one of Learniq's app values.
	 *
	 * @param string $key   The config key.
	 * @param string $value The value.
	 *
	 * @return void
	 */
	private function store(string $key, string $value): void {
		$this->appConfig->setValueString(app: 'learniq', key: $key, value: $value);
	}//end store()
}//end class
