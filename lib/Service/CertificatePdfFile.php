<?php

/**
 * Learniq Certificate PDF File
 *
 * Reads the PDF filinq rendered for a certificate. filinq's render endpoint
 * answers `documentRef`, the Nextcloud file id of the stored document; this
 * reads that file and nothing else: a reference that is not a file id, a
 * folder, or a file that is not a PDF reads as null.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-only-the-holder-and-the-certificates-managers-download-the-pdf
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\CertificatePdfFileInterface;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a rendered certificate PDF by its document reference.
 *
 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-only-the-holder-and-the-certificates-managers-download-the-pdf
 */
class CertificatePdfFile implements CertificatePdfFileInterface {

	/**
	 * The media type a certificate PDF has.
	 */
	public const MIME = 'application/pdf';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder     $rootFolder The Nextcloud file tree.
	 * @param LoggerInterface $logger     Logs a reference that could not be read.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The PDF the reference names, or null.
	 *
	 * @param string $documentRef The document reference filinq answered.
	 *
	 * @return string|null The PDF bytes.
	 *
	 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-only-the-holder-and-the-certificates-managers-download-the-pdf
	 */
	public function read(string $documentRef): ?string {
		$documentRef = trim($documentRef);
		if (preg_match('/^[1-9]\d*$/', $documentRef) !== 1) {
			return null;
		}

		try {
			$node = $this->rootFolder->getFirstNodeById((int)$documentRef);
			if ($node instanceof File === false || $node->getMimetype() !== self::MIME) {
				return null;
			}

			return $node->getContent();
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[CertificatePdfFile] Certificate PDF {ref} could not be read: {msg}',
				['ref' => $documentRef, 'msg' => $exception->getMessage()]
			);
			return null;
		}
	}//end read()
}//end class
