<?php

/**
 * Contract of ReportCardPdfDelegationService
 *
 * The public surface of ReportCardPdfDelegationService that other components call, so they depend
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
 * Contract of ReportCardPdfDelegationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportCardPdfDelegationServiceInterface {

	/**
	 * Render the ReportCard to PDF through docudesk and record the outcome.
	 *
	 * Never throws: on success sets `docudeskRenderStatus=rendered` and
	 * `docudeskDocumentRef`, on any failure `docudeskRenderStatus=failed` and
	 * `docudeskRenderError`; always stamps `docudeskRequestedAt`.
	 *
	 * @param array<string,mixed> $reportCard The ReportCard data array.
	 *
	 * @return array<string,mixed> The ReportCard with the render outcome applied.
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
	 * @spec openspec/specs/report-card/spec.md#scenario-a-successful-render-records-the-docudesk-document-reference
	 */
	public function render(array $reportCard): array;
}//end interface
