<?php

/**
 * Contract of ExamSittingOverview
 *
 * The public surface of ExamSittingOverview that other components call, so they depend
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
 * Contract of ExamSittingOverview.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExamSittingOverviewInterface {

	/**
	 * Invigilators available for the whole sitting and not already asked, or null.
	 *
	 * @param string $sittingId The ExamSitting uuid.
	 *
	 * @return array<int, string>|null
	 *
	 * @spec openspec/specs/exam-schedule/spec.md#requirement-invigilator-assignment
	 */
	public function availableInvigilators(string $sittingId): ?array;

	/**
	 * Invigilators with a pending or confirmed request for the sitting.
	 *
	 * @param string $sittingId The ExamSitting uuid.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/exam-schedule/spec.md#requirement-invigilator-assignment
	 */
	public function bookedInvigilators(string $sittingId): array;

	/**
	 * Invigilators whose availability in the sitting's test week covers the whole sitting.
	 *
	 * @param array<string, mixed> $sitting The sitting.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/exam-schedule/spec.md#requirement-invigilator-assignment
	 */
	public function coveringAvailability(array $sitting): array;

	/**
	 * The overview of one sitting, or null when it cannot be read.
	 *
	 * @param string $sittingId The ExamSitting uuid.
	 *
	 * @return array{sitting: string, accommodations: array<int, array<string, mixed>>, invigilators: array<string, mixed>}|null
	 *
	 * @spec openspec/specs/exam-schedule/spec.md#requirement-accommodations-in-the-schedule
	 */
	public function overview(string $sittingId): ?array;

	/**
	 * One sitting as an array, or null.
	 *
	 * @param string $sittingId The ExamSitting uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/exam-schedule/spec.md#requirement-exam-periods-and-sittings
	 */
	public function sitting(string $sittingId): ?array;
}//end interface
