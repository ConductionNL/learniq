<?php

/**
 * Learniq xAPI Caller Resolver
 *
 * Works out who is calling learniq's LRS. Every xAPI resource (statements,
 * activity state, agent profile) authenticates the same way:
 * - a launched cmi5 AU sends its auth-token as `Authorization: Basic <token>`
 *   (or `Bearer <token>`); the launch JWT inside is the credential;
 * - the lesson player of a signed-in learner calls with its session and a
 *   valid request token.
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

use OCP\IRequest;
use OCP\IUserSession;

/**
 * Resolves the authenticated LRS caller from a launch token or a session.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class XapiCallerResolver {

	/**
	 * Constructor.
	 *
	 * @param IUserSession           $userSession The session, for lesson-player callers.
	 * @param Cmi5LaunchTokenService $tokens      Verifies AU auth-tokens.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly Cmi5LaunchTokenService $tokens,
	) {
	}//end __construct()

	/**
	 * Resolve the caller: a valid launch token first, else a session with a valid request token.
	 *
	 * A request that carries a Basic or Bearer credential is judged on that
	 * credential alone: an invalid token is refused even when a session exists.
	 *
	 * @param IRequest $request The current request.
	 *
	 * @return array{actorId: string, launch: array<string, string>}|null The identity, or null.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function resolve(IRequest $request): ?array {
		$header = trim((string)$request->getHeader('Authorization'));
		if (preg_match('/^(Basic|Bearer)\s+(\S+)$/i', $header, $match) === 1) {
			$claims = $this->tokens->verifyAuthToken(credential: $match[2]);
			if ($claims === null) {
				return null;
			}

			return [
				'actorId' => (string)$claims['sub'],
				'launch'  => [
					'lessonId'     => (string)($claims['aud'] ?? ''),
					'courseId'     => (string)($claims['courseId'] ?? ''),
					'activityId'   => (string)($claims['activityId'] ?? ''),
					'registration' => (string)($claims['registration'] ?? ''),
				],
			];
		}

		$user = $this->userSession->getUser();
		if ($user === null || $request->passesCSRFCheck() === false) {
			return null;
		}

		return ['actorId' => $user->getUID(), 'launch' => []];
	}//end resolve()
}//end class
