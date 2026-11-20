<?php

/**
 * Learniq ProofOfEnrolmentPdf
 *
 * Renders a proof of enrolment through the document render endpoint and keeps
 * the PDF on the enrolment. The render endpoint answers with the PDF itself,
 * or with JSON carrying it base64 encoded under `content`.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Kernel\AppIdentity;
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
 * Makes the PDF of a proof of enrolment and keeps it on the enrolment.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 */
class ProofOfEnrolmentPdf {

	/**
	 * The register the enrolments live in.
	 */
	private const REGISTER = 'learniq';

	/**
	 * The enrolment schema.
	 */
	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * The template filinq renders the proof with.
	 */
	public const TEMPLATE_SLUG = 'proof-of-enrolment';

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
	 * @param ObjectService   $objectService OpenRegister object access, to find the enrolment.
	 * @param FileService     $fileService   Keeps the PDF on the enrolment.
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
	 * Render the proof and keep it on the enrolment.
	 *
	 * @param string               $enrolmentId The enrolment's id.
	 * @param string               $fileName    The file's name: the proof's title.
	 * @param array<string, mixed> $data        What the template shows.
	 *
	 * @return bool Whether the proof was made and kept.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	public function make(string $enrolmentId, string $fileName, array $data): bool {
		$pdf = $this->render(data: $data);
		if ($pdf === null) {
			return false;
		}

		return $this->keep(enrolmentId: $enrolmentId, fileName: $fileName, pdf: $pdf);
	}//end make()

	/**
	 * The rendered PDF, or null when the render failed or answered no PDF.
	 *
	 * The render endpoint answers with the PDF itself, or with JSON carrying it base64 encoded under `content`.
	 *
	 * @param array<string, mixed> $data What the template shows.
	 *
	 * @return string|null
	 */
	private function render(array $data): ?string {
		$token = $this->appConfig->getValueString(app: AppIdentity::APP_ID, key: self::TOKEN_KEY, default: '');
		if ($token === '') {
			$this->logger->warning('Learniq: no render credential configured (learniq.docudesk_api_token); no proof of enrolment can be made.');
			return null;
		}

		$url = $this->urlGenerator->getAbsoluteURL('/index.php'.FleetAppId::path($this->appManager, 'filinq', self::RENDER_PATH));
		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'json' => ['templateSlug' => self::TEMPLATE_SLUG, 'output' => 'pdf', 'data' => $data],
					'timeout' => 60,
					'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/pdf, application/json'],
				]
			);
			$body = (string)$response->getBody();
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: a proof of enrolment could not be rendered', ['reason' => $e->getMessage()]);
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
	 * Keep the PDF on the enrolment, replacing an earlier one of the same school year.
	 *
	 * @param string $enrolmentId The enrolment's id.
	 * @param string $fileName    The file's name: the proof's title.
	 * @param string $pdf         The PDF bytes.
	 *
	 * @return bool Whether it was kept.
	 */
	private function keep(string $enrolmentId, string $fileName, string $pdf): bool {
		try {
			$enrolment = $this->objectService->find(
				id: $enrolmentId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::ENROLMENT_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
			if ($enrolment === null) {
				return false;
			}

			$this->fileService->saveFile(
				objectEntity: $enrolment,
				upload: new FileUpload(fileName: $fileName, content: $pdf, share: false, tags: [self::TEMPLATE_SLUG])
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: a proof of enrolment could not be kept', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end keep()
}//end class
