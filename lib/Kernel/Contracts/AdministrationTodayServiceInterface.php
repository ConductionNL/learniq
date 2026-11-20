<?php

/**
 * Contract of AdministrationTodayService
 *
 * The public surface of AdministrationTodayService that other components call, so they depend
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
 * Contract of AdministrationTodayService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdministrationTodayServiceInterface {

	/**
	 * The administration's Today: the exam registration asking for attention
	 * first, the course days to issue certificates for, the certificates
	 * expiring before 1 January and the coming exams.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function today(): array;

	/**
	 * The issued certificates expiring before a date, soonest first, with the renewal booking if any.
	 *
	 * @param string $before The date (Y-m-d).
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws \Throwable When the date cannot be read or OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/tasks.md
	 */
	public function expiring(string $before): array;
}//end interface
