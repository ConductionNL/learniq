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
 * A valid certificate whose PDF is not rendered (yet) is left out and
 * counted, so the answer can say how many are missing; an expired, revoked
 * or replaced one, a proof of participation, or another company's
 * certificate never goes in.
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
use OCA\Learniq\Kernel\Contracts\CertificatePdfFileInterface;
use OCA\Learniq\Kernel\Contracts\EmployerCertificatesArchiveInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;
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

	private const REGISTER = 'learniq';

	private const CREDENTIAL_SCHEMA = 'credential';

	private const COMPANY_SCHEMA = 'client-organisation';

	private const LIMIT = 1000;

	/**
	 * Characters a file name inside the ZIP may not carry.
	 */
	private const UNSAFE = '#[\\\\/:*?"<>|\x00-\x1F]+#';

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService Reads the certificates and the company row.
	 * @param FileService                 $fileService   Keeps the ZIP on the company row.
	 * @param CertificatePdfFileInterface $pdfs          Reads a rendered certificate PDF.
	 * @param IFactory                    $l10nFactory   Names the file.
	 * @param ITimeFactory                $time          Today, for "not expired".
	 * @param LoggerInterface             $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly FileService $fileService,
		private readonly CertificatePdfFileInterface $pdfs,
		private readonly IFactory $l10nFactory,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
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
		$valid           = self::validCertificates(rows: $this->certificatesOf(organisationRef: $organisationRef), organisationRef: $organisationRef, today: $today);

		$files   = [];
		$missing = 0;
		foreach ($valid as $certificate) {
			$pdf = $this->pdfOf(certificate: $certificate);
			if ($pdf === null) {
				$missing++;
				continue;
			}

			$files[self::entryName(certificate: $certificate, taken: array_keys($files))] = $pdf;
		}

		if ($files === []) {
			return new PortalOutcome(
				status: Http::STATUS_CONFLICT,
				body: ['error' => 'no_valid_certificates', 'message' => 'There is no valid certificate to download yet.', 'missing' => $missing],
				reason: 'no-valid-certificates'
			);
		}

		$zip = self::zip(files: $files);
		if ($zip === null) {
			return self::failed();
		}

		$title = $this->l10nFactory->get(AppIdentity::APP_ID)->t('Valid certificates, %s', [$today]);
		$kept  = $this->keep(organisationRef: $organisationRef, fileName: $title.'.zip', zip: $zip);
		if ($kept !== Http::STATUS_CREATED) {
			if ($kept === Http::STATUS_NOT_FOUND) {
				return new PortalOutcome(status: Http::STATUS_NOT_FOUND, body: ['error' => 'unknown_company'], reason: 'unknown-company');
			}

			return self::failed();
		}

		return new PortalOutcome(
			status: Http::STATUS_CREATED,
			body: ['status' => 'created', 'title' => $title, 'count' => count($files), 'missing' => $missing]
		);
	}//end make()

	/**
	 * The certificates that go in: the company's own, issued, of kind
	 * certificate, and not expired today. Whatever the store answered is
	 * checked again here.
	 *
	 * @param array<int, mixed> $rows            The rows the store answered.
	 * @param string            $organisationRef The company.
	 * @param string            $today           Today as Y-m-d.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
	 */
	public static function validCertificates(array $rows, string $organisationRef, string $today): array {
		if ($organisationRef === '') {
			return [];
		}

		$valid = [];
		foreach ($rows as $row) {
			$row = self::toArray(row: $row);
			if ((string)($row['organisationRef'] ?? '') !== $organisationRef
				|| (string)($row['lifecycle'] ?? '') !== 'issued'
				|| (string)($row['kind'] ?? '') !== 'certificate'
				|| (string)($row['expiryStatus'] ?? '') === 'expired'
			) {
				continue;
			}

			$expiresAt = substr(trim((string)($row['expiresAt'] ?? '')), 0, 10);
			if ($expiresAt !== '' && $expiresAt < $today) {
				continue;
			}

			$valid[] = $row;
		}

		return $valid;
	}//end validCertificates()

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

		try {
			$archive = new ZipArchive();
			if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
				return null;
			}

			foreach ($files as $name => $bytes) {
				$archive->addFromString((string)$name, $bytes);
			}

			if ($archive->close() === false) {
				return null;
			}

			$zip = file_get_contents($path);
			if ($zip === false || $zip === '') {
				return null;
			}

			return $zip;
		} finally {
			@unlink($path);
		}
	}//end zip()

	/**
	 * The certificate's name inside the ZIP: holder and certificate, unique.
	 *
	 * @param array<string, mixed> $certificate The certificate row.
	 * @param array<int, string>   $taken       The names already used.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
	 */
	public static function entryName(array $certificate, array $taken): string {
		$parts = array_filter(
			[trim((string)($certificate['learnerName'] ?? '')), trim((string)($certificate['courseName'] ?? ''))],
			static fn (string $part): bool => $part !== ''
		);
		$base  = trim((string)preg_replace(self::UNSAFE, '-', implode(' - ', $parts)));
		if ($base === '') {
			$base = 'Certificate '.(string)($certificate['id'] ?? '');
		}

		$name = $base.'.pdf';
		for ($n = 2; in_array($name, $taken, true) === true; $n++) {
			$name = $base.' ('.$n.').pdf';
		}

		return $name;
	}//end entryName()

	/**
	 * The company's certificates as the store answers them.
	 *
	 * @param string $organisationRef The company.
	 *
	 * @return array<int, mixed>
	 */
	private function certificatesOf(string $organisationRef): array {
		if ($organisationRef === '') {
			return [];
		}

		try {
			return (array)$this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::CREDENTIAL_SCHEMA,
						'organisationRef' => $organisationRef,
						'lifecycle' => 'issued',
						'kind' => 'certificate',
					],
					'limit' => self::LIMIT,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: the certificates of a company could not be read', ['reason' => $e->getMessage()]);
			return [];
		}
	}//end certificatesOf()

	/**
	 * The rendered PDF of a certificate, or null.
	 *
	 * @param array<string, mixed> $certificate The certificate row.
	 *
	 * @return string|null
	 */
	private function pdfOf(array $certificate): ?string {
		$ref = trim((string)($certificate['pdfDocumentRef'] ?? ''));
		if ((string)($certificate['pdfRenderStatus'] ?? '') !== 'rendered' || $ref === '') {
			return null;
		}

		return $this->pdfs->read(documentRef: $ref);
	}//end pdfOf()

	/**
	 * Keep the ZIP on the company's row; same name replaces.
	 *
	 * @param string $organisationRef The company.
	 * @param string $fileName        The file's name.
	 * @param string $zip             The ZIP bytes.
	 *
	 * @return int 201 kept, 404 no such company, 502 not kept.
	 */
	private function keep(string $organisationRef, string $fileName, string $zip): int {
		try {
			$company = $this->objectService->find(
				id: $organisationRef,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::COMPANY_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
			if ($company === null) {
				return Http::STATUS_NOT_FOUND;
			}

			$this->fileService->saveFile(
				objectEntity: $company,
				upload: new FileUpload(fileName: $fileName, content: $zip, share: false, tags: [self::TAG])
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: the certificates of a company could not be kept', ['reason' => $e->getMessage()]);
			return Http::STATUS_BAD_GATEWAY;
		}

		return Http::STATUS_CREATED;
	}//end keep()

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

	/**
	 * A store row as an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private static function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;
	}//end toArray()
}//end class
