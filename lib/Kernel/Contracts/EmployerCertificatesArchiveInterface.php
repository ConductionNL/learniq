<?php

/**
 * Learniq Employer Certificates Archive contract
 *
 * An employer asks the portal for one file with the certificates of her
 * people that are issued and not expired; it is kept with her company's
 * documents for her to download.
 *
 * @category Contract
 * @package  OCA\Learniq\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Bundles and keeps a company's valid certificates.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
interface EmployerCertificatesArchiveInterface {

	/**
	 * Bundle the rendered PDFs of every issued, unexpired certificate of the
	 * company's people into one ZIP and keep it on the company's row.
	 *
	 * @param string $organisationRef The company's client-organisation uuid, as the portal proved it.
	 *
	 * @return PortalOutcome 201 with the file's title and count, or a refusal with its reason.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
	 */
	public function make(string $organisationRef): PortalOutcome;
}//end interface
