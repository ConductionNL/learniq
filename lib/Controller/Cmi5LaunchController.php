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
use OCA\Learniq\Service\XapiDocumentStore;
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
use Psr\Log\LoggerInterface;
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
	 * The stateId of the launch data document (cmi5 section 10.2.1).
	 *
	 * @var string
	 */
	public const LAUNCH_DATA_STATE_ID = 'LMS.LaunchData';

	/**
	 * The cmi5 session id context extension.
	 *
	 * @var string
	 */
	private const SESSION_ID_EXTENSION = 'https://w3id.org/xapi/cmi5/context/extensions/sessionid';

	/**
	 * The moveOn values cmi5 section 13.1.4 allows.
	 *
	 * @var array<int, string>
	 */
	private const MOVE_ON = ['Passed', 'Completed', 'CompletedAndPassed', 'CompletedOrPassed', 'NotApplicable'];

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
	 * @param XapiDocumentStore      $documents     Stores the LMS.LaunchData state document.
	 * @param LoggerInterface        $logger        PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly Cmi5LaunchTokenService $tokens,
		ICacheFactory $cacheFactory,
		private readonly ISecureRandom $secureRandom,
		private readonly IURLGenerator $urlGenerator,
		private readonly XapiDocumentStore $documents,
		private readonly LoggerInterface $logger,
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

		$actor = [
			'objectType' => 'Agent',
			'account'    => ['homePage' => $this->urlGenerator->getAbsoluteURL('/'), 'name' => $uid],
		];

		// cmi5 section 10.2.1: the LMS writes LMS.LaunchData before the AU launches,
		// and an AU that cannot read it must abort. No fetch code without it.
		try {
			$this->documents->put(
				key: $this->documents->key(
					kind: XapiDocumentStore::KIND_STATE,
					actorId: $uid,
					activityId: $activityId,
					registration: $registration,
					documentId: self::LAUNCH_DATA_STATE_ID
				),
				contents: (string)json_encode($this->launchData(lesson: $lesson, lessonId: $lessonId, activityId: $activityId), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				contentType: 'application/json',
				context: ['agent' => $actor, 'lessonId' => $lessonId]
			);
		} catch (Throwable $e) {
			$this->logger->error('[Cmi5LaunchController] writing LMS.LaunchData failed: {msg}', ['msg' => $e->getMessage()]);
			return new JSONResponse(
				data: ['error' => 'cmi5_launch_data_failed', 'message' => 'The lesson could not be prepared for launch. Please try again later.'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$code = $this->secureRandom->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->fetchCodes->set($code, $token, self::FETCH_TTL_SECONDS);

		return new JSONResponse(
			data: [
				'endpoint'     => $this->urlGenerator->getAbsoluteURL('/apps/learniq/api/lrs/'),
				'fetchUrl'     => $this->urlGenerator->getAbsoluteURL('/apps/learniq/api/cmi5/fetch/' . $code),
				'actor'        => $actor,
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
	 * The LMS.LaunchData state document for a launch (cmi5 section 10.2.1).
	 *
	 * `moveOn`, `masteryScore` and `launchParameters` come from the lesson when
	 * it carries them; without a course structure import the lesson rarely does,
	 * so `moveOn` falls back to NotApplicable, the cmi5 course structure default.
	 * learniq has no course structure publisher id, so the grouping context
	 * activity is the AU's own activity IRI.
	 *
	 * @param array<string, mixed> $lesson     The lesson as read.
	 * @param string               $lessonId   The lesson UUID.
	 * @param string               $activityId The AU activity IRI of this launch.
	 *
	 * @return array<string, mixed> The document.
	 */
	private function launchData(array $lesson, string $lessonId, string $activityId): array {
		$moveOn = (string)($lesson['moveOn'] ?? '');
		if (in_array($moveOn, self::MOVE_ON, true) === false) {
			$moveOn = 'NotApplicable';
		}

		$courseId = (string)($lesson['courseId'] ?? '');
		$data = [
			'contextTemplate' => [
				'contextActivities' => ['grouping' => [['objectType' => 'Activity', 'id' => $activityId]]],
				'extensions'        => [self::SESSION_ID_EXTENSION => $this->uuid()],
			],
			'launchMode'      => 'Normal',
			'moveOn'          => $moveOn,
			'returnURL'       => $this->urlGenerator->getAbsoluteURL('/apps/learniq/courses/' . rawurlencode($courseId) . '/lessons/' . rawurlencode($lessonId)),
		];

		$launchParameters = $lesson['launchParameters'] ?? null;
		if (is_string($launchParameters) === true && $launchParameters !== '') {
			$data['launchParameters'] = $launchParameters;
		}

		$masteryScore = $lesson['masteryScore'] ?? null;
		if ((is_int($masteryScore) === true || is_float($masteryScore) === true) && $masteryScore >= 0 && $masteryScore <= 1) {
			$data['masteryScore'] = $masteryScore;
		}

		return $data;
	}//end launchData()

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
