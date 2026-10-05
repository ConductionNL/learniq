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
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CourseStore\StoreAccessService;
use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Service\LineManagerCheck;
use OCA\Learniq\Service\LoadedExampleSets;
use OCA\Learniq\Service\SegmentService;
use OCA\Learniq\Service\Settings\MenuStructure;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
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
 * @spec exclude framework glue — SPA shell, manifest passthrough and initial-state provider (role, segment, store access, tenant)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One over the threshold since
 * the caller's tenant joined the initial state. This controller is where the
 * page's per-user values are gathered, and each one comes from its own service,
 * resolved lazily (segment, store access, tenant) so the default route stays
 * up without OpenRegister.
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
	 * @param LoadedExampleSets $loadedSets The example sets the wizard loaded (app config only).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IInitialState $initialState,
		private readonly DashboardRoleService $dashboardRoleSvc,
		private readonly ContainerInterface $container,
		private readonly LoadedExampleSets $loadedSets,
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
			$workspace = $this->resolveWorkspace();
			$this->initialState->provideInitialState('segment', $workspace['segment']);
			$this->initialState->provideInitialState('chosenSegment', $workspace['chosenSegment']);
			$this->initialState->provideInitialState('confidentialCounsellor', $this->dashboardRoleSvc->isConfidentialCounsellor($user));
			// A line manager has no staff role but approves their reports'
			// self sign-ups, so the menu needs this flag to show them the requests.
			$this->initialState->provideInitialState('managesLearners', $this->resolveManagesLearners());
			$this->initialState->provideInitialState('storeAccess', $this->resolveStoreAccess());
			// One removal step per loaded example set in the setup wizard (D34).
			$this->initialState->provideInitialState('loadedExampleSets', $this->loadedSets->all());
			// The caller's tenant, for nextcloud-vue's tenant context: the
			// shared create dialog fills a hidden `tenant_id` from it.
			$this->initialState->provideInitialState('callerTenant', $this->resolveCallerTenant());
			// Which structure `src/main.js` builds: the simple menu (the default)
			// or the full one (simple-structure-profile).
			$this->initialState->provideInitialState(MenuStructure::KEY, $this->resolveMenuStructure());
		}

		return new TemplateResponse(Application::APP_ID, 'index');
	}//end index()

	/**
	 * The instance segment for `runtime.workspace.segment` and the segment an
	 * admin chose for `runtime.workspace.chosenSegment`, or the defaults.
	 *
	 * 🔴 RESOLVED LAZILY, NOT INJECTED. SegmentService reads OpenRegister, and
	 * this is the app's default route: a constructor-injected OpenRegister
	 * dependency here makes the start screen 500 on an instance without
	 * OpenRegister instead of letting it explain what is missing (ADR-083
	 * rule 3, gate-66). Resolving it at call time inside a catch that degrades
	 * to the defaults keeps the page up either way; a null chosen segment
	 * keeps every menu visible.
	 *
	 * @return array{segment: string, chosenSegment: string|null} The two values.
	 */
	private function resolveWorkspace(): array {
		try {
			return $this->container->get(SegmentService::class)->workspace();
		} catch (Throwable $e) {
			return ['segment' => SegmentService::DEFAULT_SEGMENT, 'chosenSegment' => null];
		}
	}//end resolveWorkspace()

	/**
	 * Which structure the app shows: `simple` (the default) or `full`.
	 *
	 * Resolved lazily and degraded to the default on failure, like the other
	 * values this page provides: this is the app's default route, and a start
	 * screen that fails over a menu setting helps nobody.
	 *
	 * @return string `simple` or `full`.
	 *
	 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
	 */
	private function resolveMenuStructure(): string {
		try {
			return $this->container->get(MenuStructure::class)->current();
		} catch (Throwable $e) {
			return MenuStructure::SIMPLE;
		}
	}//end resolveMenuStructure()

	/**
	 * Which course store actions the signed-in user may take, for the Store page and
	 * the export screen (store-rights-for-teachers, D27).
	 *
	 * Resolved lazily and degraded to "none" on failure, for the same reason
	 * as resolveSegment(): the store service reaches OpenRegister, and this is
	 * the app's default route. Showing no store buttons is the safe answer; the
	 * store endpoints enforce the rights either way.
	 *
	 * @return array{install: bool, publish: bool}
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take
	 */
	private function resolveStoreAccess(): array {
		try {
			return $this->container->get(StoreAccessService::class)->forCurrentUser();
		} catch (Throwable $e) {
			return ['install' => false, 'publish' => false];
		}
	}//end resolveStoreAccess()

	/**
	 * Whether the signed-in user is anyone's line manager, for the
	 * `user.managesLearners` menu gate.
	 *
	 * Resolved lazily and degraded to false on failure, for the same reason
	 * as resolveWorkspace(): LineManagerCheck reads OpenRegister, and this is
	 * the app's default route.
	 *
	 * @return bool True when at least one learner names the user as manager.
	 */
	private function resolveManagesLearners(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		try {
			return $this->container->get(LineManagerCheck::class)->managesLearners(user: $user);
		} catch (Throwable $e) {
			return false;
		}
	}//end resolveManagesLearners()

	/**
	 * The signed-in user's tenant, as CallerTenantResolver resolves it: the
	 * per-user `tenant_id` binding, else the instance id. Every row learniq
	 * writes server-side carries this value, so a record created through the
	 * shared create dialog must carry it too; nextcloud-vue reads it from the
	 * tenant context `src/main.js` feeds with this initial state.
	 *
	 * Resolved lazily and degraded to null on failure, for the same reason
	 * as resolveWorkspace(): CallerTenantResolver takes OpenRegister's
	 * ObjectService, and this is the app's default route. Null means no
	 * tenant context, so the dialog leaves the key out rather than guess.
	 *
	 * @return string|null The tenant id, or null when it cannot be resolved.
	 */
	private function resolveCallerTenant(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		try {
			$tenant = $this->container->get(CallerTenantResolver::class)->resolve(user: $user);
		} catch (Throwable $e) {
			return null;
		}

		if ($tenant === '') {
			return null;
		}

		return $tenant;
	}//end resolveCallerTenant()

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
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
		}

		$manifestPath = __DIR__ . '/../../src/manifest.json';
		$manifestJson = file_get_contents($manifestPath);
		$manifest = json_decode($manifestJson, associative: true);

		return new JSONResponse($manifest);
	}//end manifest()
}//end class
