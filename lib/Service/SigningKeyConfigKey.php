<?php

/**
 * Learniq SigningKeyConfigKey
 *
 * The one place the app config keys of a tenant's credential signing key are
 * built. KeyManagementService writes them, CredentialSigningService and
 * LearningRecordExportSigningService read them, so all three must agree.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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

namespace OCA\Learniq\Service;

/**
 * Builds `signing.<purpose>.<tenant hash>` app config keys.
 *
 * Nextcloud refuses an app config key over 64 characters. The old scheme,
 * `learniq.credential.signing.<purpose>.<tenantId>`, reached 70 to 77
 * characters with a UUID tenant, so no key could ever be stored (#1232). The
 * app config is already namespaced by app id, so the `learniq.` prefix bought
 * nothing. The tenant id is folded into the first 32 hex characters of its
 * SHA-256, so the key is at most 52 characters whatever the tenant id looks
 * like. No installation holds a key under the old scheme, because none could
 * be written, so there is nothing to migrate.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */
final class SigningKeyConfigKey {
	/**
	 * Encrypted private key of the active keypair.
	 */
	public const PRIVATE = 'private';

	/**
	 * Public key (PEM) of the active keypair.
	 */
	public const PUBLIC = 'public';

	/**
	 * Fingerprint of the active public key.
	 */
	public const FINGERPRINT = 'fingerprint';

	/**
	 * JSON list of archived (verification-only) public keys.
	 */
	public const ARCHIVED = 'archived';

	/**
	 * The app config key for one purpose of a tenant's signing key.
	 *
	 * @param string $purpose  One of the class constants.
	 * @param string $tenantId The tenant id.
	 *
	 * @return string The app config key, at most 52 characters.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
	 */
	public static function forTenant(string $purpose, string $tenantId): string {
		return 'signing.' . $purpose . '.' . substr(hash('sha256', $tenantId), 0, 32);
	}//end forTenant()
}//end class
