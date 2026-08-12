<?php

/**
 * Scholiq Service Override Registrar
 *
 * The ADR-040 "override cookbook" half of `Application::register()`: after the
 * OpenRegister AppHost `Bootstrap` has aliased the generic settings controller,
 * settings service, action-auth service and install repair step, this registrar
 * re-points each of them at Scholiq's bespoke implementation so the bespoke one
 * wins.
 *
 * It runs AFTER Bootstrap for exactly that reason; registering it earlier would
 * let the generic aliases overwrite these.
 *
 * @category AppInfo
 * @package  OCA\Scholiq\AppInfo\Registrar
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\AppInfo\Registrar;

use OCA\Scholiq\Controller\SettingsController;
use OCA\Scholiq\Repair\InitializeSettings;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\SettingsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Re-points the AppHost's generic settings/auth/repair aliases at Scholiq's own classes.
 */
class ServiceOverrideRegistrar {
	/**
	 * Register every bespoke override.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 * @param string $appId The Scholiq app id.
	 *
	 * @return void
	 */
	public function register(IRegistrationContext $context, string $appId): void {
		// Override cookbook (ADR-040): re-point the settings controller + service
		// at Scholiq's bespoke implementations AFTER Bootstrap, so they win over
		// the generic aliases. Scholiq keeps the bespoke SettingsService because
		// its register-import path passes the full payload to OpenRegister's
		// ConfigurationService::importFromApp(appId, data, version, force); the
		// generic AppHostSettingsService::loadConfiguration() invokes the 2-arg
		// importFromApp(appId, force) shape, which is incompatible with the
		// ConfigurationService signature on OpenRegister `development`. Aliasing
		// settings to the generic would break /api/settings/load and the
		// InitializeSettings repair step. Tracked as an upstream AppHost fix.
		$context->registerService(
			SettingsService::class,
			static function (ContainerInterface $c) {
				return new SettingsService(
					appConfig: $c->get('OCP\\IAppConfig'),
					appManager: $c->get('OCP\\App\\IAppManager'),
					container: $c,
					groupManager: $c->get('OCP\\IGroupManager'),
					userSession: $c->get('OCP\\IUserSession'),
					logger: $c->get('Psr\\Log\\LoggerInterface')
				);
			}
		);
		$context->registerService(
			SettingsController::class,
			static function (ContainerInterface $c) {
				return new SettingsController(
					request: $c->get('OCP\\IRequest'),
					settingsService: $c->get(SettingsService::class)
				);
			}
		);

		// Bind Scholiq's ActionAuthService class name to a concrete instance of
		// the local stub (extends GenericActionAuthService). Bootstrap registered
		// the generic class under this name, but five domain controllers
		// (KeyAdmin/ActionMatrix/AuditPackExport/QtiImport/ExternalTraining/
		// Rollover) type-hint `OCA\Scholiq\Service\ActionAuthService`, so the DI
		// container must return an instance that IS that subtype.
		$context->registerService(
			ActionAuthService::class,
			static function (ContainerInterface $c) use ($appId) {
				return new ActionAuthService(
					appId: $appId,
					appConfig: $c->get('OCP\\IAppConfig'),
					groupManager: $c->get('OCP\\IGroupManager')
				);
			}
		);

		// Re-point the InitializeSettings repair step at Scholiq's bespoke step
		// (injects the bespoke SettingsService above). Bootstrap aliased this
		// class name at GenericInitializeSettings, which drives the generic
		// settings service's incompatible importFromApp call (see note above).
		$context->registerService(
			InitializeSettings::class,
			static function (ContainerInterface $c) {
				return new InitializeSettings(
					settingsService: $c->get(SettingsService::class),
					logger: $c->get('Psr\\Log\\LoggerInterface')
				);
			}
		);

	}//end register()
}//end class
