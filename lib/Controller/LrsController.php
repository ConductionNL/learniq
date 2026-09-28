<?php

/**
 * Learniq LRS Controller
 *
 * The xAPI statement endpoint of learniq (ADR-002 §Implementation notes):
 * POST and GET on `/api/lrs/statements`.
 *
 * Two callers authenticate differently:
 * - a launched cmi5 AU sends its launch token as `Authorization: Basic <token>`
 *   (or `Bearer <token>`); it has no Nextcloud session, so the POST route is a
 *   public page and the token is the credential;
 * - the lesson player of a signed-in learner (the SCORM 1.2 shim) posts with its
 *   session and request token, which is checked here because the route itself
 *   cannot demand CSRF for the token caller.
 *
 * Either way the identity comes from the credential and is stamped as
 * `verified_actor_id` by {@see XapiStatementIngest}; the statement's own
 * `actor` is stored but never trusted.
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
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use InvalidArgumentException;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Cmi5LaunchTokenService;
use OCA\Learniq\Service\XapiStatementIngest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * xAPI statement ingest and query.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class LrsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request      The current request.
	 * @param IUserSession           $userSession  The session, for lesson-player callers.
	 * @param IGroupManager          $groupManager Admin check for the query scope.
	 * @param Cmi5LaunchTokenService $tokens       Verifies AU launch tokens.
	 * @param XapiStatementIngest    $ingest       Stamps, stores and reads statements.
	 * @param LoggerInterface        $logger       PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly Cmi5LaunchTokenService $tokens,
		private readonly XapiStatementIngest $ingest,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Store one statement or a list of statements.
	 *
	 * Public route on purpose: an AU has no session, so the launch token is its
	 * credential. A caller with neither a valid token nor a session (with a
	 * valid request token) gets 401 and nothing is stored.
	 *
	 * @return JSONResponse The stored statement ids, or an error.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function postStatements(): JSONResponse {
		$identity = $this->authenticate();
		if ($identity === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$ids = $this->ingest->ingest(
				statements: $this->statementsFromBody(),
				actorId: $identity['actorId'],
				launch: $identity['launch']
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('[LrsController] storing xAPI statements failed: {msg}', ['msg' => $e->getMessage()]);
			return new JSONResponse(data: ['error' => 'The statements could not be stored'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $ids);
	}//end postStatements()

	/**
	 * Read statements. A non-admin caller only ever gets their own.
	 *
	 * @param string $lessonId Optional lesson filter.
	 * @param string $courseId Optional course filter.
	 * @param int    $limit    Page size.
	 *
	 * @return JSONResponse `{statements: [...]}`.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
	 */
	#[NoAdminRequired]
	public function getStatements(string $lessonId = '', string $courseId = '', int $limit = 50): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$uid        = $user->getUID();
		$statements = $this->ingest->query(
			callerId: $uid,
			isAdmin: $this->groupManager->isAdmin($uid) === true,
			filters: ['lessonId' => $lessonId, 'courseId' => $courseId],
			limit: $limit
		);

		return new JSONResponse(data: ['statements' => $statements]);
	}//end getStatements()

	/**
	 * Resolve the caller: a valid launch token first, else a session with a valid request token.
	 *
	 * @return array{actorId: string, launch: array<string, string>}|null The identity, or null.
	 */
	private function authenticate(): ?array {
		$header = trim((string)$this->request->getHeader('Authorization'));
		if (preg_match('/^(Basic|Bearer)\s+(\S+)$/i', $header, $match) === 1) {
			$claims = $this->tokens->verifyLaunchToken(token: $match[2]);
			if ($claims === null) {
				return null;
			}

			return [
				'actorId' => (string)$claims['sub'],
				'launch'  => ['lessonId' => (string)($claims['aud'] ?? ''), 'courseId' => (string)($claims['courseId'] ?? '')],
			];
		}

		$user = $this->userSession->getUser();
		if ($user === null || $this->request->passesCSRFCheck() === false) {
			return null;
		}

		return ['actorId' => $user->getUID(), 'launch' => []];
	}//end authenticate()

	/**
	 * The statements in the JSON body: a single statement object or a list.
	 *
	 * @return array<int, mixed> The statements.
	 */
	private function statementsFromBody(): array {
		$params = $this->request->getParams();
		if (isset($params['actor']) === true || isset($params['verb']) === true) {
			return [$params];
		}

		return array_values(
			array_filter(
				$params,
				static fn ($value, $key): bool => is_int($key) === true && is_array($value) === true,
				ARRAY_FILTER_USE_BOTH
			)
		);
	}//end statementsFromBody()
}//end class
