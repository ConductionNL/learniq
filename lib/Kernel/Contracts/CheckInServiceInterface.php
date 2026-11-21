<?php

/**
 * Contract of CheckInService
 *
 * The public surface of CheckInService that other components call, so they depend
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

use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IUser;

/**
 * Contract of CheckInService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CheckInServiceInterface {

	/**
	 * Check a learner in.
	 *
	 * @param string      $windowId   The CheckInWindow uuid.
	 * @param string      $code       The code the learner sent.
	 * @param string      $userId     The learner's Nextcloud user id.
	 * @param string|null $learnerRef The learner's profile uuid, when known.
	 * @param IUser|null  $runAs      The pupil's account for a portal check-in, null in the app.
	 *
	 * @return PortalOutcome 200 `{status, sessionId}`, or 404 / 403 / 409 / 422 with a reason.
	 *
	 * @spec openspec/specs/attendance/spec.md#scenario-a-learner-scans-the-code-at-the-start-of-the-lesson
	 * @spec openspec/specs/attendance/spec.md#requirement-a-self-check-in-never-overwrites-a-mark
	 */
	public function checkIn(string $windowId, string $code, string $userId, ?string $learnerRef = null, ?IUser $runAs = null): PortalOutcome;

	/**
	 * Check a learner in with only the code on the board: the window is the
	 * open one of the learner's own lessons whose current code matches. A
	 * learner who types the code therefore needs no link, and the portal
	 * needs no window id.
	 *
	 * @param string      $code       The code the learner typed.
	 * @param string      $userId     The learner's Nextcloud user id.
	 * @param string|null $learnerRef The learner's profile uuid, when known.
	 * @param IUser|null  $runAs      The pupil's account for a portal check-in, null in the app.
	 *
	 * @return PortalOutcome As checkIn(); 422 `invalid_code` when no open window of the learner matches.
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	public function checkInWithCode(string $code, string $userId, ?string $learnerRef = null, ?IUser $runAs = null): PortalOutcome;

	/**
	 * The open check-ins of the learner's own lessons, for the learner's
	 * check-in page: window id, lesson title and times. Never the code.
	 *
	 * @param string $userId The learner's Nextcloud user id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	public function openFor(string $userId): array;

	/**
	 * What the learner's check-in page shows: the lesson and whether the
	 * window is open. Only for a learner of the lesson's group.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 * @param string $userId   The learner's Nextcloud user id.
	 *
	 * @return PortalOutcome 200 `{title, startsAt, endsAt, open}`, or a refusal.
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	public function show(string $windowId, string $userId): PortalOutcome;
}//end interface
