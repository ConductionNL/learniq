<?php

/**
 * Static-analysis stub for OpenRegister's AppHost engine classes (ADR-040).
 *
 * Analysis-only — discovered by phpstan.neon `scanDirectories` (tests/Stubs)
 * and psalm.xml `<stubs>`, and NEVER loaded at runtime. The file name does not
 * match any class name, so PSR-4 autoloading can never reach it.
 *
 * Scholiq ships three one-line engine-backed subclasses — Repair\InitializeActions,
 * Sections\SettingsSection and Settings\AdminSettings — that extend the classes
 * below. The real implementations live in the openregister sibling app
 * (openregister/lib/AppHost/), which is absent from the CI analysis path.
 * Without this file all three read as "extends unknown class" (an error PHPStan
 * explicitly refuses to let `ignoreErrors` suppress) and every
 * `#[AuthorizedAdminSetting(AdminSettings::class)]` attribute additionally fails
 * its `class-string<IDelegatedSettings>` check.
 *
 * The signatures below mirror the real classes verbatim as of openregister
 * lib/AppHost/Settings/GenericSettingsSection.php,
 * lib/AppHost/Settings/GenericAdminSettings.php and
 * lib/AppHost/Repair/GenericInitializeActions.php.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Settings {

	use OCP\App\IAppManager;
	use OCP\AppFramework\Http\TemplateResponse;
	use OCP\AppFramework\Services\IInitialState;
	use OCP\IAppConfig;
	use OCP\IURLGenerator;
	use OCP\Settings\IDelegatedSettings;
	use OCP\Settings\IIconSection;

	/**
	 * Analysis-only stub for the AppHost admin-settings section base class.
	 */
	class GenericSettingsSection implements IIconSection {

		/**
		 * Construct a settings section.
		 *
		 * @param string $sectionId The section id.
		 * @param string $name The display name.
		 * @param string $appId The app id.
		 * @param string $iconFile The icon file name.
		 * @param int $priority The display priority.
		 * @param IURLGenerator $urlGenerator The URL generator.
		 */
		public function __construct(
			protected readonly string $sectionId,
			protected readonly string $name,
			protected readonly string $appId,
			protected readonly string $iconFile,
			protected readonly int $priority,
			protected readonly IURLGenerator $urlGenerator,
		) {

		}//end __construct()

		/**
		 * Return the section id.
		 *
		 * @return string
		 */
		public function getID(): string {
			return $this->sectionId;
		}//end getID()

		/**
		 * Return the display name.
		 *
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}//end getName()

		/**
		 * Return the display priority.
		 *
		 * @return int
		 */
		public function getPriority(): int {
			return $this->priority;
		}//end getPriority()

		/**
		 * Return the absolute URL to the section icon.
		 *
		 * @return string
		 */
		public function getIcon(): string {
			return $this->urlGenerator->imagePath($this->appId, $this->iconFile);
		}//end getIcon()

	}//end class

	/**
	 * Analysis-only stub for the AppHost admin-settings panel base class.
	 */
	class GenericAdminSettings implements IDelegatedSettings {

		/**
		 * Construct an admin settings panel.
		 *
		 * @param string $appId The app id.
		 * @param string $sectionId The section id.
		 * @param int $priority The display priority.
		 * @param IAppManager $appManager The app manager.
		 * @param IInitialState $initialState The initial state service.
		 * @param IAppConfig|null $appConfig The app config service.
		 */
		public function __construct(
			protected readonly string $appId,
			protected readonly string $sectionId,
			protected readonly int $priority,
			protected readonly IAppManager $appManager,
			protected readonly IInitialState $initialState,
			protected readonly ?IAppConfig $appConfig = null,
		) {

		}//end __construct()

		/**
		 * Return the settings form template response.
		 *
		 * @return TemplateResponse
		 */
		public function getForm(): TemplateResponse {
			return new TemplateResponse($this->appId, 'settings-admin');
		}//end getForm()

		/**
		 * Return the section id.
		 *
		 * @return string
		 */
		public function getSection(): string {
			return $this->sectionId;
		}//end getSection()

		/**
		 * Return the display priority.
		 *
		 * @return int
		 */
		public function getPriority(): int {
			return $this->priority;
		}//end getPriority()

		/**
		 * Return the delegated-settings display name.
		 *
		 * @return string|null
		 */
		public function getName(): ?string {
			return null;
		}//end getName()

		/**
		 * Return the app-config keys this panel is authorised to write.
		 *
		 * @return array<string, list<string>>
		 */
		public function getAuthorizedAppConfig(): array {
			return [];
		}//end getAuthorizedAppConfig()

	}//end class

}

namespace OCA\OpenRegister\AppHost {

	use OCP\AppFramework\Bootstrap\IRegistrationContext;

	/**
	 * Analysis-only stub for the AppHost registration entrypoint (ADR-040).
	 *
	 * `AppInfo\Application::register()` calls `Bootstrap::register()` to wire the
	 * generic SPA / settings / action-auth / repair / deep-link services. Every
	 * closure it registers is lazy, so a disabled openregister never fatals
	 * Nextcloud bootstrap — but the static call site still has to resolve during
	 * analysis.
	 */
	class Bootstrap {

		/**
		 * Register the AppHost engine bindings on the leaf app's container.
		 *
		 * @param IRegistrationContext $context The app registration context.
		 * @param string $appId The leaf app id.
		 * @param array<string, mixed> $options The AppHost options block.
		 *
		 * @return void
		 */
		public static function register(IRegistrationContext $context, string $appId, array $options = []): void {

		}//end register()

	}//end class

}

namespace OCA\OpenRegister\AppHost\Repair {

	use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
	use OCP\App\IAppManager;
	use OCP\Migration\IOutput;
	use OCP\Migration\IRepairStep;
	use Psr\Log\LoggerInterface;

	/**
	 * Analysis-only stub for the AppHost action-matrix seed repair step.
	 */
	class GenericInitializeActions implements IRepairStep {

		/**
		 * Construct the repair step.
		 *
		 * @param string $appId The app id.
		 * @param GenericActionAuthService $actionAuth The action authorization service.
		 * @param IAppManager $appManager The app manager.
		 * @param LoggerInterface $logger The logger.
		 */
		public function __construct(
			protected readonly string $appId,
			protected readonly GenericActionAuthService $actionAuth,
			protected readonly IAppManager $appManager,
			protected readonly LoggerInterface $logger,
		) {

		}//end __construct()

		/**
		 * Return the repair step name.
		 *
		 * @return string
		 */
		public function getName(): string {
			return 'Initialize action authorization matrix';
		}//end getName()

		/**
		 * Run the repair step.
		 *
		 * @param IOutput $output The migration output channel.
		 *
		 * @return void
		 */
		public function run(IOutput $output): void {

		}//end run()

	}//end class

}
