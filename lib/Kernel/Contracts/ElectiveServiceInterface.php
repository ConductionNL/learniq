<?php

/**
 * Contract of ElectiveService
 *
 * The public surface of ElectiveService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of ElectiveService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ElectiveServiceInterface {

	public const REGISTER = 'learniq';

	public const SIGN_UP_SCHEMA = 'elective-sign-up';

	public const WITHDRAWN = 'withdrawn';

	/**
	 * The non-withdrawn sign-ups of an offer, optionally for one lesson.
	 *
	 * @param string      $offerId The offer.
	 * @param string|null $key     The lesson key, or null for all lessons.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function activeSignUps(string $offerId, ?string $key=null): array;

	/**
	 * Who is writing.
	 *
	 * @param string             $uid    The writer's user id.
	 * @param array<int, string> $groups The writer's groups.
	 *
	 * @return array{uid: string, admin: bool, staff: bool, integration: bool, via: string}
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-another-system-signs-learners-up-through-the-api
	 */
	public function caller(string $uid, array $groups): array;

	/**
	 * The learners of the offer's eligible groups; empty means every learner.
	 *
	 * @param array<string, mixed> $offer The offer.
	 *
	 * @return array<int, string>|null The learner ids, or null when the offer is open to everyone.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function eligibleLearners(array $offer): ?array;

	/**
	 * Whether the window is open now; an unset edge does not close it.
	 *
	 * @param array{opensAt: ?DateTimeImmutable, closesAt: ?DateTimeImmutable} $window The window.
	 * @param DateTimeImmutable                                                  $now    Now.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	public function isOpen(array $window, DateTimeImmutable $now): bool;

	/**
	 * The key of the lesson a sign-up or request names.
	 *
	 * @param array<string, mixed> $row A sign-up, or a request body.
	 *
	 * @return string The session uuid, `sourceSystem:externalRef`, or ''.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function lessonKey(array $row): string;

	/**
	 * The offer's lessons by key, with their times.
	 *
	 * @param array<string, mixed> $offer The offer.
	 *
	 * @return array<string, array{key: string, sessionId: ?string, timetableSessionRef: ?array, title: string, startsAt: string, endsAt: string}>
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-school-offers-optional-lessons-with-a-window-and-a-capacity
	 */
	public function lessons(array $offer): array;

	/**
	 * One offer, or null.
	 *
	 * @param string $offerId The offer's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-school-offers-optional-lessons-with-a-window-and-a-capacity
	 */
	public function offer(string $offerId): ?array;

	/**
	 * One sign-up, or null.
	 *
	 * @param string $signUpId The sign-up's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	public function signUp(string $signUpId): ?array;

	/**
	 * When sign-up for a lesson opens and closes.
	 *
	 * @param array<string, mixed> $offer    The offer.
	 * @param string               $startsAt The lesson's start.
	 *
	 * @return array{opensAt: ?DateTimeImmutable, closesAt: ?DateTimeImmutable}
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-school-offers-optional-lessons-with-a-window-and-a-capacity
	 */
	public function window(array $offer, string $startsAt): array;
}//end interface
