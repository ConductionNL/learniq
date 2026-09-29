<?php

/**
 * Learniq Credential Europass Controller
 *
 * The Europass form of a credential (credentials-europass-edci-export): the
 * learner it belongs to, and staff who may read credentials (`hr`,
 * `compliance-officers`, admins), download it as a JSON-LD file; staff create
 * it once for a credential issued before this change. Both methods are
 * `#[NoAdminRequired]` with the check in the body; anyone else gets the same
 * 404 as a missing credential, so the route tells a stranger nothing.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-a-learner-downloads-their-certificate-for-europass
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\CredentialLearner;
use OCA\Learniq\Service\EuropassIssuer;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Europass download and backfill.
 *
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-a-learner-downloads-their-certificate-for-europass
 */
class CredentialEuropassController extends Controller {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'credential';

	/**
	 * Groups that read credentials and create their Europass form.
	 */
	private const STAFF_GROUPS = ['hr', 'compliance-officers'];

	/**
	 * Constructor.
	 *
	 * @param IRequest       $request      The request.
	 * @param IUserSession   $userSession  The signed-in user.
	 * @param IGroupManager  $groupManager Group membership and admin checks.
	 * @param ObjectService  $objects      OpenRegister object access.
	 * @param EuropassIssuer $europass     Builds and signs the Europass form.
	 * @param CredentialLearner $learners  Whose credential it is, as a Nextcloud user id.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ObjectService $objects,
		private readonly EuropassIssuer $europass,
		private readonly CredentialLearner $learners,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Download the Europass form as `europass-<course code>-<issued date>.jsonld`.
	 *
	 * @param string $id The credential uuid.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#scenario-a-learner-saves-a-certificate-to-their-europass-profile
	 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#scenario-another-learner-cannot-download-it
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(string $id): DataDownloadResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$credential = $this->read(schema: self::SCHEMA, id: $id);
		// Credential.learnerId is the LearnerProfile uuid, never the user id:
		// the learner is matched on learnerUserId, or through the profile.
		$mayRead = $credential !== null
			&& ($this->learners->belongsTo(credential: $credential, userId: $user->getUID()) === true
			|| $this->isStaff(userId: $user->getUID()) === true);
		if ($mayRead === false || is_array($credential['edciPayload'] ?? null) === false) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$course = $this->read(schema: 'course', id: (string)($credential['courseId'] ?? ''));
		$code = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($course['code'] ?? 'credential'));
		$date = substr((string)($credential['issuedAt'] ?? ''), 0, 10);
		$json = (string)json_encode($credential['edciPayload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

		return new DataDownloadResponse($json, 'europass-' . trim($code . '-' . $date, '-') . '.jsonld', 'application/ld+json');
	}//end download()

	/**
	 * Create the Europass form of an earlier credential, once. Staff only; a
	 * revoked credential is refused.
	 *
	 * @param string $id The credential uuid.
	 *
	 * @return JSONResponse 200 `{created: true}`, or 401 / 404 / 409 / 422.
	 *
	 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#scenario-an-hr-officer-backfills-an-old-certificate
	 */
	#[NoAdminRequired]
	public function create(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$credential = $this->read(schema: self::SCHEMA, id: $id);
		if ($credential === null || $this->isStaff(userId: $user->getUID()) === false) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if (($credential['lifecycle'] ?? 'issued') !== 'issued') {
			return new JSONResponse(data: ['error' => 'not_issued'], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if (is_array($credential['edciPayload'] ?? null) === true) {
			return new JSONResponse(data: ['error' => 'already_created'], statusCode: Http::STATUS_CONFLICT);
		}

		try {
			$payload = $this->europass->payloadFor(credential: $credential);
			if ($payload === null) {
				return new JSONResponse(data: ['error' => 'cannot_sign'], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$row = $credential;
			unset($row['@self']);
			$row['edciPayload'] = $payload;
			$this->objects->saveObject(object: $row, register: self::REGISTER, schema: self::SCHEMA, uuid: $id, _rbac: false);
		} catch (Throwable) {
			return new JSONResponse(data: ['error' => 'save_failed'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: ['created' => true]);
	}//end create()

	/**
	 * Whether the user is an admin or in a staff group.
	 *
	 * @param string $userId The user id.
	 *
	 * @return bool
	 */
	private function isStaff(string $userId): bool {
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach (self::STAFF_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isStaff()

	/**
	 * One learniq object by uuid as an array, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _render: false);
		} catch (DoesNotExistException) {
			return null;
		}

		$row = $object?->jsonSerialize();
		if (is_array($row) === false) {
			return null;
		}

		$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? $id));

		return $row;
	}//end read()
}//end class
