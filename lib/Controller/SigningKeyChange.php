<?php

/**
 * Learniq signing-key change in progress.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * One generation or rotation of a tenant's credential signing key while
 * KeyAdminController makes it and writes it to the audit log. It never
 * leaves that controller.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */
final class SigningKeyChange {

	/**
	 * The tenant whose key changes.
	 *
	 * @var string
	 */
	public string $tenantId = '';

	/**
	 * Whether the change replaces an existing key.
	 *
	 * @var boolean
	 */
	public bool $rotated = false;

	/**
	 * The new key's fingerprint, once generated.
	 *
	 * @var string
	 */
	public string $fingerprint = '';

	/**
	 * The admin audit log entry for this change.
	 *
	 * @param string $actor The uid of the admin who made it, or `unknown`.
	 *
	 * @return CriticalActionPerformedEvent
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
	 */
	public function auditEvent(string $actor): CriticalActionPerformedEvent {
		return new CriticalActionPerformedEvent(
			'Credential signing key %s by "%s" for tenant "%s", new fingerprint "%s"',
			[
				'action' => $this->action(),
				'actor' => $actor,
				'tenantId' => $this->tenantId,
				'fingerprint' => $this->fingerprint,
			]
		);
	}//end auditEvent()

	/**
	 * The word the audit log uses for this change.
	 *
	 * @return string `rotated` or `generated`.
	 */
	private function action(): string {
		if ($this->rotated === true) {
			return 'rotated';
		}

		return 'generated';
	}//end action()
}//end class
