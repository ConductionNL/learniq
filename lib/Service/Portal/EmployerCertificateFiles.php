<?php

/**
 * Learniq EmployerCertificateFiles
 *
 * The rendered PDFs of a company's valid certificates, each named for the
 * ZIP: the company's own certificates that are issued, of kind certificate
 * and not expired today. A valid certificate whose PDF is not rendered (yet)
 * is counted as missing; an expired, revoked or replaced one, a proof of
 * participation, or another company's certificate is never read.
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

use OCA\Learniq\Kernel\Contracts\CertificatePdfFileInterface;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and names the PDFs of a company's valid certificates.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
class EmployerCertificateFiles {

	/**
	 * The store filter every read starts from; the company is added per read.
	 */
	private const ISSUED_CERTIFICATES = [
		'register' => 'learniq',
		'schema' => 'credential',
		'lifecycle' => 'issued',
		'kind' => 'certificate',
	];

	private const LIMIT = 1000;

	/**
	 * Characters a file name inside the ZIP may not carry.
	 */
	private const UNSAFE = '#[\\\\/:*?"<>|\x00-\x1F]+#';

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService Reads the certificates.
	 * @param CertificatePdfFileInterface $pdfs          Reads a rendered certificate PDF.
	 * @param LoggerInterface             $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CertificatePdfFileInterface $pdfs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The company's valid certificate PDFs by their name in the ZIP, and how
	 * many valid certificates have no rendered PDF.
	 *
	 * @param string $organisationRef The company's client-organisation uuid.
	 * @param string $today           Today as Y-m-d.
	 *
	 * @return array{files: array<string, string>, missing: int}
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
	 */
	public function forCompany(string $organisationRef, string $today): array {
		$files   = [];
		$missing = 0;
		$rows    = $this->certificatesOf(organisationRef: $organisationRef);
		foreach (self::validCertificates(rows: $rows, organisationRef: $organisationRef, today: $today) as $certificate) {
			$pdf = $this->pdfOf(certificate: $certificate);
			if ($pdf === null) {
				$missing++;
				continue;
			}

			$files[self::entryName(certificate: $certificate, taken: array_keys($files))] = $pdf;
		}

		return ['files' => $files, 'missing' => $missing];
	}//end forCompany()

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

		$reader = new SerialisedRow();
		$valid  = [];
		foreach ($rows as $row) {
			$row = $reader->asArray(object: $row);
			if (self::isValid(row: $row, organisationRef: $organisationRef, today: $today) === true) {
				$valid[] = $row;
			}
		}

		return $valid;
	}//end validCertificates()

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
		for ($copy = 2; in_array($name, $taken, true) === true; $copy++) {
			$name = $base.' ('.$copy.').pdf';
		}

		return $name;
	}//end entryName()

	/**
	 * Whether one certificate row goes in.
	 *
	 * @param array<string, mixed> $row             The certificate.
	 * @param string               $organisationRef The company.
	 * @param string               $today           Today as Y-m-d.
	 *
	 * @return bool
	 */
	private static function isValid(array $row, string $organisationRef, string $today): bool {
		if ((string)($row['organisationRef'] ?? '') !== $organisationRef
			|| (string)($row['lifecycle'] ?? '') !== 'issued'
			|| (string)($row['kind'] ?? '') !== 'certificate'
			|| (string)($row['expiryStatus'] ?? '') === 'expired'
		) {
			return false;
		}

		$expiresAt = substr(trim((string)($row['expiresAt'] ?? '')), 0, 10);

		return $expiresAt === '' || $expiresAt >= $today;
	}//end isValid()

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

		$filters = self::ISSUED_CERTIFICATES + ['organisationRef' => $organisationRef];
		try {
			return (array)$this->objectService->findAll(config: ['filters' => $filters, 'limit' => self::LIMIT], _rbac: false, _multitenancy: false);
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
}//end class
