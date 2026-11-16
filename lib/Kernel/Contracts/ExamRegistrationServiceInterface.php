<?php

/**
 * Contract of ExamRegistrationService
 *
 * The public surface of ExamRegistrationService that other components call, so they depend
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
 * Contract of ExamRegistrationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExamRegistrationServiceInterface {

	/**
	 * Open a registration list for a course day's exam with a body.
	 *
	 * @param string       $cohortId       The course day.
	 * @param string       $bodyName       The examining body.
	 * @param string       $closesAt       The moment the list closes (ISO 8601).
	 * @param string|null  $examDate       The exam date (Y-m-d), when known.
	 * @param list<string> $requiredFields The fields the body requires, in its column order; [] for the default.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When the day is no course day, or OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function open(string $cohortId, string $bodyName, string $closesAt, ?string $examDate, array $requiredFields): array;

	/**
	 * The list, refreshed from the participants' profiles while it is open.
	 *
	 * @param string $id The list.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When the list does not exist or OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function show(string $id): array;

	/**
	 * Close the list, by hand or at its moment (`$userId` null).
	 *
	 * @param string      $id     The list.
	 * @param string|null $userId Who closes it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When the list is not open or OpenRegister cannot be written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function close(string $id, ?string $userId): array;

	/**
	 * Close every open list whose moment has come.
	 *
	 * @return int How many lists closed.
	 *
	 * @throws \Throwable When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function closeDue(): int;

	/**
	 * The CSV of a closed list, in the body's column order.
	 *
	 * @param string $id The list.
	 *
	 * @return array{filename: string, csv: string}
	 *
	 * @throws \Throwable When the list is still open or OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function export(string $id): array;

	/**
	 * Mark a closed list sent to the body.
	 *
	 * @param string $id     The list.
	 * @param string $userId Who sent it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When the list is not closed or OpenRegister cannot be written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function markSent(string $id, string $userId): array;

	/**
	 * Record the body's result for one participant.
	 *
	 * @param string $id          The list.
	 * @param string $enrolmentId The participant's enrolment.
	 * @param string $result      `pass`, `fail` or '' to clear it.
	 * @param string $userId      Who entered it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When the list is open, the result unknown or OpenRegister cannot be written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
	 */
	public function recordResult(string $id, string $enrolmentId, string $result, string $userId): array;

	/**
	 * The lists of a course day.
	 *
	 * @param string $cohortId The course day.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
	 */
	public function forCourseDay(string $cohortId): array;

	/**
	 * The lists still open, refreshed.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws \Throwable When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function openLists(): array;
}//end interface
