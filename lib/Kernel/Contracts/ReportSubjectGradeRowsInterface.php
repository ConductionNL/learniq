<?php

/**
 * Contract of ReportSubjectGradeRows
 *
 * The public surface of ReportSubjectGradeRows that other components call, so they depend
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
 * Contract of ReportSubjectGradeRows.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportSubjectGradeRowsInterface {

	/**
	 * Replace the pupil's rows with those of this newly published card.
	 *
	 * The rows of the pupil's older cards go first, so the portal always
	 * shows the latest report. A card without a pupil or an id writes nothing.
	 *
	 * @param array<string, mixed> $card The report card that was published.
	 *
	 * @return int The rows written.
	 *
	 * @throws \Throwable When OpenRegister refuses a read or a write.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-latest-report-reads-as-one-bar-per-subject
	 */
	public function replace(array $card): int;
}//end interface
