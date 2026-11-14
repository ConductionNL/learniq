<?php

/**
 * Contract of TimetableFeedTokenService
 *
 * The public surface of TimetableFeedTokenService that other components call, so they depend
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
 * Contract of TimetableFeedTokenService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimetableFeedTokenServiceInterface {

	/**
	 * Whether the user has a feed address.
	 *
	 * @param string $uid The user id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
	 */
	public function exists(string $uid): bool;

	/**
	 * Create a new token for the user, replacing any earlier one.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string The token, shown to the user once.
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
	 */
	public function issue(string $uid): string;

	/**
	 * Remove the user's token, so their feed address answers 404.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
	 */
	public function revoke(string $uid): void;

	/**
	 * The user a token belongs to, or null.
	 *
	 * A malformed token, an unknown or replaced token, and a hash that more
	 * than one user holds all answer null.
	 *
	 * @param string $token The token from the feed address.
	 *
	 * @return string|null The user id.
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
	 */
	public function userFor(string $token): ?string;
}//end interface
