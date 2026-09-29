<?php

/**
 * Learniq cmi5 Launch Sessions
 *
 * What a cmi5 launch leaves behind for the AU: the `LMS.LaunchData` state
 * document the AU reads first (cmi5 section 10.2.1), and the one-time fetch
 * code the AU redeems for its auth-token (cmi5 section 8.2).
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes LMS.LaunchData and issues and redeems fetch codes.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class Cmi5LaunchSessions {

	/**
	 * The stateId of the launch data document (cmi5 section 10.2.1).
	 *
	 * @var string
	 */
	public const LAUNCH_DATA_STATE_ID = 'LMS.LaunchData';

	/**
	 * Seconds a fetch code stays redeemable.
	 *
	 * @var int
	 */
	private const FETCH_TTL_SECONDS = 300;

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
	 * @param XapiDocumentStore $documents    Stores the LMS.LaunchData state document.
	 * @param ICacheFactory     $cacheFactory Holds the one-time fetch codes.
	 * @param ISecureRandom     $secureRandom Generates fetch codes.
	 * @param IURLGenerator     $urlGenerator Builds the return URL.
	 * @param LoggerInterface   $logger       PSR logger.
	 */
	public function __construct(
		private readonly XapiDocumentStore $documents,
		ICacheFactory $cacheFactory,
		private readonly ISecureRandom $secureRandom,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
		$this->fetchCodes = $cacheFactory->createDistributed('learniq-cmi5-fetch');
	}//end __construct()

	/**
	 * Write the LMS.LaunchData state document for one launch.
	 *
	 * @param string               $learnerId The learner's uid.
	 * @param array<string, mixed> $launch    `actor`, `lesson` (as read), `lessonId`, `activityId` and `registration`.
	 *
	 * @return bool True when the document is stored; false (and logged) when it is not.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function writeLaunchData(string $learnerId, array $launch): bool {
		$lessonId   = (string)($launch['lessonId'] ?? '');
		$activityId = (string)($launch['activityId'] ?? '');
		$lesson     = (array)($launch['lesson'] ?? []);
		$data       = $this->launchData(lesson: $lesson, lessonId: $lessonId, activityId: $activityId);

		try {
			$this->documents->put(
				key: $this->documents->key(
					kind: XapiDocumentStore::KIND_STATE,
					actorId: $learnerId,
					activityId: $activityId,
					registration: (string)($launch['registration'] ?? ''),
					documentId: self::LAUNCH_DATA_STATE_ID
				),
				contents: (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				contentType: 'application/json',
				context: ['agent' => $launch['actor'] ?? [], 'lessonId' => $lessonId]
			);
		} catch (Throwable $e) {
			$this->logger->error('[Cmi5LaunchSessions] writing LMS.LaunchData failed: {msg}', ['msg' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end writeLaunchData()

	/**
	 * Hold a launch token behind a fresh one-time fetch code.
	 *
	 * @param string $token The launch token.
	 *
	 * @return string The fetch code.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#5-launch-endpoint-wiring
	 */
	public function issueFetchCode(string $token): string {
		$code = $this->secureRandom->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->fetchCodes->set($code, $token, self::FETCH_TTL_SECONDS);

		return $code;
	}//end issueFetchCode()

	/**
	 * Redeem a fetch code once.
	 *
	 * @param string $code The fetch code.
	 *
	 * @return string|null The launch token, or null when the code was used or has expired.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#5-launch-endpoint-wiring
	 */
	public function redeemFetchCode(string $code): ?string {
		$token = $this->fetchCodes->get($code);
		if (is_string($token) === false || $token === '') {
			return null;
		}

		$this->fetchCodes->remove($code);
		return $token;
	}//end redeemFetchCode()

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

		$courseId   = (string)($lesson['courseId'] ?? '');
		$returnPath = '/apps/learniq/courses/' . rawurlencode($courseId) . '/lessons/' . rawurlencode($lessonId);
		$data = [
			'contextTemplate' => [
				'contextActivities' => ['grouping' => [['objectType' => 'Activity', 'id' => $activityId]]],
				'extensions'        => [self::SESSION_ID_EXTENSION => $this->uuid()],
			],
			'launchMode'      => 'Normal',
			'moveOn'          => $moveOn,
			'returnURL'       => $this->urlGenerator->getAbsoluteURL($returnPath),
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
	 * A fresh v4 UUID for the cmi5 session id.
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
