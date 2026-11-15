<?php

/**
 * Learniq Material Import Refused Exception
 *
 * Thrown when the licence rules refuse adding a source record, or refuse the
 * way it was asked for (a copy of an ND record), carrying the reason code.
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Exception;

use RuntimeException;

/**
 * The licence rules said no.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */
class MaterialImportRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $reasonCode The machine-readable reason, such as `no-derivatives`.
	 * @param string $reason     The reason in words.
	 */
	public function __construct(
		private readonly string $reasonCode,
		string $reason,
	) {
		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * The machine-readable reason.
	 *
	 * @return string The code.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function getReasonCode(): string {
		return $this->reasonCode;
	}//end getReasonCode()
}//end class
