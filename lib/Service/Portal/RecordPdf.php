<?php

/**
 * Learniq RecordPdf
 *
 * Renders one document through the document render endpoint and keeps the PDF
 * on the learniq record it belongs to: a proof of enrolment on the enrolment,
 * a guardian's answer to a permission request on that answer. The render
 * endpoint answers with the PDF itself, or with JSON carrying it base64
 * encoded under `content`.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Support\FleetAppId;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes the PDF of one document and keeps it on its learniq record.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */
class RecordPdf {

	/**
	 * The register the records live in.
	 */
	private const REGISTER = 'learniq';

	/**
	 * The app whose config holds the render credential.
	 */
	private const APP_ID = 'learniq';

	/**
	 * filinq's render endpoint, after the app segment (the report card's).
	 */
	private const RENDER_PATH = 'api/v1/documents/render';

	/**
	 * The app-config key of the render credential, shared with the report card.
	 */
	private const TOKEN_KEY = 'docudesk_api_token';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object access, to find the record.
	 * @param FileService     $fileService   Keeps the PDF on the record.
	 * @param IClientService  $clientService HTTP client for the render endpoint.
	 * @param IURLGenerator   $urlGenerator  Absolute URL of the render endpoint.
	 * @param IAppManager     $appManager    Where the render app is installed.
	 * @param IAppConfig      $appConfig     The render credential.
	 * @param LoggerInterface $logger        PSR logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly FileService $fileService,
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The document to render and the record to keep it on.
	 *
	 * @param string $templateSlug The render template's slug.
	 * @param string $schema       The learniq schema of the record.
	 * @param string $recordId     The record's uuid.
	 * @param string $fileName     The file's name on the record.
	 *
	 * @return PdfTarget
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	public function target(string $templateSlug, string $schema, string $recordId, string $fileName): PdfTarget {
		return new PdfTarget(templateSlug: $templateSlug, schema: $schema, recordId: $recordId, fileName: $fileName);
	}//end target()

	/**
	 * Render the document and keep it on its record.
	 *
	 * @param PdfTarget            $target The template, the record and the file's name.
	 * @param array<string, mixed> $data   What the template shows.
	 *
	 * @return bool Whether the document was made and kept.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	public function make(PdfTarget $target, array $data): bool {
		$pdf = $this->render(templateSlug: $target->templateSlug, data: $data);
		if ($pdf === null) {
			return false;
		}

		return $this->keep(target: $target, pdf: $pdf);
	}//end make()

	/**
	 * The rendered PDF, or null when the render failed or answered no PDF.
	 *
	 * The render endpoint answers with the PDF itself, or with JSON carrying it base64 encoded under `content`.
	 *
	 * @param string               $templateSlug The render template's slug.
	 * @param array<string, mixed> $data         What the template shows.
	 *
	 * @return string|null
	 */
	private function render(string $templateSlug, array $data): ?string {
		$token = $this->appConfig->getValueString(app: self::APP_ID, key: self::TOKEN_KEY, default: '');
		if ($token === '') {
			$this->logger->warning(
				'Learniq: no render credential configured (learniq.docudesk_api_token); no document can be made.',
				['template' => $templateSlug]
			);
			return null;
		}

		$url = $this->urlGenerator->getAbsoluteURL('/index.php'.FleetAppId::path($this->appManager, 'filinq', self::RENDER_PATH));
		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'json' => ['templateSlug' => $templateSlug, 'output' => 'pdf', 'data' => $data],
					'timeout' => 60,
					'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/pdf, application/json'],
				]
			);
			$body = (string)$response->getBody();
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: a document could not be rendered', ['template' => $templateSlug, 'reason' => $e->getMessage()]);
			return null;
		}

		return self::pdfFrom(body: $body);
	}//end render()

	/**
	 * The PDF in a render answer: the body itself, or JSON's base64 `content`.
	 *
	 * @param string $body The answer's body.
	 *
	 * @return string|null The PDF bytes, or null when the answer holds none.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	public static function pdfFrom(string $body): ?string {
		if (str_starts_with($body, '%PDF-') === true) {
			return $body;
		}

		$json = json_decode($body, true);
		if (is_array($json) === false || is_string($json['content'] ?? null) === false) {
			return null;
		}

		$content = $json['content'];
		if ($content === '') {
			return null;
		}

		$pdf = base64_decode($content, true);
		if ($pdf === false || str_starts_with($pdf, '%PDF-') === false) {
			return null;
		}

		return $pdf;
	}//end pdfFrom()

	/**
	 * Keep the PDF on its record; a file of the same name is replaced.
	 *
	 * @param PdfTarget $target The record and the file's name.
	 * @param string    $pdf    The PDF bytes.
	 *
	 * @return bool Whether it was kept.
	 */
	private function keep(PdfTarget $target, string $pdf): bool {
		try {
			$record = $this->objectService->find(
				id: $target->recordId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: $target->schema),
				_rbac: false,
				_multitenancy: false
			);
			if ($record === null) {
				return false;
			}

			$this->fileService->saveFile(
				objectEntity: $record,
				upload: new FileUpload(fileName: $target->fileName, content: $pdf, share: false, tags: [$target->templateSlug])
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: a rendered document could not be kept', ['template' => $target->templateSlug, 'reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end keep()
}//end class
