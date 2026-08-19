<?php

/**
 * Learniq MigrateAppConfigKeys Repair Step
 *
 * rename-to-learniq boundary 4 (IAppConfig key migration): copies every
 * stored `IAppConfig` value from the old `scholiq` app-config namespace to
 * the new `learniq` namespace. Nextcloud's `IAppConfig` is namespaced by app
 * id at the storage layer (`oc_appconfig.appid`), so this is not a key
 * rename within one namespace — the app's own id changed, so this is a copy
 * across app-config namespaces.
 *
 * WHY EVERY KEY, NOT A FIXED LIST. migration.md's original design named 9
 * "known" keys (course, module, listCourses, getCourseDetails,
 * credentialVerify.verify, the 4-member credential.signing.* family, and
 * actions). Measuring the actual call sites at implementation time found
 * that list was incomplete in two ways this step corrects:
 *   - credential.signing.private/public/fingerprint/archived_keys are NOT
 *     four fixed keys — they are PREFIXES concatenated with a per-tenant
 *     UUID (KeyManagementService/CredentialSigningService), so the set of
 *     actual stored keys is unbounded and cannot be hardcoded.
 *   - several more keys exist that migration.md's list never named:
 *     `register`, `lti_ags_subscription_id`, `lti_ags_pull_cursor`,
 *     `openconnector_api_token`, `openconnector_api_user`,
 *     `openconnector_callback_token`, `docudesk_api_token`, and
 *     `keygen.last_at.{tenantId}` (also tenant-prefixed).
 * Rather than maintain a second, still-possibly-incomplete hardcoded list,
 * this step enumerates every key IAppConfig actually has under the old
 * namespace (`IAppConfig::getKeys()`) and copies each one. This is a
 * superset of migration.md's named list and is exhaustive by construction —
 * a key added under the old namespace by a not-yet-rebased in-flight change
 * still gets migrated.
 *
 * The `register` key is a known, called-out exception in shape only: its
 * stored VALUE (the literal string `scholiq`) becomes stale once
 * RenameRegisterSlug has run, since the app's actual register slug is now
 * `learniq`. This step still copies it verbatim, deliberately, for the same
 * reason `scholiq.actions` is copied verbatim and never rewritten: this step
 * migrates a NAMESPACE, not the semantic content of what is stored in it.
 * No code path in this app reads the `register` config key to resolve an
 * object lookup (every register-slug call site was changed directly to the
 * literal `learniq` in the same release as this step), so a stale display
 * value here is cosmetic, not a functional break — but it is a known,
 * intentional gap, not an oversight.
 *
 * SAFETY. Idempotent and non-destructive, mirroring RenameDutchColumns and
 * RenameRegisterSlug:
 *   - a key is copied only when its old-namespace value is non-empty AND its
 *     new-namespace value is not already set (never overwrites an
 *     already-migrated or admin-edited `learniq.*` value);
 *   - the old `scholiq.*` rows are never deleted — mirrors
 *     RenameDutchColumns's "old column left in place" principle;
 *   - `getValueString()` / `setValueString()` round-trip every value as its
 *     raw stored string, regardless of which typed getter/setter wrote it —
 *     IAppConfig's storage layer is string-based; the typed accessors only
 *     coerce on read, so a string round-trip cannot lose or corrupt data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-stored-per-install-configuration-survives-the-identity-rename
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Copy every stored IAppConfig value from the scholiq namespace to learniq.
 *
 * @spec openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-stored-per-install-configuration-survives-the-identity-rename
 */
class MigrateAppConfigKeys implements IRepairStep {
	/**
	 * The app-config namespace prior to the rename.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'scholiq';

	/**
	 * The app-config namespace after the rename.
	 *
	 * @var string
	 */
	private const NEW_APP_ID = 'learniq';

	/**
	 * Config keys Nextcloud owns and manages for every app. These MUST NOT be
	 * copied.
	 *
	 * `AppManager::enableApp()` writes `enabled` through the deprecated
	 * `IAppConfig::setValue()`, which stores type MIXED. Copying the old app's
	 * `enabled` with `setValueString()` stores type STRING, and the next
	 * `app:enable` then dies with `AppConfigTypeConflictException: conflict
	 * between new type (mixed) and old type (string)` — permanently, because
	 * the failure happens before the app can run anything that would fix it.
	 * Observed on the dev instance 2026-08-19: the app could not be enabled at
	 * all until these rows were deleted by hand.
	 *
	 * @var string[]
	 */
	private const RESERVED_KEYS = [
		'enabled',
		'installed_version',
		'types',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Copy learniq IAppConfig values from the scholiq namespace to learniq';
	}//end getName()

	/**
	 * Run the config-key migration.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-stored-per-install-configuration-survives-the-identity-rename
	 */
	public function run(IOutput $output): void {
		$keys = $this->oldKeys();
		if ($keys === []) {
			$output->info('MigrateAppConfigKeys: no stored scholiq config keys on this install; nothing to do.');
			return;
		}

		$migrated = 0;
		$alreadyPresent = 0;
		$emptySource = 0;

		$skippedReserved = 0;

		foreach ($keys as $key) {
			if (in_array($key, self::RESERVED_KEYS, strict: true) === true) {
				$skippedReserved++;
				continue;
			}

			$old = $this->appConfig->getValueString(self::OLD_APP_ID, $key, '');
			if ($old === '') {
				$emptySource++;
				continue;
			}

			$existing = $this->appConfig->getValueString(self::NEW_APP_ID, $key, '');
			if ($existing !== '') {
				$alreadyPresent++;
				continue;
			}

			try {
				$this->appConfig->setValueString(self::NEW_APP_ID, $key, $old);
				$migrated++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'MigrateAppConfigKeys: could not migrate one key; leaving it under the old namespace.',
					['key' => $key, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		$output->info(
			'MigrateAppConfigKeys: ' . $migrated . ' key(s) migrated, ' . $alreadyPresent
			. ' already present, ' . $emptySource . ' had no value to migrate, '
			. $skippedReserved . ' skipped as Nextcloud-reserved.'
		);
	}//end run()

	/**
	 * Every key currently stored under the old app-config namespace.
	 *
	 * @return array<int, string>
	 */
	private function oldKeys(): array {
		try {
			return $this->appConfig->getKeys(self::OLD_APP_ID);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MigrateAppConfigKeys: could not enumerate scholiq config keys; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}//end oldKeys()
}//end class
