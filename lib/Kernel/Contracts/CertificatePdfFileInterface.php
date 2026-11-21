<?php

/**
 * Contract of CertificatePdfFile
 *
 * The public surface of CertificatePdfFile that other components call, so they depend
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
 * Contract of CertificatePdfFile.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 */
interface CertificatePdfFileInterface {

	/**
	 * The PDF a certificate's `pdfDocumentRef` names, or null when the
	 * reference names no readable PDF. Never throws.
	 *
	 * @param string $documentRef The document reference filinq answered.
	 *
	 * @return string|null The PDF bytes.
	 *
	 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-only-the-holder-and-the-certificates-managers-download-the-pdf
	 */
	public function read(string $documentRef): ?string;
}//end interface
