<?php

/**
 * Learniq Credential Controller
 *
 * The rendered PDF of a certificate (credentials-certificate-pdf): the holder,
 * `hr` and `compliance-officers` download it once it is rendered; anyone else
 * gets the same 404 as a missing certificate, so the route tells a stranger
 * nothing. The method is `#[NoAdminRequired]` with the object check in the
 * body.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-only-the-holder-and-the-certificates-managers-download-the-pdf
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\CertificatePdfFileInterface;
use OCA\Learniq\Kernel\Contracts\CredentialLearnerInterface;
use OCA\Learniq\Support\FindOrNull;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Credential routes for the certificate PDF.
 *
 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-only-the-holder-and-the-certificates-managers-download-the-pdf
 */
class CredentialController extends Controller {
	use FindOrNull;

	private const REGISTER = 'learniq';
	private const SCHEMA = 'credential';

	/**
	 * The groups that manage certificates and may download any certificate's PDF.
	 */
	private const MANAGER_GROUPS = ['hr', 'compliance-officers'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request      The request.
	 * @param IUserSession                $userSession  The signed-in user.
	 * @param IGroupManager               $groupManager Answers group membership.
	 * @param ObjectService               $objects      OpenRegister object access.
	 * @param CredentialLearnerInterface  $learners     Tells whether a credential is the user's own.
	 * @param CertificatePdfFileInterface $pdfFiles     Reads the rendered PDF.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ObjectService $objects,
		private readonly CredentialLearnerInterface $learners,
		private readonly CertificatePdfFileInterface $pdfFiles,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The certificate's rendered PDF, for its holder, hr and compliance officers.
	 *
	 * @param string $id The credential uuid.
	 *
	 * @return DataDownloadResponse|JSONResponse The PDF, 401 without a user, or 404.
	 *
	 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-only-the-holder-and-the-certificates-managers-download-the-pdf
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pdf(string $id): DataDownloadResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$credential = $this->read(schema: self::SCHEMA, id: $id);
		if ($credential === null || $this->mayDownload(credential: $credential, userId: $user->getUID()) === false) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$ref = (string)($credential['pdfDocumentRef'] ?? '');
		if (($credential['pdfRenderStatus'] ?? null) !== 'rendered' || $ref === '') {
			return new JSONResponse(data: ['error' => 'pdf_not_rendered'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$pdf = $this->pdfFiles->read(documentRef: $ref);
		if ($pdf === null) {
			return new JSONResponse(data: ['error' => 'pdf_missing'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new DataDownloadResponse($pdf, $this->fileName(credential: $credential), 'application/pdf');
	}//end pdf()

	/**
	 * Whether the user holds the credential or is in a managing group.
	 *
	 * @param array<string, mixed> $credential The credential.
	 * @param string               $userId     The user.
	 *
	 * @return bool
	 */
	private function mayDownload(array $credential, string $userId): bool {
		if ($this->learners->belongsTo(credential: $credential, userId: $userId) === true) {
			return true;
		}

		foreach (self::MANAGER_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end mayDownload()

	/**
	 * The download's file name: `certificaat-<course code>-<issue date>.pdf`.
	 *
	 * @param array<string, mixed> $credential The credential.
	 *
	 * @return string
	 */
	private function fileName(array $credential): string {
		$course = $this->read(schema: 'course', id: (string)($credential['courseId'] ?? ''));
		$code   = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($course['code'] ?? ''));
		$date   = substr((string)($credential['issuedAt'] ?? ''), 0, 10);

		return 'certificaat-' . trim($code . '-' . $date, '-') . '.pdf';
	}//end fileName()

	/**
	 * One row of the learniq register, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The row id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$find = fn () => $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _render: false);
		return $this->findRowWithIdOrNull(find: $find, id: $id);
	}//end read()
}//end class
