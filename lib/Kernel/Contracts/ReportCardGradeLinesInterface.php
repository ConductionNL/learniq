<?php

/**
 * Contract of ReportCardGradeLines
 *
 * The public surface of ReportCardGradeLines that other components call, so they depend
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
 * Contract of ReportCardGradeLines.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportCardGradeLinesInterface {

	/**
	 * The readable copies of a report card's period and subject grades.
	 *
	 * A subject is named by its course, else by its curriculum plan. A subject
	 * that has neither name is left out rather than shown as a code. A grade
	 * is written with one decimal and a decimal comma, the way a Dutch report
	 * card writes it; a subject without a grade shows its name alone.
	 *
	 * @param array<string, mixed> $reportCard The ReportCard as it will be saved.
	 *
	 * @return array{periodName: string|null, gradeLines: array<int, string>}
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-a-report-card-carries-its-subject-grades-in-readable-form
	 */
	public function derive(array $reportCard): array;
}//end interface
