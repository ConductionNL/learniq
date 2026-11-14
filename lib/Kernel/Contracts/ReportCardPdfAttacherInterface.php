<?php

/**
 * Learniq Report Card PDF Attacher contract
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of ReportCardPdfAttacher.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportCardPdfAttacherInterface {

	/**
	 * Attach the rendered PDF of a report card to the card as an OpenRegister file.
	 *
	 * Never throws: returns the attached file's name, or null when nothing was attached.
	 *
	 * @param ObjectEntity $reportCard The report card, as saved after a successful render.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/report-card-pdf-to-guardians/specs/report-card/spec.md#requirement-the-rendered-pdf-is-a-file-on-the-report-card
	 */
	public function attach(ObjectEntity $reportCard): ?string;
}//end interface
