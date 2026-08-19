<?php

/**
 * Scholiq Learning Record Share Verify Controller
 *
 * Public (unauthenticated) endpoint for verifying a `LearningRecordShare`'s
 * shared bundle. External employers, receiving-school admissions offices,
 * or anyone holding the share link call
 * `GET /api/learning-record-shares/{id}/verify` to see the shared,
 * cryptographically-verified bundle without a Nextcloud session — the same
 * public/unauthenticated, JWS-verifying, fail-closed pattern
 * `CredentialVerifyController` already establishes.
 *
 * Legitimate PHP per ADR-031 "External-system contract — public
 * verification surface that must bypass NC session middleware via
 * `@PublicPage` + `@NoCSRFRequired`."
 *
 * Those two are deliberately backticked and kept off the start of the line.
 * Nextcloud's ControllerMethodReflector matches `^\h+\*\h+@([A-Z]\w+)(.*)$`,
 * so an @-prefixed word sitting at docblock-tag position is read AS the
 * annotation even when the surrounding sentence is only describing it. A
 * sibling app shipped `* @NoCSRFRequired removed to close ...` in prose and
 * that sentence re-enabled the annotation on two admin POSTs.
 *
 * Read-only except for `lastAccessedAt`/`accessCount`, which this
 * controller stamps on every SUCCESSFUL verification — never on a denied
 * one, so a probing attempt against a revoked/expired/invalid share does
 * not pollute the learner's own "was this viewed" signal.
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
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\LearningRecordExportSigningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * Public verification endpoint for a LearningRecordShare's shared bundle.
 *
 * No session auth, no CSRF. Denies (no partial data) when revoked, expired,
 * or signature-invalid. On success returns only the bundle content.
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-3-2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One over the threshold since
 * IThrottler + LoggerInterface were injected to stop this endpoint being an
 * enumeration oracle over who has shared which learning records (ADR-082).
 * Every dependency is a distinct capability the handler needs — object read,
 * export signature verification, file access, brute-force accounting, logging.
 * Folding them behind a facade to satisfy the count would hide which of those
 * is the security boundary, and dropping the throttler would reopen the leak.
 */
class LearningRecordShareVerifyController extends Controller {

	/**
	 * Brute-force throttler action for failed share verifications.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'learniq_learning_record_share_verify';

	/**
	 * Record a failed verification with the brute-force throttler.
	 *
	 * The half that COUNTS; `#[BruteForceProtection]` on verify() is the half
	 * that ENFORCES. Either alone is inert -- see ADR-082.
	 *
	 * @return void
	 */
	private function registerFailedVerification(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (\Throwable $throttlerFailure) {
			$this->logger->warning(
				'LearningRecordShareVerifyController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}//end registerFailedVerification()


	private const SCHOLIQ_REGISTER = 'learniq';
	private const SHARE_SCHEMA = 'learning-record-share';
	private const EXPORT_SCHEMA = 'learning-record-export';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param ObjectService $objectService OR object read/update service.
	 * @param LearningRecordExportSigningService $signingService JWS verification.
	 * @param IRootFolder $rootFolder NC root folder for reading the bundle file.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly LearningRecordExportSigningService $signingService,
		private readonly IRootFolder $rootFolder,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Verify a LearningRecordShare by UUID without requiring authentication.
	 *
	 * @param string $id LearningRecordShare UUID.
	 *
	 * @return JSONResponse `{valid: true, bundle: {...}}` on success, `{valid: false, reason}` otherwise.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-valid-unexpired-share-resolves-to-the-shared-bundle
	 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-an-expired-share-is-denied-even-though-its-lifecycle-is-still-active
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function verify(string $id): JSONResponse {
		$share = $this->fetchObject(id: $id, schema: self::SHARE_SCHEMA);
		if ($share === null) {
			// A share UUID that resolves to nothing is a guess. Enumerating
			// these would leak which learners have shared which records.
			$this->registerFailedVerification();
			return new JSONResponse(['valid' => false, 'reason' => 'not_found'], 404);
		}

		$lifecycle = $share['lifecycle'] ?? '';
		if ($lifecycle === 'revoked') {
			return new JSONResponse(['valid' => false, 'reason' => 'revoked'], 200);
		}

		if (($share['isExpired'] ?? false) === true) {
			return new JSONResponse(['valid' => false, 'reason' => 'expired'], 200);
		}

		$exportId = $share['learningRecordExportId'] ?? '';
		$export = null;
		if ($exportId !== '') {
			$export = $this->fetchObject(id: (string)$exportId, schema: self::EXPORT_SCHEMA);
		}

		if ($export === null) {
			return new JSONResponse(['valid' => false, 'reason' => 'export_not_found'], 200);
		}

		$bundle = $this->readBundle(export: $export);
		if ($bundle === null) {
			return new JSONResponse(['valid' => false, 'reason' => 'bundle_unreadable'], 200);
		}

		$jws = (string)($export['bundleSignature'] ?? '');
		$tenantId = (string)($export['tenant_id'] ?? '');
		if ($jws === '' || $this->signingService->verify(jws: $jws, bundle: $bundle, tenantId: $tenantId) === false) {
			return new JSONResponse(['valid' => false, 'reason' => 'signature_invalid'], 200);
		}

		$this->stampAccess(share: $share);

		return new JSONResponse(['valid' => true, 'bundle' => $bundle], 200);
	}//end verify()

	/**
	 * Read and decode the signed bundle file referenced by a
	 * `LearningRecordExport.bundleRef`, owned by the export's `learnerId`.
	 *
	 * @param array<string,mixed> $export The LearningRecordExport data array.
	 *
	 * @return array<string,mixed>|null The decoded bundle, or null when unreadable.
	 */
	private function readBundle(array $export): ?array {
		$bundleRef = (string)($export['bundleRef'] ?? '');
		$ownerUid = (string)($export['learnerId'] ?? '');
		if ($bundleRef === '' || $ownerUid === '') {
			return null;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($ownerUid);
			$node = $userFolder->get(ltrim($bundleRef, '/'));
			if (($node instanceof File) === false) {
				return null;
			}

			$decoded = json_decode($node->getContent(), associative: true);

			if (is_array($decoded) === true) {
				return $decoded;
			}

			return null;
		} catch (\Throwable) {
			return null;
		}
	}//end readBundle()

	/**
	 * Stamp `lastAccessedAt`/`accessCount` on a successful verification.
	 *
	 * @param array<string,mixed> $share The LearningRecordShare data array (with its own `id`).
	 *
	 * @return void
	 */
	private function stampAccess(array $share): void {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
		$accessCount = (int)($share['accessCount'] ?? 0);

		$updated = $share;
		$updated['lastAccessedAt'] = $now;
		$updated['accessCount'] = $accessCount + 1;

		try {
			$this->objectService->saveObject(
				register: self::SCHOLIQ_REGISTER,
				schema: self::SHARE_SCHEMA,
				object: $updated
			);
		} catch (\Throwable) {
			// Best-effort — a failed access-stamp must never block a valid verification response.
		}
	}//end stampAccess()

	/**
	 * Fetch an object by id/schema, normalising the OpenRegister entity to an
	 * array — mirrors `LeaderboardController::fetchObject()`.
	 *
	 * @param string $id UUID of the object.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchObject(string $id, string $schema): ?array {
		// `find()` THROWS for a share id that does not resolve — DoesNotExistException
		// for an unknown object, and ObjectService::setSchema() rethrows the same for a
		// schema the register has not imported. This is a #[PublicPage], so letting that
		// escape means Nextcloud's dispatcher renders printExceptionErrorPage() and the
		// caller receives an HTML error page where it asked for JSON. The verify view then
		// fails with `SyntaxError: Unexpected token '<', "<!DOCTYPE "...`.
		//
		// An unresolvable share is exactly the denied case this endpoint is specified to
		// fail closed on, so it must return null and let the caller emit the denied JSON —
		// matching the `catch (\Throwable)` already used by the other methods on this class.
		try {
			$obj = $this->objectService->find(id: $id, register: self::SCHOLIQ_REGISTER, schema: $schema);
		} catch (\Throwable) {
			return null;
		}

		if ($obj === null) {
			return null;
		}

		return $obj->jsonSerialize();
	}//end fetchObject()
}//end class
