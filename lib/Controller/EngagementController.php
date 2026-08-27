<?php

/**
 * Learniq Engagement Controller
 *
 * One read endpoint: `getMe`. It returns the signed-in learner's own points,
 * level name and streak as a single flat record.
 *
 * NOT a pass-through CRUD wrapper (ADR-031 / hydra-gate-redundant-
 * controller). OR's object API already serves LearnerEngagement and
 * EngagementLevel directly, and this controller deliberately does not
 * duplicate that. It exists because the KPI tile needs a JOIN across two
 * schemas -- `learner-engagement.levelId` resolved against
 * `engagement-level.name` -- which no single aggregation config expresses,
 * and which the frontend could otherwise only assemble with two sequential
 * round-trips whose failure modes differ.
 *
 * That second point is the substance rather than a nicety. The tile it backs
 * renders an error state on failure instead of a confident zero, and a
 * two-call client cannot honour that: the level lookup failing silently
 * yielded a learner with points and no level, indistinguishable from a
 * learner who has not reached one. Joining server-side makes the whole
 * record succeed or fail together.
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
 * @spec openspec/specs/engagement/spec.md#requirement-frontend-surfaces-a-private-points-level-widget-and-one-opt-in-leaderboard-view
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves the signed-in learner's own engagement record.
 *
 * @spec openspec/specs/engagement/spec.md#requirement-frontend-surfaces-a-private-points-level-widget-and-one-opt-in-leaderboard-view
 */
class EngagementController extends Controller {

	private const LEARNIQ_REGISTER = 'learniq';
	private const LEARNER_ENGAGEMENT_SCHEMA = 'learner-engagement';
	private const ENGAGEMENT_LEVEL_SCHEMA = 'engagement-level';

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request       HTTP request.
	 * @param IUserSession    $userSession   Current user session.
	 * @param ObjectService   $objectService OR object query service.
	 * @param IL10N           $l10n          Translations for the display summary.
	 * @param LoggerInterface $logger        Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the caller's own points, level name and streak.
	 *
	 * Visible unconditionally regardless of any Leaderboard opt-in or
	 * per-learner opt-out state: opting out of a peer-visible ranking never
	 * hides a learner's own progress from themselves (design.md
	 * "Pedagogical posture").
	 *
	 * @return JSONResponse `{totalPoints, levelName, currentStreakDays, summary}`, or an error response.
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	#[NoAdminRequired]
	public function getMe(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$row = $this->fetchOwnEngagement(learnerId: $user->getUID());
		} catch (Throwable $e) {
			// A failed read is NOT a learner with no points. Returning zeros
			// here would render identically to a genuine zero on the tile,
			// which is the exact confusion this endpoint exists to remove.
			$this->logger->error(
				'Could not read the caller\'s LearnerEngagement row',
				['app' => Application::APP_ID, 'exception' => $e]
			);
			return new JSONResponse(
				data: ['error' => 'Could not read your engagement record'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if ($row === null) {
			// An absent row really does mean no points yet, so this is a
			// successful answer rather than an error.
			return new JSONResponse(
				data: [
					'totalPoints' => 0.0,
					'levelName' => null,
					'currentStreakDays' => 0,
					'summary' => '',
				]
			);
		}

		$levelId = $row['levelId'] ?? null;
		$levelName = null;
		if (is_string($levelId) === true && $levelId !== '') {
			$levelName = $this->resolveLevelName(levelId: $levelId);
		}

		$streakDays = (int)($row['currentStreakDays'] ?? 0);

		return new JSONResponse(
			data: [
				'totalPoints' => (float)($row['totalPoints'] ?? 0),
				'levelName' => $levelName,
				'currentStreakDays' => $streakDays,
				'summary' => $this->buildSummary(levelName: $levelName, streakDays: $streakDays),
			]
		);
	}//end me()

	/**
	 * The tile's secondary line, composed and translated.
	 *
	 * WHY THE SERVER COMPOSES THIS. `CnStatWidget`'s caption interpolates
	 * `{token}` placeholders and resolves a missing one to the empty string,
	 * so a single static caption cannot express the two conditionals this
	 * line has always had: a learner with no level yet would render a
	 * leading orphan separator, and a 0-day streak would read "0-day
	 * streak" where the tile it replaces showed nothing. Joining here keeps
	 * the tile declarative -- `caption: '{summary}'` -- without moving that
	 * logic back into a bespoke component.
	 *
	 * `levelName` and `currentStreakDays` are still returned separately: this
	 * is the display string, not a replacement for the data.
	 *
	 * The `{days}-day streak` source string is the one the previous tile
	 * already used, so every existing catalogue translates this unchanged.
	 * PHP's `t()` is vsprintf-based and does not substitute `{days}` itself,
	 * so the placeholder is filled after translation -- exactly what the
	 * JavaScript `t()` does with the same string.
	 *
	 * @param string|null $levelName  The resolved level, when reached.
	 * @param int         $streakDays The current streak.
	 *
	 * @return string The caption, empty when there is nothing to say.
	 */
	private function buildSummary(?string $levelName, int $streakDays): string {
		$parts = [];
		if ($levelName !== null && $levelName !== '') {
			$parts[] = $levelName;
		}

		if ($streakDays > 0) {
			$parts[] = str_replace('{days}', (string)$streakDays, $this->l10n->t('{days}-day streak'));
		}

		return implode(' · ', $parts);
	}//end buildSummary()

	/**
	 * The caller's own LearnerEngagement row.
	 *
	 * LearnerEngagement's x-property-rbac allows admin plus self-match reads,
	 * so this filter is a narrowing convenience rather than the access
	 * control: the store refuses another learner's row regardless.
	 *
	 * @param string $learnerId The caller's Nextcloud user id.
	 *
	 * @return array<string, mixed>|null The row, or null when the learner has none.
	 */
	private function fetchOwnEngagement(string $learnerId): ?array {
		$rows = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::LEARNER_ENGAGEMENT_SCHEMA,
				'filters' => ['learnerId' => $learnerId],
				'limit' => 1,
			]
		);

		$row = ($rows[0] ?? null);
		if ($row === null) {
			return null;
		}

		if (is_array($row) === false) {
			$row = $row->jsonSerialize();
		}

		return $row;
	}//end fetchOwnEngagement()

	/**
	 * Resolve an EngagementLevel's display name by id.
	 *
	 * A level that cannot be resolved is reported as `null` -- the learner
	 * still sees their points -- but it is logged rather than swallowed,
	 * because a levelId pointing at nothing is a data fault, not a learner
	 * who has not reached a level.
	 *
	 * ObjectService::find() THROWS DoesNotExistException for an unknown id
	 * rather than returning null, so both exits below are reachable and both
	 * are needed.
	 *
	 * @param string $levelId UUID of the EngagementLevel.
	 *
	 * @return string|null The name, or null when it cannot be resolved.
	 */
	private function resolveLevelName(string $levelId): ?string {
		try {
			$level = $this->objectService->find(
				id: $levelId,
				register: self::LEARNIQ_REGISTER,
				schema: self::ENGAGEMENT_LEVEL_SCHEMA
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'LearnerEngagement.levelId does not resolve to an EngagementLevel',
				['app' => Application::APP_ID, 'levelId' => $levelId, 'exception' => $e]
			);
			return null;
		}

		if ($level === null) {
			$this->logger->warning(
				'LearnerEngagement.levelId does not resolve to an EngagementLevel',
				['app' => Application::APP_ID, 'levelId' => $levelId]
			);
			return null;
		}

		// ObjectService::find() returns ?ObjectEntity, so this is always an
		// entity here — there is no array branch to defend against, unlike
		// the findAll() reads above.
		$level = $level->jsonSerialize();

		$name = ($level['name'] ?? null);
		if (is_string($name) === true && $name !== '') {
			return $name;
		}

		return null;
	}//end resolveLevelName()
}//end class
