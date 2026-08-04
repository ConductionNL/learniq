<?php

/**
 * Scholiq Application
 *
 * Main application class for the Scholiq Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Scholiq\AppInfo
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

namespace OCA\Scholiq\AppInfo;

use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\Scholiq\AppInfo\Registrar\EventListenerWiring;
use OCA\Scholiq\AppInfo\Registrar\ServiceOverrideRegistrar;
use OCA\Scholiq\Mcp\ScholiqToolProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Main application class for the Scholiq Nextcloud app.
 *
 * Per ADR-031: DI registrations limited to legitimate PHP seams only:
 *   - Cryptographic operations (Cmi5LaunchTokenService)
 *   - Lifecycle guards (AssessmentPublishGuard)
 *   - NC framework requirements (controllers, event listeners)
 *
 * NOT registered: AuditTrail, AuditedController, AiFeatureRegistry,
 * NotificationService, OpenRegisterGuard, AdminSettings, PersonalSettings.
 * All state machines and notifications are declared via x-openregister-*
 * in lib/Settings/scholiq_register.json (per ADR-022 + ADR-031).
 *
 * Settings UI is handled by the manifest's Settings custom page (ScholiqSettings
 * Vue component) — no OCP\Settings\ISettings PHP class needed (per ADR-024).
 *
 * The listener wiring itself lives in domain-scoped registrars under
 * {@see \OCA\Scholiq\AppInfo\Registrar}, reached through
 * {@see EventListenerWiring}. This class therefore names the bootstrap seams,
 * not the ~40 listener classes behind them.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'scholiq';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        // ADR-040: adopt the OpenRegister AppHost. One call wires the generic
        // SPA/settings/preferences/health/metrics controllers, the settings +
        // action-auth services, the install repair steps, the admin settings
        // panel + section, the manifest-driven deep-link listener, and the
        // observability aliases — every closure is lazy, so a disabled
        // OpenRegister never fatals Nextcloud bootstrap.
        //
        // The MCP provider alias (formerly hand-written here) and the deep-link
        // listener (formerly bespoke PHP patterns) are handled by Bootstrap from
        // the `mcpProvider` option + the manifest `deepLinks` block.
        Bootstrap::register(
            $context,
            self::APP_ID,
            [
                'namespace'   => 'OCA\\Scholiq',
                'sectionName' => 'Scholiq',
                'mcpProvider' => ScholiqToolProvider::class,
            ]
        );

        // Override cookbook (ADR-040): re-point the settings controller/service,
        // the action-auth service and the install repair step at Scholiq's own
        // implementations, AFTER Bootstrap so they win over the generic aliases.
        (new ServiceOverrideRegistrar())->register(context: $context, appId: self::APP_ID);

        // Every cross-object write bridge (ADR-031 legitimate exceptions), wired
        // by domain. See the individual registrars for the per-listener rationale.
        (new EventListenerWiring())->registerAll(context: $context);

    }//end register()

    /**
     * Boot the application.
     *
     * Every object-event listener that declares a register/schema interest is
     * subscribed here rather than in register(): OpenRegister's
     * `ObjectEventSubscription` is only guaranteed autoloadable once every app's
     * register() has run. See {@see \OCA\Scholiq\AppInfo\Registrar\BootListenerRegistrar}.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        $dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

        (new EventListenerWiring())->bootFilteredListeners(dispatcher: $dispatcher, appId: self::APP_ID);

    }//end boot()
}//end class
