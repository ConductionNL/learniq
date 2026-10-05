<?php

/**
 * Which structure an instance shows, and that both halves agree on the words.
 *
 * The default is the behaviour here: an instance that has never set the key is
 * every instance on the day this ships. So the tests ask what an unset key
 * reads as, and what the page controller hands the frontend, rather than only
 * what a stored value turns into.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Settings
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
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Settings;

use OCA\Learniq\Controller\PageController;
use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Service\LoadedExampleSets;
use OCA\Learniq\Service\Settings\MenuStructure;
use OCA\Learniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the structure setting.
 */
final class MenuStructureTest extends TestCase {
	/**
	 * An app config that keeps what it is given, like the real one.
	 *
	 * @param array<string, string> $stored The stored values, by `app/key`. Written to by the fake.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(array &$stored): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$app . '/' . $key] = $value;

				return true;
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$app . '/' . $key] ?? $default);
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * What the page controller hands the frontend under the structure key.
	 *
	 * @param ContainerInterface $container The container the controller resolves from.
	 *
	 * @return mixed The provided value, or null when the key was never provided.
	 */
	private function providedBy(ContainerInterface $container): mixed {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$provided     = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			static function (string $key, mixed $value) use (&$provided): void {
				$provided[$key] = $value;
			}
		);

		$controller = new PageController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			initialState: $initialState,
			dashboardRoleSvc: $this->createMock(DashboardRoleService::class),
			container: $container,
			loadedSets: new LoadedExampleSets($this->createMock(IAppConfig::class)),
		);
		$controller->index();

		return ($provided[MenuStructure::KEY] ?? null);
	}//end providedBy()

	/**
	 * A container that hands out the structure setting and nothing else.
	 *
	 * @param IAppConfig $appConfig The app config behind the setting.
	 *
	 * @return ContainerInterface
	 */
	private function containerWith(IAppConfig $appConfig): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($appConfig): object {
				if ($id === MenuStructure::class) {
					return new MenuStructure($appConfig);
				}

				throw new RuntimeException('not in this test: ' . $id);
			}
		);

		return $container;
	}//end containerWith()

	/**
	 * An unset key, an empty string and a typing mistake all read as simple.
	 *
	 * @return void
	 */
	public function testAnythingThatIsNotFullReadsAsSimple(): void {
		foreach (['', 'simple', 'Simple', 'ful', 'uitgebreid', 'yes', '1'] as $stored) {
			$this->assertSame(
				MenuStructure::SIMPLE,
				MenuStructure::normalise($stored),
				sprintf("'%s' should read as the simple structure.", $stored),
			);
		}
	}//end testAnythingThatIsNotFullReadsAsSimple()

	/**
	 * The word full, however an operator types it into occ, brings the full one back.
	 *
	 * @return void
	 */
	public function testTheWordFullBringsTheFullStructureBack(): void {
		foreach (['full', 'FULL', ' full '] as $stored) {
			$this->assertSame(MenuStructure::FULL, MenuStructure::normalise($stored));
		}
	}//end testTheWordFullBringsTheFullStructureBack()

	/**
	 * An instance that never set the key gets the simple structure on the page.
	 *
	 * The fake app config hands the caller's default back, the way an
	 * instance that never set the key does, and records what was asked.
	 *
	 * @return void
	 */
	public function testAnInstanceThatNeverSetTheKeyShowsTheSimpleStructure(): void {
		$asked     = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$asked): string {
				$asked[] = $app . '/' . $key;

				return $default;
			}
		);

		$this->assertSame(MenuStructure::SIMPLE, $this->providedBy($this->containerWith($appConfig)));
		$this->assertSame(['learniq/' . MenuStructure::KEY], $asked);
	}//end testAnInstanceThatNeverSetTheKeyShowsTheSimpleStructure()

	/**
	 * A stored `full` reaches the page as `full`.
	 *
	 * @return void
	 */
	public function testAStoredFullReachesThePage(): void {
		$stored = ['learniq/' . MenuStructure::KEY => 'full'];

		$this->assertSame(MenuStructure::FULL, $this->providedBy($this->containerWith($this->appConfig($stored))));
	}//end testAStoredFullReachesThePage()

	/**
	 * A container that cannot resolve anything still gets a page, on the default.
	 *
	 * @return void
	 */
	public function testAFailingContainerFallsBackToTheSimpleStructure(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('nothing resolves'));

		$this->assertSame(MenuStructure::SIMPLE, $this->providedBy($container));
	}//end testAFailingContainerFallsBackToTheSimpleStructure()

	/**
	 * The settings write stores the key, and hands the stored word back.
	 *
	 * `SettingsService::updateSettings()` writes only the keys on its own list
	 * and answers success for the rest, so a key missing there is a save that
	 * reports done and changes nothing. The list is private, so this goes
	 * through the write itself: the fake app config keeps what it is given,
	 * and the test reads back what the admin section reads back.
	 *
	 * @return void
	 */
	public function testTheSettingsWriteStoresTheKey(): void {
		$stored  = [];
		$service = new SettingsService(
			$this->appConfig($stored),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame('', $service->getSettings()[MenuStructure::KEY], 'An unset key reads as an empty string.');

		$config = $service->updateSettings([MenuStructure::KEY => MenuStructure::FULL]);

		$this->assertSame(MenuStructure::FULL, ($stored['learniq/' . MenuStructure::KEY] ?? null));
		$this->assertSame(MenuStructure::FULL, $config[MenuStructure::KEY]);

		// The control: a key that is not on the list is dropped, which is the
		// failure the assertion above would otherwise not be able to see.
		$service->updateSettings(['no_such_setting' => 'x']);
		$this->assertArrayNotHasKey('learniq/no_such_setting', $stored);
	}//end testTheSettingsWriteStoresTheKey()

	/**
	 * The PHP half and the JavaScript half use the same key and the same words.
	 *
	 * @return void
	 */
	public function testBothHalvesSpellTheSettingTheSameWay(): void {
		$source = file_get_contents(__DIR__ . '/../../../../src/utils/structureProfile.js');
		$this->assertIsString($source);

		foreach ([
			'STRUCTURE_SETTING' => MenuStructure::KEY,
			'STRUCTURE_SIMPLE'  => MenuStructure::SIMPLE,
			'STRUCTURE_FULL'    => MenuStructure::FULL,
		] as $constant => $expected) {
			$matched = preg_match('/export const ' . $constant . " = '([^']+)'/", $source, $matches);
			$this->assertSame(1, $matched, sprintf('structureProfile.js no longer declares %s.', $constant));
			$this->assertSame($expected, $matches[1], sprintf('%s differs between PHP and JavaScript.', $constant));
		}
	}//end testBothHalvesSpellTheSettingTheSameWay()
}//end class
