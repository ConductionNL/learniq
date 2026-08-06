<?php

/**
 * Scholiq OpenRegister autoload prelude
 *
 * Puts OpenRegister's PSR-4 prefix on the autoloader so this app can reference
 * `OCA\OpenRegister\AppHost\…` from its own `Application::register()`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppInfo
 * @package  OCA\Scholiq\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\AppInfo;

/**
 * Registers OpenRegister's autoload prefix before AppHost is referenced.
 *
 * ## Why this is needed (ADR-040)
 *
 * `OC_App::getEnabledApps()` does `sort($apps)`, and
 * `Coordinator::registerApps()` walks THAT sorted list calling
 * `OC_App::registerAutoloading($appId, $path)` and then `$app->register()` for
 * one app at a time. So every app's `register()` runs BEFORE the PSR-4 prefix
 * of every alphabetically-LATER app exists.
 *
 * `scholiq` sorts AFTER `openregister`, so today the prefix happens to be on
 * the autoloader by the time this app registers. That is an accident of the
 * alphabet, not a design property, and Scholiq depends on it far more sharply
 * than the apps that sort earlier: its `Bootstrap::register()` call is
 * UNGUARDED, so the moment the ordering stops holding — an app id change, a
 * multi-`apps_paths` install, this composition root moving into a package that
 * sorts earlier — the resulting `\Error` aborts the WHOLE of
 * `Application::register()`. `Coordinator::registerApps()` catches it, logs an
 * `emergency` and continues, so Scholiq would stay enabled and keep serving
 * with every registration below that line silently missing.
 *
 * Registering the prefix ourselves removes the dependency on ordering
 * entirely. `OC_App::registerAutoloading()` is idempotent, so on the current
 * ordering this call is free.
 *
 * Lives in its own class rather than inline in `Application::register()` for
 * one reason: `Application` cannot be constructed without a Nextcloud DI
 * container, so an inline prelude is unreachable from a unit test. Here the
 * degraded-path contract — "this NEVER throws, whatever the instance looks
 * like" — is directly assertable, and it is asserted.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
final class OpenRegisterAutoloader
{
    /**
     * Register OpenRegister's PSR-4 prefix on the composer autoloader.
     *
     * MUST be called before any `OCA\OpenRegister\…` reference in
     * `Application::register()`, including a `class_exists()` probe — the probe
     * answers FALSE, not "not yet loaded", and a FALSE is indistinguishable
     * from OpenRegister being absent.
     *
     * `OC_App::registerAutoloading()` touches only the autoloader and is
     * idempotent: it early-returns on an `$alreadyRegistered` key, so calling
     * this more than once is free.
     *
     * Deliberately NOT `IAppManager::loadApp('openregister')`: that marks
     * OpenRegister loaded and calls `Coordinator::bootApp()`, booting it before
     * its own `register()` has run.
     *
     * @return bool True when the prefix is registered, false when OpenRegister
     *              is absent, disabled, or otherwise unresolvable.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) OC_App is Nextcloud's legacy
     * bootstrap class. There is no OCP interface for registering another app's
     * autoloader, and this runs at the composition root where no container is
     * available to resolve an adapter from.
     *
     * @spec openspec/specs/apphost-adoption/spec.md
     */
    public static function register(): bool
    {
        try {
            $appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
            $path       = $appManager->getAppPath('openregister');
            \OC_App::registerAutoloading('openregister', $path);
            return true;
        } catch (\Throwable) {
            // OpenRegister absent, disabled, or the server container is not up
            // (unit tests). Never rethrow: an exception escaping here would
            // abort the caller's entire register(), which is the exact defect
            // this prelude exists to prevent.
            return false;
        }

    }//end register()
}//end class
