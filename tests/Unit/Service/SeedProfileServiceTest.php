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
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Portal\ExamplePortalProvisioner;
use OCA\Learniq\Service\DemoDataService;
use OCA\Learniq\Service\LoadedExampleSets;
use OCA\Learniq\Service\SeedProfileService;
use OCA\Learniq\Service\SharedCodeFilter;
use OCP\App\IAppManager;
use OCP\IAppConfig;
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
	 * @param LoadedExampleSets|null  $loaded    The loaded-set list, when a test reads it.
	 * @param ExamplePortalProvisioner|null $portals The portal provisioner, when a test asserts on it.
	 *
	 * @return SeedProfileService
	 */
	private function service(?ContainerInterface $container = null, ?LoggerInterface $logger = null, bool $generated = true, ?LoadedExampleSets $loaded = null, ?ExamplePortalProvisioner $portals = null): SeedProfileService {
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
			$demo,
			$this->passThroughFilter(),
			($loaded ?? $this->createMock(LoadedExampleSets::class)),
			($portals ?? $this->createMock(ExamplePortalProvisioner::class))
		);
	}//end service()

	/**
	 * A shared-code filter that leaves every descriptor as it is.
	 *
	 * @return SharedCodeFilter
	 */
	private function passThroughFilter(): SharedCodeFilter {
		$filter = $this->createMock(SharedCodeFilter::class);
		$filter->method('withoutCodesHeldElsewhere')->willReturnArgument(0);
		return $filter;
	}//end passThroughFilter()

	/**
	 * `none` first, the sets by their order (not their file names), then
	 * the generated set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-two-sets-on-disk
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
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-broken-file-does-not-hide-the-others
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
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-path-in-the-answer-is-refused
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
	 * @spec openspec/specs/example-sets/spec.md#scenario-loading-the-primary-school-set
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

		$portalAnswer = ['status' => 'created', 'slug' => 'wilgenboom', 'theme' => 'example-basisschool'];
		$portals = $this->getMockBuilder(ExamplePortalProvisioner::class)
			->disableOriginalConstructor()
			->onlyMethods(['provision'])
			->getMock();
		$portals->expects(self::once())->method('provision')->with('po')->willReturn($portalAnswer);

		$result = $this->service(container: $container, portals: $portals)->install('po');

		self::assertSame(['objects' => 3, 'skipped' => 0, 'profile' => 'po', 'portal' => $portalAnswer], $result);
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
		$portals = $this->getMockBuilder(ExamplePortalProvisioner::class)
			->disableOriginalConstructor()
			->onlyMethods(['provision'])
			->getMock();
		$portals->expects(self::never())->method('provision');
		self::assertSame(['objects' => 405, 'skipped' => 0, 'profile' => 'demo'], $this->service(portals: $portals)->install('demo'));

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
			$this->createMock(DemoDataService::class),
			$this->passThroughFilter(),
			$this->createMock(LoadedExampleSets::class),
			$this->createMock(ExamplePortalProvisioner::class)
		);

		$this->expectExceptionMessage('OpenRegister');
		$service->install('po');
	}//end testInstallDelegatesTheGeneratedSetAndRefusesTheRest()

	/**
	 * A remover double: records the app id and answers like OpenRegister's
	 * ConfigurationService::softDeleteAppImports().
	 *
	 * @param array<string, mixed> $summary The summary it answers with.
	 *
	 * @return object
	 */
	private static function remover(array $summary): object {
		return new class($summary) {
			/**
			 * App ids it was asked to remove.
			 *
			 * @var array<int, string>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $summary The summary to answer with.
			 */
			public function __construct(private readonly array $summary) {
			}//end __construct()

			/**
			 * Record the call and answer.
			 *
			 * @param string $appId The app id.
			 *
			 * @return array<string, mixed>
			 */
			public function softDeleteAppImports(string $appId): array {
				$this->calls[] = $appId;
				return $this->summary;
			}//end softDeleteAppImports()
		};
	}//end remover()

	/**
	 * Removing a set hands OpenRegister the set's own import app id and reports
	 * what it soft-deleted, per job.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-removing-the-company-set
	 */
	public function testRemoveSoftDeletesTheSetsRecordedImports(): void {
		$this->writeProfile('po.json', 'po', 1);
		$remover   = self::remover(
			[
				'appId'       => 'learniq.profile.po',
				'jobs'        => [['importJobId' => 'job-1', 'softDeleted' => ['a', 'b', 'c'], 'errors' => []]],
				'softDeleted' => 3,
				'errors'      => [],
			]
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ConfigurationService')->willReturn($remover);

		$result = $this->service(container: $container)->remove('po');

		self::assertSame(['learniq.profile.po'], $remover->calls);
		self::assertTrue($result['supported']);
		self::assertSame(['job-1'], $result['jobs']);
		self::assertSame(3, $result['softDeleted']);
		self::assertSame(0, $result['errors']);
		self::assertSame([], $result['failedJobs']);
	}//end testRemoveSoftDeletesTheSetsRecordedImports()

	/**
	 * Errors are counted and the jobs they belong to are named, so the admin
	 * can finish them with occ.
	 *
	 * @return void
	 */
	public function testRemoveNamesTheJobsThatDidNotFinish(): void {
		$this->writeProfile('po.json', 'po', 1);
		$remover   = self::remover(
			[
				'jobs'        => [
					['importJobId' => 'job-1', 'softDeleted' => ['a'], 'errors' => []],
					['importJobId' => 'job-2', 'softDeleted' => [], 'errors' => [['uuid' => 'b', 'error' => 'locked']]],
				],
				'softDeleted' => 1,
				'errors'      => [['importJobId' => 'job-2', 'uuid' => 'b', 'error' => 'locked']],
			]
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($remover);

		$result = $this->service(container: $container)->remove('po');

		self::assertSame(1, $result['errors']);
		self::assertSame(['job-2'], $result['failedJobs']);
	}//end testRemoveNamesTheJobsThatDidNotFinish()

	/**
	 * The generated set is recorded under learniq.demo, DemoDataService's own
	 * import id.
	 *
	 * @return void
	 */
	public function testTheGeneratedSetIsRemovedUnderItsOwnImportId(): void {
		$remover   = self::remover(['jobs' => [], 'softDeleted' => 0, 'errors' => []]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($remover);

		$this->service(container: $container)->remove('demo');

		self::assertSame(['learniq.demo'], $remover->calls);
		self::assertSame(DemoDataService::CONFIG_APP_ID, $this->service()->importAppId('demo'));
		self::assertSame('learniq.profile.corporate', $this->service()->importAppId('corporate'));
	}//end testTheGeneratedSetIsRemovedUnderItsOwnImportId()

	/**
	 * An OpenRegister from before import jobs has no softDeleteAppImports():
	 * the answer says so and nothing is called; an unknown set throws.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-an-openregister-without-the-method
	 */
	public function testRemoveIsDuckTypedAndRefusesAnUnknownSet(): void {
		$this->writeProfile('po.json', 'po', 1);
		$older     = new class {
			/**
			 * The import an older ConfigurationService still has.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(): array {
				return [];
			}//end importFromApp()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($older);

		$result = $this->service(container: $container)->remove('po');

		self::assertFalse($result['supported']);
		self::assertSame('learniq.profile.po', $result['appId']);
		self::assertSame(0, $result['softDeleted']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('"vo"');
		$this->service(container: $container)->remove('vo');
	}//end testRemoveIsDuckTypedAndRefusesAnUnknownSet()

	/**
	 * Loading a set puts it on the wizard's list with its label; a clean
	 * removal takes it off, a removal with errors leaves it on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-removing-one-of-two-loaded-sets
	 */
	public function testLoadingAndRemovingKeepTheLoadedList(): void {
		$this->writeProfile('po.json', 'po', 1);
		$stored    = new \ArrayObject(['value' => '']);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (): string => $stored['value']);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($stored): bool {
				$stored['value'] = $value;
				return true;
			}
		);
		$loaded = new LoadedExampleSets($appConfig);

		$summary = ['jobs' => [['importJobId' => 'job-1']], 'softDeleted' => 3, 'errors' => []];
		$openRegister = new class($summary) {
			/**
			 * The summary softDeleteAppImports() answers with.
			 *
			 * @var array<string, mixed>
			 */
			public array $summary;

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $summary The summary.
			 */
			public function __construct(array $summary) {
				$this->summary = $summary;
			}//end __construct()

			/**
			 * Accept the import.
			 *
			 * @param string               $appId   Config id.
			 * @param array<string, mixed> $data    Descriptor.
			 * @param string               $version App version.
			 * @param bool                 $force   Force flag.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return [];
			}//end importFromApp()

			/**
			 * Answer with the summary.
			 *
			 * @param string $appId The app id.
			 *
			 * @return array<string, mixed>
			 */
			public function softDeleteAppImports(string $appId): array {
				return $this->summary;
			}//end softDeleteAppImports()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($openRegister);
		$service = $this->service(container: $container, loaded: $loaded);

		$service->install('po');
		$service->install('demo');
		self::assertSame(
			[['id' => 'po', 'label' => 'Set po'], ['id' => 'demo', 'label' => 'Every schema, generated values']],
			$loaded->all()
		);

		$service->remove('po');
		self::assertSame(['demo'], array_column($loaded->all(), 'id'));

		$openRegister->summary = ['jobs' => [['importJobId' => 'job-2']], 'softDeleted' => 0, 'errors' => [['importJobId' => 'job-2']]];
		$service->remove('demo');
		self::assertSame(['demo'], array_column($loaded->all(), 'id'));
	}//end testLoadingAndRemovingKeepTheLoadedList()

	/**
	 * The removal list is every fixed uuid, last-loaded first.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly
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
	 * @spec openspec/specs/example-sets/spec.md#scenario-the-generated-set-is-refused
	 */
	public function testUuidsForRefusesTheGeneratedSet(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('fixed uuids');

		$this->service()->uuidsFor('demo');
	}//end testUuidsForRefusesTheGeneratedSet()
}//end class
