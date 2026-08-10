<?php

/**
 * Scholiq Settings Controller
 *
 * Controller for managing Scholiq application settings.
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\SettingsService;
use OCA\Scholiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for managing Scholiq application settings.
 *
 * Bespoke by design, NOT the AppHost generic:
 * `AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()` skips the DI alias
 * whenever the leaf app ships a controller of that name, and Scholiq ships
 * this one — so every method the route table points at `settings#*` has to
 * exist here. It publishes the canonical ADR-066 surface:
 * `index` (read) / `update` (canonical PUT write) / `create` (legacy POST
 * alias) / `load` (register re-import).
 *
 * @spec openspec/specs/apphost-adoption/spec.md#requirement-boilerplate-served-by-apphost-generics-with-parity
 */
class SettingsController extends Controller
{
    /**
     * Constructor for the SettingsController.
     *
     * @param IRequest        $request         The request object
     * @param SettingsService $settingsService The settings service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Retrieve all current settings.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-1
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function index(): JSONResponse
    {
        return new JSONResponse(
            $this->settingsService->getSettings()
        );
    }//end index()

    /**
     * Update settings with provided data — the canonical write.
     *
     * This mirrors {@see \OCA\OpenRegister\AppHost\Controller\GenericSettingsControllerBase::update()},
     * which the canonical AppHost route table
     * ({@see \OCA\OpenRegister\AppHost\Routes}) reaches on
     * `PUT /api/settings` (`settings#update`). Because Scholiq ships this
     * controller itself, `AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
     * skips the generic alias entirely, so the method has to exist here — the
     * generic will not fill the gap.
     *
     * Writes exactly what `create()` has always written: every key of
     * `SettingsService::CONFIG_KEYS` present in the request parameters is
     * persisted to `IAppConfig`, and the refreshed settings map is returned
     * under Scholiq's `{success, config}` envelope. The envelope is kept
     * rather than the generic's flat payload so the two existing POST callers
     * (`src/store/modules/settings.js::saveSettings()` and
     * `src/views/ScholiqSettings.vue::saveDefaultRegister()`) keep the exact
     * response shape they parse today.
     *
     * @return JSONResponse The `{success, config}` envelope carrying the refreshed settings map.
     *
     * @spec openspec/specs/apphost-adoption/spec.md#scenario-settings-endpoints-parity
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function update(): JSONResponse
    {
        $data   = $this->request->getParams();
        $config = $this->settingsService->updateSettings($data);

        return new JSONResponse(
            [
                'success' => true,
                'config'  => $config,
            ]
        );
    }//end update()

    /**
     * Legacy alias for {@see update()} — `POST /api/settings`.
     *
     * The canonical AppHost route table still ships `settings#create` for the
     * pre-ADR-066 `index/create/load` dialect, and both of Scholiq's own
     * frontend writers still POST here, so it stays reachable (ADR-029) and
     * behaviourally identical to `update()`.
     *
     * The `#[AuthorizedAdminSetting]` attribute is re-declared rather than
     * inherited from the delegate: NC's SecurityMiddleware evaluates the
     * attributes of the *dispatched* method only, so dropping it here would
     * silently open the legacy verb to any authenticated user.
     *
     * @return JSONResponse The `{success, config}` envelope carrying the refreshed settings map.
     *
     * @spec openspec/specs/apphost-adoption/spec.md#scenario-settings-endpoints-parity
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function create(): JSONResponse
    {
        return $this->update();
    }//end create()

    /**
     * Re-import the configuration from scholiq_register.json.
     *
     * Forces a fresh import regardless of version, auto-configuring
     * all schema and register IDs from the import result.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-26
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function load(): JSONResponse
    {
        $result = $this->settingsService->reloadConfiguration();

        return new JSONResponse($result);
    }//end load()
}//end class
