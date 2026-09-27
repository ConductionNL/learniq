<?php

/**
 * Learniq SeedProfileService unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\DemoDataService;
use OCA\Learniq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Listing, loading and naming the uuids of the example sets.
 */
class SeedProfileServiceTest extends TestCase {

	/**
	 * A throwaway app directory.
	 *
	 * @var string
	 */
	private string $appPath;

	/**
	 * The app manager double.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Build a temp app with an empty profiles directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appPath = sys_get_temp_dir() . '/lq-profiles-' . uniqid();
		mkdir($this->appPath . '/lib/Settings/profiles', 0777, true);

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$this->appManager->method('getAppVersion')->willReturn('1.2.3');
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
	}//end setUp()

	/**
	 * Remove the temp app.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->appPath . '/lib/Settings/profiles/*') ?: [] as $file) {
			unlink($file);
		}

		@rmdir($this->appPath . '/lib/Settings/profiles');
		@rmdir($this->appPath . '/lib/Settings');
		@rmdir($this->appPath . '/lib');
		@rmdir($this->appPath);
	}//end tearDown()

	/**
	 * Write a descriptor into the temp profiles directory.
	 *
	 * @param string $file  File name.
	 * @param string $id    Profile id.
	 * @param int    $order Wizard order.
	 *
	 * @return void
	 */
	private function writeProfile(string $file, string $id, int $order): void {
		$fixture = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/profiles/po.json'), true);
		$fixture['x-openregister']['profile']['id']      = $id;
		$fixture['x-openregister']['profile']['segment'] = $id;
		$fixture['x-openregister']['profile']['order']   = $order;
		$fixture['x-openregister']['profile']['label']   = 'Set ' . $id;
		file_put_contents($this->appPath . '/lib/Settings/profiles/' . $file, json_encode($fixture));
	}//end writeProfile()

	/**
	 * Build the service.
	 *
	 * @param ContainerInterface|null $container The container double.
	 * @param LoggerInterface|null    $logger    The logger double.
	 * @param bool                    $generated Whether the generated set ships.
	 *
	 * @return SeedProfileService
	 */
	private function service(?ContainerInterface $container = null, ?LoggerInterface $logger = null, bool $generated = true): SeedProfileService {
		$demo = $this->createMock(DemoDataService::class);
		$demo->method('isAvailable')->willReturn($generated);
		$choices = [['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => 'CloseCircleOutline']];
		if ($generated === true) {
			$choices[] = ['id' => 'demo', 'label' => 'Every schema, generated values', 'description' => 'x', 'objectCount' => 405, 'icon' => 'DatabaseOutline'];
		}

		$demo->method('listChoices')->willReturn($choices);
		$demo->method('install')->willReturn(['objects' => 405, 'registers' => 0, 'schemas' => 0]);

		return new SeedProfileService(
			$this->appManager,
			($container ?? $this->createMock(ContainerInterface::class)),
			($logger ?? $this->createMock(LoggerInterface::class)),
			$demo
		);
	}//end service()

	/**
	 * `none` first, the sets by their order (not their file names), then
	 * the generated set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-two-sets-on-disk
	 */
	public function testChoicesListNoneThenTheSetsByOrderThenTheGeneratedSet(): void {
		$this->writeProfile('a-vo.json', 'vo', 2);
		$this->writeProfile('b-po.json', 'po', 1);

		$choices = $this->service()->listChoices();

		self::assertSame(['none', 'po', 'vo', 'demo'], array_column($choices, 'id'));
		self::assertSame(3, $choices[1]['objectCount']);
		self::assertArrayNotHasKey('order', $choices[1]);
	}//end testChoicesListNoneThenTheSetsByOrderThenTheGeneratedSet()

	/**
	 * A malformed file is skipped and logged; the others stay reachable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-a-broken-file-does-not-hide-the-others
	 */
	public function testABrokenFileIsSkippedAndLogged(): void {
		$this->writeProfile('po.json', 'po', 1);
		file_put_contents($this->appPath . '/lib/Settings/profiles/vo.json', 'not json');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::atLeastOnce())->method('warning');

		$ids = array_column($this->service(logger: $logger)->listChoices(), 'id');

		self::assertSame(['none', 'po', 'demo'], $ids);
	}//end testABrokenFileIsSkippedAndLogged()

	/**
	 * Only ids a file declares are known; a path never is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-a-path-in-the-answer-is-refused
	 */
	public function testOnlyDeclaredIdsAreKnown(): void {
		$this->writeProfile('po.json', 'po', 1);
		$service = $this->service();

		self::assertTrue($service->isKnown('po'));
		self::assertTrue($service->isKnown('demo'));
		self::assertFalse($service->isKnown('none'));
		self::assertFalse($service->isKnown('../../config/config'));
		self::assertFalse($service->isKnown('vo'));
		self::assertFalse($this->service(generated: false)->isKnown('demo'));
	}//end testOnlyDeclaredIdsAreKnown()

	/**
	 * Loading hands the file to OpenRegister under its own config id and
	 * reports the number of objects the file carries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-loading-the-primary-school-set
	 */
	public function testInstallImportsTheDescriptorUnderItsOwnConfigId(): void {
		$this->writeProfile('po.json', 'po', 1);
		$importer = new class {
			/**
			 * Arguments of the last import.
			 *
			 * @var array<string, mixed>
			 */
			public array $call = [];

			/**
			 * Record the import.
			 *
			 * @param string               $appId   Config id.
			 * @param array<string, mixed> $data    Descriptor.
			 * @param string               $version App version.
			 * @param bool                 $force   Force flag.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->call = compact('appId', 'data', 'version', 'force');
				return [];
			}//end importFromApp()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ConfigurationService')->willReturn($importer);

		$result = $this->service(container: $container)->install('po');

		self::assertSame(['objects' => 3, 'profile' => 'po'], $result);
		self::assertSame('learniq.profile.po', $importer->call['appId']);
		self::assertSame('profile', $importer->call['data']['x-openregister']['type']);
		self::assertTrue($importer->call['force']);
	}//end testInstallImportsTheDescriptorUnderItsOwnConfigId()

	/**
	 * The generated set is imported by DemoDataService; an unknown set and a
	 * missing OpenRegister throw with a reason.
	 *
	 * @return void
	 */
	public function testInstallDelegatesTheGeneratedSetAndRefusesTheRest(): void {
		self::assertSame(['objects' => 405, 'profile' => 'demo'], $this->service()->install('demo'));

		try {
			$this->service()->install('vo');
			self::fail('an unknown set must throw');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('"vo"', $e->getMessage());
		}

		$this->writeProfile('po.json', 'po', 1);
		$noOr = $this->createMock(IAppManager::class);
		$noOr->method('getAppPath')->willReturn($this->appPath);
		$noOr->method('getInstalledApps')->willReturn([]);
		$service = new SeedProfileService(
			$noOr,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DemoDataService::class)
		);

		$this->expectExceptionMessage('OpenRegister');
		$service->install('po');
	}//end testInstallDelegatesTheGeneratedSetAndRefusesTheRest()

	/**
	 * The removal list is every fixed uuid, last-loaded first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly
	 */
	public function testUuidsForListsEveryUuidLastLoadedFirst(): void {
		$this->writeProfile('po.json', 'po', 1);

		self::assertSame(
			[
				'ee010003-0000-4000-8000-000000000001',
				'ee010002-0000-4000-8000-000000000001',
				'ee010001-0000-4000-8000-000000000001',
			],
			$this->service()->uuidsFor('po')
		);
	}//end testUuidsForListsEveryUuidLastLoadedFirst()

	/**
	 * The generated set has no fixed uuids, so it has no removal list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-the-generated-set-is-refused
	 */
	public function testUuidsForRefusesTheGeneratedSet(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('fixed uuids');

		$this->service()->uuidsFor('demo');
	}//end testUuidsForRefusesTheGeneratedSet()
}//end class
