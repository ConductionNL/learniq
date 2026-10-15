<?php

/**
 * Learniq Service Account Unavailable Exception
 *
 * Thrown when learniq's service account cannot be used, before anything runs.
 * The caller writes nothing.
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
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
 */

declare(strict_types=1);

namespace OCA\Learniq\Exception;

use RuntimeException;

/**
 * No usable service account: nothing was written.
 *
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
 */
class ServiceAccountUnavailableException extends RuntimeException {
}//end class
