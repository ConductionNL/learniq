<?php

/**
 * Contract of CertificatePdfDelegationService
 *
 * The public surface of CertificatePdfDelegationService that other components call, so they depend
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
 * Contract of CertificatePdfDelegationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CertificatePdfDelegationServiceInterface {

	/**
	 * Render a certificate's PDF through filinq on its course's template, and record the outcome.
	 *
	 * Never throws. A course without `certificateTemplateSlug` leaves the
	 * certificate as it is. Otherwise sets `pdfRenderStatus` to `rendered`
	 * with `pdfDocumentRef`, or to `unavailable` / `failed` with `pdfRenderError`.
	 *
	 * @param array<string,mixed> $credential The Credential data array.
	 * @param array<string,mixed> $course     The Course it certifies.
	 *
	 * @return array<string,mixed> The Credential with the render outcome applied.
	 *
	 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-issuing-a-certificate-renders-its-pdf-fail-soft
	 */
	public function render(array $credential, array $course): array;
}//end interface
