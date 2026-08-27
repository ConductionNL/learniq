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
 * The buffered entries handed to a deferred job.
 */
class DeferredListenerContext {

	/**
	 * @param array<int, array<string, mixed>> $entries The buffered entries.
	 */
	public function __construct(private readonly array $entries = []) {
	}//end __construct()

	/**
	 * The buffered entries.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	public function getEntries(): array {
		return $this->entries;

	}//end getEntries()
}//end class
