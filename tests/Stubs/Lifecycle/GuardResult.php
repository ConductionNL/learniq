<?php

/**
 * Test stub for OCA\OpenRegister\Lifecycle\GuardResult.
 *
 * Same factories and readers as openregister/lib/Lifecycle/GuardResult.php.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Stub for GuardResult.
 */
final class GuardResult {

	/**
	 * Constructor, through the factories only.
	 *
	 * @param bool        $allowed Whether the transition is allowed.
	 * @param string|null $message The deny message.
	 */
	private function __construct(
		private readonly bool $allowed,
		private readonly ?string $message,
	) {
	}//end __construct()

	/**
	 * Allow the transition.
	 *
	 * @return self
	 */
	public static function allow(): self {
		return new self(allowed: true, message: null);
	}//end allow()

	/**
	 * Deny the transition.
	 *
	 * @param string $message Why.
	 *
	 * @return self
	 */
	public static function deny(string $message): self {
		return new self(allowed: false, message: $message);
	}//end deny()

	/**
	 * Whether the verdict allows the transition.
	 *
	 * @return bool
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	/**
	 * The deny message, if any.
	 *
	 * @return string|null
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()
}//end class
