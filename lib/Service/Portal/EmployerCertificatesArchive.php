<?php

/**
 * Learniq EmployerCertificatesArchive
 *
 * "Alle geldige certificaten downloaden" on the employer's certificates page
 * (warmtepompacademie/Documenten): one ZIP with the rendered PDF of every
 * certificate of her company's people that is issued and not expired. The
 * ZIP is kept on the company's own client-organisation row, so the portal
 * offers it through the same scoped file download as every other document;
 * a new request on the same day replaces that day's file.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\EmployerCertificatesArchiveInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\L10N\IFactory;
use ZipArchive;

/**
 * Bundles a company's valid certificates into one kept ZIP.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
class EmployerCertificatesArchive implements EmployerCertificatesArchiveInterface {

	/**
	 * The tag the kept ZIP carries.
	 */
	public const TAG = 'employer-certificates';

	/**
	 * Constructor.
	 *
	 * @param EmployerCertificateFiles $certificates The company's valid certificate PDFs.
	 * @param ClientOrganisationFiles  $companyFiles Keeps the ZIP on the company row.
	 * @param IFactory                 $l10nFactory  Names the file.
	 * @param ITimeFactory             $time         Today, for "not expired".
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EmployerCertificateFiles $certificates,
		private readonly ClientOrganisationFiles $companyFiles,
		private readonly IFactory $l10nFactory,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Bundle and keep the company's valid certificates.
	 *
	 * @param string $organisationRef The company's client-organisation uuid.
	 *
	 * @return PortalOutcome 201 `{status, title, count, missing}`, 409 without a valid rendered certificate, 404 / 502.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
	 */
	public function make(string $organisationRef): PortalOutcome {
		$organisationRef = trim($organisationRef);
		$today           = date('Y-m-d', $this->time->getTime());
		$found           = $this->certificates->forCompany(organisationRef: $organisationRef, today: $today);
		if ($found['files'] === []) {
			return new PortalOutcome(
				status: Http::STATUS_CONFLICT,
				body: ['error' => 'no_valid_certificates', 'message' => 'There is no valid certificate to download yet.', 'missing' => $found['missing']],
				reason: 'no-valid-certificates'
			);
		}

		$zip = self::zip(files: $found['files']);
		if ($zip === null) {
			return self::failed();
		}

		$title = $this->l10nFactory->get(AppIdentity::APP_ID)->t('Valid certificates, %s', [$today]);
		$kept  = $this->companyFiles->keep(organisationRef: $organisationRef, fileName: $title.'.zip', content: $zip, tag: self::TAG);
		if ($kept === Http::STATUS_NOT_FOUND) {
			return new PortalOutcome(status: Http::STATUS_NOT_FOUND, body: ['error' => 'unknown_company'], reason: 'unknown-company');
		}

		if ($kept !== Http::STATUS_CREATED) {
			return self::failed();
		}

		return new PortalOutcome(
			status: Http::STATUS_CREATED,
			body: ['status' => 'created', 'title' => $title, 'count' => count($found['files']), 'missing' => $found['missing']]
		);
	}//end make()

	/**
	 * A ZIP holding the given files, or null when it could not be written.
	 *
	 * @param array<string, string> $files Name inside the ZIP => bytes.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
	 */
	public static function zip(array $files): ?string {
		$path = tempnam(sys_get_temp_dir(), 'learniq-certificates-');
		if ($path === false) {
			return null;
		}

		$archive = new ZipArchive();
		$zip     = null;
		if ($archive->open($path, ZipArchive::OVERWRITE) === true) {
			foreach ($files as $name => $bytes) {
				$archive->addFromString((string)$name, $bytes);
			}

			$zip = self::readClosed(archive: $archive, path: $path);
		}

		if (file_exists($path) === true) {
			unlink($path);
		}

		return $zip;
	}//end zip()

	/**
	 * Close the archive and read what it wrote, or null.
	 *
	 * @param ZipArchive $archive The open archive.
	 * @param string     $path    Where it writes.
	 *
	 * @return string|null
	 */
	private static function readClosed(ZipArchive $archive, string $path): ?string {
		if ($archive->close() === false) {
			return null;
		}

		$zip = file_get_contents($path);
		if ($zip === false || $zip === '') {
			return null;
		}

		return $zip;
	}//end readClosed()

	/**
	 * The answer when the ZIP could not be made or kept.
	 *
	 * @return PortalOutcome
	 */
	private static function failed(): PortalOutcome {
		return new PortalOutcome(
			status: Http::STATUS_BAD_GATEWAY,
			body: ['error' => 'archive_failed', 'message' => 'The certificates could not be bundled just now. Try again later.'],
			reason: 'archive-failed'
		);
	}//end failed()
}//end class
