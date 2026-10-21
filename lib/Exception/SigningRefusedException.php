<?php

/**
 * Learniq Signing Refused Exception
 *
 * Thrown when a signature cannot be recorded, carrying the HTTP status and a
 * machine-readable reason next to the message.
 *
 * @category Exception
 * @package  OCA\Learniq\Exception
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
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */

declare(strict_types=1);

namespace OCA\Learniq\Exception;

use RuntimeException;

/**
 * A signature was refused.
 *
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */
class SigningRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int    $status  The HTTP status to answer with.
	 * @param string $reason  Machine-readable reason, such as `role-not-held`.
	 * @param string $message Readable explanation.
	 */
	public function __construct(
		private readonly int $status,
		private readonly string $reason,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * The machine-readable reason.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
