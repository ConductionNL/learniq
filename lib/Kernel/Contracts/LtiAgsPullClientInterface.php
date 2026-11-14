<?php

/**
 * Contract of LtiAgsPullClient
 *
 * The public surface of LtiAgsPullClient that other components call, so they depend
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
 * Contract of LtiAgsPullClient.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LtiAgsPullClientInterface {

	/**
	 * Pull pending AGS messages from OpenConnector for a subscription.
	 *
	 * @param string $subscriptionId The learniq-owned event_subscription UUID.
	 * @param string $cursor The last-seen event_message cursor ('' for a first sweep).
	 *
	 * @return array{messages: array<int,mixed>, cursor: string|null}|null The pull result, or null on failure.
	 *
	 * @spec openspec/changes/archive/2026-07-13-lti-tool-placement/tasks.md#task-4.1
	 */
	public function pull(string $subscriptionId, string $cursor): ?array;
}//end interface
