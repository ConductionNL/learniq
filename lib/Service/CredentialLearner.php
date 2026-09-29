<?php

/**
 * Learniq Credential Learner
 *
 * Answers "whose credential is this?" in Nextcloud user ids.
 *
 * A Credential names its learner twice. `learnerId` is the LearnerProfile
 * uuid, as the schema declares (format uuid, $ref LearnerProfile): it is what
 * the signed payload's subject is built from and what staff views filter on.
 * `learnerUserId` is the learner's Nextcloud user id, written next to it at
 * issuance, so a comparison with the signed-in user and the register's read
 * rule need no profile lookup.
 *
 * Rows written before `learnerUserId` existed have only `learnerId`. For those
 * the answer comes from the profile the uuid names (its `ncUserId`); a legacy
 * row whose `learnerId` is not a uuid at all held the user id there, so that
 * value is the answer.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-a-learner-downloads-their-certificate-for-europass
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Resolves a credential's learner to a Nextcloud user id.
 *
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-a-learner-downloads-their-certificate-for-europass
 */
class CredentialLearner {

	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Constructor.
	 *
	 * @param LearnerRefResolver $profiles LearnerProfile by uuid.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerRefResolver $profiles,
	) {
	}//end __construct()

	/**
	 * The Nextcloud user id of the credential's learner, or null when it
	 * cannot be told: `learnerUserId` when set, else the `ncUserId` of the
	 * profile `learnerId` names, else a legacy non-uuid `learnerId` itself.
	 *
	 * @param array<string, mixed> $credential The serialised credential.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#scenario-another-learner-cannot-download-it
	 */
	public function userIdOf(array $credential): ?string {
		$userId = ($credential['learnerUserId'] ?? null);
		if (is_string($userId) === true && $userId !== '') {
			return $userId;
		}

		$learnerId = ($credential['learnerId'] ?? null);
		if (is_string($learnerId) === false || $learnerId === '') {
			return null;
		}

		if ($this->isUuid(value: $learnerId) === false) {
			return $learnerId;
		}

		$ncUserId = ($this->profiles->byRef(learnerRef: $learnerId)['ncUserId'] ?? null);
		if (is_string($ncUserId) === true && $ncUserId !== '') {
			return $ncUserId;
		}

		return null;
	}//end userIdOf()

	/**
	 * Whether the credential belongs to this Nextcloud user.
	 *
	 * @param array<string, mixed> $credential The serialised credential.
	 * @param string               $userId     The Nextcloud user id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#scenario-a-learner-saves-a-certificate-to-their-europass-profile
	 */
	public function belongsTo(array $credential, string $userId): bool {
		if ($userId === '') {
			return false;
		}

		return $this->userIdOf(credential: $credential) === $userId;
	}//end belongsTo()

	/**
	 * Whether a value is a uuid (any version).
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-auto-enrol-on-credential-expiry
	 */
	public function isUuid(string $value): bool {
		return preg_match(self::UUID_PATTERN, $value) === 1;
	}//end isUuid()
}//end class
