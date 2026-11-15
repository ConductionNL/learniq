<?php

/**
 * Learniq Exchange Request Refused Exception
 *
 * Thrown when integriq refused an exchange request, carrying its refusal code
 * (such as `target-unknown` or `mapping-missing`) next to the reason.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */

declare(strict_types=1);

namespace OCA\Learniq\Exception;

use RuntimeException;

/**
 * Integriq said no to an exchange request.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */
class ExchangeRequestRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $refusalCode Integriq's machine-readable refusal code.
	 * @param string $reason      Integriq's reason.
	 */
	public function __construct(
		private readonly string $refusalCode,
		string $reason,
	) {
		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * Integriq's refusal code.
	 *
	 * @return string The code.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	public function getRefusalCode(): string {
		return $this->refusalCode;
	}//end getRefusalCode()
}//end class
