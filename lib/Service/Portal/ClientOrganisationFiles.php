<?php

/**
 * Learniq ClientOrganisationFiles
 *
 * Keeps a file on a company's own client-organisation row, where the
 * employer's portal lists it for her to download; a file of the same name
 * is replaced.
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

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps a file on a company's row.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
class ClientOrganisationFiles {

	private const REGISTER = 'learniq';

	private const SCHEMA = 'client-organisation';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Finds the company row.
	 * @param FileService     $fileService   Writes the file in its folder.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly FileService $fileService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Keep the bytes on the company's row; the same name replaces.
	 *
	 * @param string $organisationRef The company's client-organisation uuid.
	 * @param string $fileName        The file's name.
	 * @param string $content         The bytes.
	 * @param string $tag             The tag the file carries.
	 *
	 * @return int 201 kept, 404 no such company, 502 not kept.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
	 */
	public function keep(string $organisationRef, string $fileName, string $content, string $tag): int {
		try {
			$company = $this->objectService->find(
				id: $organisationRef,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
			if ($company === null) {
				return Http::STATUS_NOT_FOUND;
			}

			$this->fileService->saveFile(
				objectEntity: $company,
				upload: new FileUpload(fileName: $fileName, content: $content, share: false, tags: [$tag])
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: a file could not be kept on a company', ['reason' => $e->getMessage()]);
			return Http::STATUS_BAD_GATEWAY;
		}

		return Http::STATUS_CREATED;
	}//end keep()
}//end class
