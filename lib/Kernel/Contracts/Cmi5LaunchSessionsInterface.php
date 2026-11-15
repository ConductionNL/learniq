<?php

/**
 * Contract of Cmi5LaunchSessions
 *
 * The public surface of Cmi5LaunchSessions that other components call, so they depend
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
 * Contract of Cmi5LaunchSessions.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface Cmi5LaunchSessionsInterface {

	/**
	 * Hold a launch token behind a fresh one-time fetch code.
	 *
	 * @param string $token The launch token.
	 *
	 * @return string The fetch code.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#5-launch-endpoint-wiring
	 */
	public function issueFetchCode(string $token): string;

	/**
	 * Redeem a fetch code once.
	 *
	 * @param string $code The fetch code.
	 *
	 * @return string|null The launch token, or null when the code was used or has expired.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#5-launch-endpoint-wiring
	 */
	public function redeemFetchCode(string $code): ?string;

	/**
	 * Write the LMS.LaunchData state document for one launch.
	 *
	 * @param string               $learnerId The learner's uid.
	 * @param array<string, mixed> $launch    `actor`, `lesson` (as read), `lessonId`, `activityId` and `registration`.
	 *
	 * @return bool True when the document is stored; false (and logged) when it is not.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function writeLaunchData(string $learnerId, array $launch): bool;
}//end interface
