<?php

/**
 * Learniq cmi5 Launch Controller
 *
 * Starts a cmi5 AU launch for the signed-in learner and serves the cmi5 fetch
 * URL the AU redeems for its auth token (cmi5 §8.2: the token never travels in
 * the launch URL itself).
 *
 * `POST /api/lessons/{lessonId}/cmi5-launch` answers 503 while no launch key is
 * provisioned (the lesson player shows its "not available yet" state), 404 when
 * the caller cannot read the lesson, and otherwise the five launch parameters
 * `{endpoint, fetchUrl, actor, activityId, registration}` that
 * `src/utils/cmi5Launch.js` appends to the AU URL.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#5-launch-endpoint-wiring
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Cmi5LaunchTokenService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Throwable;

/**
 * cmi5 launch start and fetch-URL token redemption.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class Cmi5LaunchController extends Controller {

	/**
	 * Seconds a fetch code stays redeemable.
	 *
	 * @var int
	 */
	private const FETCH_TTL_SECONDS = 300;

	/**
	 * The cache the fetch codes live in.
	 *
	 * @var ICache
	 */
	private readonly ICache $fetchCodes;

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request       The current request.
	 * @param IUserSession           $userSession   The signed-in learner.
	 * @param ObjectService          $objectService Reads the lesson with the caller's rights.
	 * @param Cmi5LaunchTokenService $tokens        Mints the launch token.
	 * @param ICacheFactory          $cacheFactory  Holds the one-time fetch codes.
	 * @param ISecureRandom          $secureRandom  Generates fetch codes.
	 * @param IURLGenerator          $urlGenerator  Builds the absolute endpoint URLs.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly Cmi5LaunchTokenService $tokens,
		ICacheFactory $cacheFactory,
		private readonly ISecureRandom $secureRandom,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->fetchCodes = $cacheFactory->createDistributed('learniq-cmi5-fetch');
	}//end __construct()

	/**
	 * Start a cmi5 launch of a lesson for the signed-in learner.
	 *
	 * @param string $lessonId The lesson UUID.
	 *
	 * @return JSONResponse The launch parameters, or 401/404/503.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#5-launch-endpoint-wiring
	 */
	#[NoAdminRequired]
	public function launch(string $lessonId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->tokens->isEnabled() === false) {
			return new JSONResponse(
				data: ['error' => 'cmi5_not_available', 'message' => 'cmi5 playback is not set up on this instance yet.'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		// Read with the caller's own rights: a lesson they cannot open does
		// not get a launch, and its existence is not disclosed.
		$lesson = $this->findLesson(lessonId: $lessonId);
		if ($lesson === null) {
			return new JSONResponse(data: ['error' => 'Lesson not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$uid          = $user->getUID();
		$registration = $this->uuid();
		$activityId   = $this->urlGenerator->getAbsoluteURL('/apps/learniq/lessons/' . $lessonId);
		$token        = $this->tokens->mintLaunchToken(
			learnerId: $uid,
			lessonId: $lessonId,
			registrationId: $registration,
			activityId: $activityId
		);

		$code = $this->secureRandom->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->fetchCodes->set($code, $token, self::FETCH_TTL_SECONDS);

		return new JSONResponse(
			data: [
				'endpoint'     => $this->urlGenerator->getAbsoluteURL('/apps/learniq/api/lrs/'),
				'fetchUrl'     => $this->urlGenerator->getAbsoluteURL('/apps/learniq/api/cmi5/fetch/' . $code),
				'actor'        => [
					'objectType' => 'Agent',
					'account'    => ['homePage' => $this->urlGenerator->getAbsoluteURL('/'), 'name' => $uid],
				],
				'activityId'   => $activityId,
				'registration' => $registration,
			]
		);
	}//end launch()

	/**
	 * Redeem a fetch code for the AU's auth token, once.
	 *
	 * Public because the AU calls it without a session; the unguessable,
	 * single-use, five-minute code is the credential.
	 *
	 * @param string $code The fetch code from the launch URL.
	 *
	 * @return JSONResponse `{"auth-token": ...}` or a cmi5 error body.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#5-launch-endpoint-wiring
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function fetch(string $code): JSONResponse {
		$token = $this->fetchCodes->get($code);
		if (is_string($token) === false || $token === '') {
			return new JSONResponse(
				data: ['error-code' => '1', 'error-text' => 'This fetch URL was already used or has expired'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$this->fetchCodes->remove($code);

		return new JSONResponse(data: ['auth-token' => $this->tokens->authToken(launchToken: $token)]);
	}//end fetch()

	/**
	 * Read a lesson with the caller's RBAC, or null.
	 *
	 * @param string $lessonId The lesson UUID.
	 *
	 * @return array<string, mixed>|null The lesson, or null when absent or not readable.
	 */
	private function findLesson(string $lessonId): ?array {
		try {
			$lesson = $this->objectService->find(id: $lessonId, register: 'learniq', schema: 'lesson');
		} catch (Throwable $e) {
			return null;
		}

		if ($lesson === null) {
			return null;
		}

		return $lesson->jsonSerialize();
	}//end findLesson()

	/**
	 * A fresh v4 UUID for the launch registration.
	 *
	 * @return string The UUID.
	 */
	private function uuid(): string {
		$bytes    = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end uuid()
}//end class
