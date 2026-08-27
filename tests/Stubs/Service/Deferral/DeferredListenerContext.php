<?php

/**
 * Minimal DeferredListenerContext stub for Learniq unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

/**
 * The actor and buffered entries handed to a deferred job.
 *
 * ⚠️ THIS STUB MUST MIRROR openregister's PARAMETER NAMES EXACTLY.
 *
 * A unit run resolves this class to the stub; CI installs the real app and
 * resolves it to openregister. Callers here use PHP named arguments, which
 * bind by NAME, so any divergence passes locally and fails only in CI.
 *
 * Measured: this was `__construct(array $entries = [])` while canonical is
 * `__construct(?string $userId, ?string $orgUuid, array $entries)`. Eighteen
 * job tests passed locally; all six PHPUnit cells failed with
 *
 *     Argument #1 ($userId) must be of type ?string, array given
 *
 * `StubSignatureParityTest` now compares this file against canonical.
 */
class DeferredListenerContext {

	/**
	 * @param string|null                     $userId  The acting user, when known.
	 * @param string|null                     $orgUuid The acting organisation, when known.
	 * @param array<int, array<string, mixed>> $entries The buffered entries.
	 */
	public function __construct(
		private readonly ?string $userId,
		private readonly ?string $orgUuid,
		private readonly array $entries,
	) {
	}//end __construct()

	/**
	 * The acting user.
	 *
	 * @return string|null The user id, or null when none.
	 */
	public function getUserId(): ?string {
		return $this->userId;

	}//end getUserId()

	/**
	 * The acting organisation.
	 *
	 * @return string|null The organisation uuid, or null when none.
	 */
	public function getOrganisationUuid(): ?string {
		return $this->orgUuid;

	}//end getOrganisationUuid()

	/**
	 * The buffered entries.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	public function getEntries(): array {
		return $this->entries;

	}//end getEntries()
}//end class
