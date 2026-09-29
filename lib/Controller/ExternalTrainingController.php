<?php

/**
 * Learniq External Training Controller
 *
 * User-invokable actions for externally-completed training records:
 *   - bulkRecord: record one classroom session for many learners at once
 *     (one record per learner sharing a batchId), and
 *   - issueCredential: optionally issue a linked manual Credential on a
 *     verified record so the certification expiry machinery covers external
 *     certificates too.
 *
 * Both endpoints are authorized via the ADR-023 action matrix
 * (ActionAuthService::requireAction) — they are NOT plain `@NoAdminRequired`
 * pass-throughs. CRUD on the record itself goes directly through OpenRegister's
 * object API per ADR-022; this controller only owns the two multi-object
 * operations that the generic object API cannot express.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CredentialSigningService;
use OCA\Learniq\Service\ExternalTrainingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Multi-object operations for external-training records.
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */
class ExternalTrainingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param IUserSession $userSession Current user session.
	 * @param ActionAuthService $actionAuth ADR-023 action authorization.
	 * @param ExternalTrainingService $trainingService External-training business logic.
	 * @param ObjectService $objectService OR object query/persistence.
	 * @param CredentialSigningService $signingService Signs a credential before it is saved.
	 * @param CallerTenantResolver $callerTenant Resolves the caller's tenant.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ExternalTrainingService $trainingService,
		private readonly ObjectService $objectService,
		private readonly CredentialSigningService $signingService,
		private readonly CallerTenantResolver $callerTenant,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Bulk-record one external training for many learners.
	 *
	 * Authorized via the `external-training.bulk-record` action (officer / HR /
	 * admin). Returns the shared batchId and the number of records created.
	 *
	 * @param array<string> $learnerIds The learners attending the session.
	 * @param array<string,mixed> $training Shared training fields (title,
	 *                                      provider, kind, completedAt,
	 *                                      regulationSlug?, validUntil?,
	 *                                      evidenceNote?, tenant_id).
	 *
	 * @return JSONResponse The created batchId + count, or an error.
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 */
	#[NoAdminRequired]
	public function bulkRecord(array $learnerIds = [], array $training = []): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// ADR-023: throws OCSForbiddenException (HTTP 403) when not allowed.
		$this->actionAuth->requireAction(user: $user, action: 'external-training.bulk-record');

		if (empty($learnerIds) === true) {
			return new JSONResponse(data: ['error' => 'learnerIds is required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		// The submitter is always the authenticated actor — never client-supplied.
		$training['submittedBy'] = $user->getUID();

		$batchId = $this->trainingService->bulkRecord(learnerIds: $learnerIds, shared: $training);

		if ($batchId === '') {
			return new JSONResponse(
				data: ['error' => 'Missing required training fields (title, provider, completedAt, tenant_id)'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: ['batchId' => $batchId, 'count' => count(array_unique($learnerIds))],
			statusCode: Http::STATUS_CREATED
		);
	}//end bulkRecord()

	/**
	 * Issue a linked manual Credential for a verified external-training record.
	 *
	 * Authorized via the `external-training.issue-credential` action. The record
	 * MUST be `verified`; the credential is created via OR's existing
	 * `source: manual` path with `expiresAt = validUntil`, and its UUID is
	 * written back onto the record as `credentialId`.
	 *
	 * @param string $recordId UUID of the verified external-training record.
	 *
	 * @return JSONResponse The new credentialId, or an error.
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function issueCredential(string $recordId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'external-training.issue-credential');

		if ($recordId === '') {
			return new JSONResponse(data: ['error' => 'recordId is required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		// An unknown id and another tenant's record both read as absent, before
		// any credential is built (ObjectService::find() throws for an unknown id;
		// the resolver turns that into null too).
		$record = $this->callerTenant->findOwned(user: $user, id: $recordId, schema: 'external-training-record');
		if ($record === null) {
			return new JSONResponse(data: ['error' => 'Record not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if (($record['lifecycle'] ?? '') !== 'verified') {
			return new JSONResponse(
				data: ['error' => 'Credential can only be issued for a verified record'],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		if (($record['credentialId'] ?? '') !== '') {
			// Idempotent: a credential already exists for this record.
			return new JSONResponse(data: ['credentialId' => $record['credentialId']], statusCode: Http::STATUS_OK);
		}

		$payload = $this->trainingService->buildManualCredentialPayload(record: $record, issuedBy: $user->getUID());

		$credentialId = $this->saveSignedCredential(payload: $payload, record: $record);
		if ($credentialId === null) {
			return new JSONResponse(
				data: ['error' => 'The credential could not be signed. Generate the credential signing key in the Learniq admin settings first.'],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(data: ['credentialId' => $credentialId], statusCode: Http::STATUS_CREATED);
	}//end issueCredential()

	/**
	 * Sign a manual credential, save it, and link it back onto its record.
	 *
	 * Signs before the save: OR runs no lifecycle guard or action on a create
	 * (learniq#182), and the signed fields are required. `lifecycle` is left to
	 * OR's declared initial `issued`, as in CredentialIssuanceHandler. Nothing is
	 * saved when the credential cannot be signed.
	 *
	 * @param array<string, mixed> $payload The unsigned credential payload.
	 * @param array<string, mixed> $record  The verified external-training record.
	 *
	 * @return string|null The saved credential's id ('' when OR returned none), or null when unsigned.
	 *
	 * @throws \Exception When OpenRegister refuses the credential or the record save; it reaches the caller as before.
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 */
	private function saveSignedCredential(array $payload, array $record): ?string {
		$signed = $this->signingService->sign(credential: $payload);
		if ($signed === null) {
			return null;
		}

		$signedId = (string)$signed['id'];
		unset($signed['id']);

		$saved = $this->objectService->saveObject(
			register: 'learniq',
			schema: 'credential',
			object: $signed,
			uuid: $signedId
		);

		$savedArr = $saved->jsonSerialize();
		$credentialId = (string)($savedArr['id'] ?? ($savedArr['uuid'] ?? ''));

		// Write the credentialId back onto the record so the link is queryable.
		if ($credentialId !== '') {
			$record['credentialId'] = $credentialId;
			$this->objectService->saveObject(
				register: 'learniq',
				schema: 'external-training-record',
				object: $record
			);
		}

		return $credentialId;
	}//end saveSignedCredential()

	/**
	 * Report whether a learner is covered for a regulation, and by which class.
	 *
	 * Powers the coverage view's per-learner evidence-class column: coverage
	 * holds when the learner has a signed Attestation, a valid Credential, or a
	 * verified unexpired ExternalTrainingRecord for the regulation. Authorized
	 * via the same officer/HR/admin action as bulk-record; a learner querying
	 * their own coverage is allowed because the action matrix admits their
	 * group, and the read itself is scoped to the (learnerId, regulationSlug)
	 * pair supplied. A learner outside the caller's tenant, or an unknown one,
	 * reads as not covered, so the endpoint discloses nothing across tenants.
	 *
	 * @param string $learnerId LearnerProfile UUID.
	 * @param string $regulationSlug Regulation slug.
	 *
	 * @return JSONResponse { covered: bool, evidenceClass: string|null }.
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function learnerCoverage(string $learnerId = '', string $regulationSlug = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'external-training.bulk-record');

		if ($learnerId === '' || $regulationSlug === '') {
			return new JSONResponse(
				data: ['error' => 'learnerId and regulationSlug are required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if ($this->callerTenant->findOwned(user: $user, id: $learnerId, schema: 'learner-profile') === null) {
			return new JSONResponse(data: ['covered' => false, 'evidenceClass' => null]);
		}

		$evidenceClass = $this->trainingService->coveringEvidenceClass(
			learnerId: $learnerId,
			regulationSlug: $regulationSlug
		);

		return new JSONResponse(
			data: [
				'covered' => $this->trainingService->isLearnerCovered(learnerId: $learnerId, regulationSlug: $regulationSlug),
				'evidenceClass' => $evidenceClass,
			]
		);
	}//end learnerCoverage()
}//end class
