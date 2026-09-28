<?php

/**
 * Learniq Page Controller
 *
 * Renders the SPA shell and serves the bundled app manifest (ADR-024 §4).
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\CourseStore\StoreAccessService;
use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Service\SegmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Renders the main SPA template and serves the bundled app manifest.
 *
 * Per ADR-024 §4: the /api/manifest endpoint returns the bundled manifest
 * blob unchanged (v0.1). A partial-override hook from IAppConfig is deferred
 * to v0.2 — the frontend loader's silent-fallback path is exercised in v0.1.
 *
 * @spec exclude framework glue — SPA shell + manifest passthrough + role, segment and store-access initial-state provider; no business behaviour
 */
class PageController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession The user session.
	 * @param IInitialState $initialState The initial-state service.
	 * @param DashboardRoleService $dashboardRoleSvc Resolves the user's role + dashboard views.
	 * @param ContainerInterface $container Resolves SegmentService lazily (see resolveSegment()).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IInitialState $initialState,
		private readonly DashboardRoleService $dashboardRoleSvc,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the main SPA page.
	 *
	 * Provides the resolved Learniq role context as initial state so the
	 * manifest shell can populate `runtime.user.primaryRole` (menu visibleIf)
	 * and the role-aware Dashboards component can pick its default view and
	 * switcher set without a second round-trip. Also provides the instance's
	 * segment, which `src/main.js` publishes as `runtime.workspace.segment` so a
	 * menu `visibleIf` on the segment resolves against a defined value.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 *
	 * @spec exclude framework glue — returns the static index TemplateResponse that boots the Vue SPA; provides role initial state only
	 */
	public function index(): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$this->initialState->provideInitialState('primaryRole', $this->dashboardRoleSvc->resolvePrimaryRole($user));
			$this->initialState->provideInitialState('dashboardRole', $this->dashboardRoleSvc->resolveDefaultView($user));
			$this->initialState->provideInitialState('dashboardRoles', $this->dashboardRoleSvc->resolveViews($user));
			$this->initialState->provideInitialState('segment', $this->resolveSegment());
			$this->initialState->provideInitialState('confidentialCounsellor', $this->dashboardRoleSvc->isConfidentialCounsellor($user));
			$this->initialState->provideInitialState('storeAccess', $this->resolveStoreAccess(user: $user));
		}

		return new TemplateResponse(Application::APP_ID, 'index');
	}//end index()

	/**
	 * The instance segment for `runtime.workspace.segment`, or the default.
	 *
	 * 🔴 RESOLVED LAZILY, NOT INJECTED. SegmentService reads OpenRegister, and
	 * this is the app's default route: a constructor-injected OpenRegister
	 * dependency here makes the start screen 500 on an instance without
	 * OpenRegister instead of letting it explain what is missing (ADR-083
	 * rule 3, gate-66). Resolving it at call time inside a catch that degrades
	 * to the default keeps the page up either way.
	 *
	 * @return string One of SegmentService::SEGMENTS.
	 */
	private function resolveSegment(): string {
		try {
			return $this->container->get(SegmentService::class)->currentSegment();
		} catch (Throwable $e) {
			return SegmentService::DEFAULT_SEGMENT;
		}
	}//end resolveSegment()

	/**
	 * Which course store actions the user may take, for the Store page and
	 * the export screen (store-rights-for-teachers, D27).
	 *
	 * Resolved lazily and degraded to "none" on failure, for the same reason
	 * as resolveSegment(): the store service reaches OpenRegister, and this is
	 * the app's default route. Showing no store buttons is the safe answer; the
	 * store endpoints enforce the rights either way.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return array{install: bool, publish: bool}
	 *
	 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take
	 */
	private function resolveStoreAccess(IUser $user): array {
		try {
			return $this->container->get(StoreAccessService::class)->forUser(user: $user);
		} catch (Throwable $e) {
			return ['install' => false, 'publish' => false];
		}
	}//end resolveStoreAccess()

	/**
	 * Serve the SPA for deep links (Vue history mode). Delegates to index().
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 *
	 * @spec exclude framework glue — deep-link catch-all that delegates to index() so Vue Router can resolve the path; no business behavior
	 */
	public function catchAll(): TemplateResponse {
		return $this->index();
	}//end catchAll()

	/**
	 * Return the bundled app manifest as JSON (ADR-024 §4).
	 *
	 * V0.1: returns the bundled src/manifest.json blob unchanged.
	 * V0.2 (deferred): will merge partial overrides from IAppConfig for
	 * admin-customised menu order / hidden pages.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-5
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifest(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$manifestPath = __DIR__ . '/../../src/manifest.json';
		$manifestJson = file_get_contents($manifestPath);
		$manifest = json_decode($manifestJson, associative: true);

		return new JSONResponse($manifest);
	}//end manifest()
}//end class
