<?php

/**
 * Contract of CredentialLearner
 *
 * The public surface of CredentialLearner that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of CredentialLearner.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CredentialLearnerInterface {

	/**
	 * Whether the credential belongs to this Nextcloud user.
	 *
	 * @param array<string, mixed> $credential The serialised credential.
	 * @param string               $userId     The Nextcloud user id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-a-learner-saves-a-certificate-to-their-europass-profile
	 */
	public function belongsTo(array $credential, string $userId): bool;

	/**
	 * Whether a value is a uuid (any version).
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-auto-enrol-on-credential-expiry
	 */
	public function isUuid(string $value): bool;

	/**
	 * The Nextcloud user id of the credential's learner, or null when it
	 * cannot be told: `learnerUserId` when set, else the `ncUserId` of the
	 * profile `learnerId` names, else a legacy non-uuid `learnerId` itself.
	 *
	 * @param array<string, mixed> $credential The serialised credential.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-another-learner-cannot-download-it
	 */
	public function userIdOf(array $credential): ?string;
}//end interface
