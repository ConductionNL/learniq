<?php
/**
 * Unit tests for InitializeSettings: the repair step that imports the Learniq
 * register through OpenRegister's ConfigurationService.
 *
 * The step runs over the real SettingsService; only OpenRegister (the app
 * manager and its ConfigurationService) is doubled.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\InitializeSettings;
use OCA\Learniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Learniq\Repair\InitializeSettings
 * @uses   \OCA\Learniq\Service\SettingsService
 */
class InitializeSettingsTest extends TestCase {

	/**
	 * Messages written to the repair output, as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $written = [];

	/**
	 * A repair output that records what it is told.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->written[] = ['info', $message];
		});
		$output->method('warning')->willReturnCallback(function (string $message): void {
			$this->written[] = ['warning', $message];
		});

		return $output;
	}//end repairOutput()

	/**
	 * The step over a real SettingsService.
	 *
	 * @param bool                 $installed Whether OpenRegister is installed.
	 * @param ContainerInterface   $container The container holding ConfigurationService.
	 * @param LoggerInterface|null $logger    The step's logger, a mock when null.
	 *
	 * @return InitializeSettings
	 */
	private function step(bool $installed, ContainerInterface $container, ?LoggerInterface $logger = null): InitializeSettings {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('openregister')->willReturn($installed);

		$settings = new SettingsService(
			appConfig: $this->createMock(IAppConfig::class),
			appManager: $appManager,
			container: $container,
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		return new InitializeSettings(settingsService: $settings, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end step()

	/**
	 * A container whose ConfigurationService answers the given import result.
	 *
	 * @param array<string, mixed>       $result The importFromApp() answer.
	 * @param array<int, array>|null     $calls  Receives each import call.
	 *
	 * @return ContainerInterface
	 */
	private function containerAnswering(array $result, ?array &$calls = null): ContainerInterface {
		$calls = [];
		$configuration = new class($result, $calls) {
			/**
			 * @param array<string, mixed> $result The answer.
			 * @param array<int, array>    $calls  The recorder.
			 */
			public function __construct(private array $result, private array &$calls) {
			}

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->calls[] = ['appId' => $appId, 'version' => $version, 'force' => $force];
				return $this->result;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ConfigurationService')->willReturn($configuration);

		return $container;
	}//end containerAnswering()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testName(): void {
		$container = $this->createMock(ContainerInterface::class);
		self::assertSame('Initialize Learniq register and schemas via ConfigurationService', $this->step(installed: true, container: $container)->getName());
	}//end testName()

	/**
	 * Without OpenRegister the step warns, logs and imports nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsImported(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('Learniq: OpenRegister not available, skipping register initialization');

		$this->step(installed: false, container: $container, logger: $logger)->run($this->repairOutput());

		self::assertSame(
			[
				['info', 'Initializing Learniq configuration...'],
				['warning', 'OpenRegister is not installed or enabled. Skipping auto-configuration.'],
			],
			$this->written
		);
	}//end testWithoutOpenRegisterNothingIsImported()

	/**
	 * A successful import is reported with the imported version, and the import is not forced.
	 *
	 * @return void
	 */
	public function testASuccessfulImportReportsItsVersion(): void {
		$calls = [];
		$container = $this->containerAnswering(result: ['version' => '1.2.3+def.abc'], calls: $calls);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('error');

		$this->step(installed: true, container: $container, logger: $logger)->run($this->repairOutput());

		self::assertSame(
			[
				['info', 'Initializing Learniq configuration...'],
				['info', 'Learniq configuration imported successfully (version: 1.2.3+def.abc)'],
			],
			$this->written
		);
		self::assertCount(1, $calls);
		self::assertSame('learniq', $calls[0]['appId']);
		self::assertFalse($calls[0]['force']);
		self::assertMatchesRegularExpression('/\+def\.[0-9a-f]{12}$/', $calls[0]['version']);
	}//end testASuccessfulImportReportsItsVersion()

	/**
	 * A successful import without a version is reported as version unknown.
	 *
	 * @return void
	 */
	public function testASuccessfulImportWithoutAVersionSaysUnknown(): void {
		$this->step(installed: true, container: $this->containerAnswering(result: ['imported' => true]))->run($this->repairOutput());

		self::assertSame(['info', 'Learniq configuration imported successfully (version: unknown)'], end($this->written));
	}//end testASuccessfulImportWithoutAVersionSaysUnknown()

	/**
	 * An empty import result is reported as an issue, as a warning.
	 *
	 * @return void
	 */
	public function testAnEmptyImportIsReportedAsAnIssue(): void {
		$this->step(installed: true, container: $this->containerAnswering(result: []))->run($this->repairOutput());

		self::assertSame(['warning', 'Learniq configuration import issue: Import returned an empty result.'], end($this->written));
	}//end testAnEmptyImportIsReportedAsAnIssue()

	/**
	 * A ConfigurationService that cannot be resolved is reported as an issue carrying its message.
	 *
	 * @return void
	 */
	public function testAnUnresolvableConfigurationServiceIsReportedAsAnIssue(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no configuration service'));

		$this->step(installed: true, container: $container)->run($this->repairOutput());

		self::assertSame(['warning', 'Learniq configuration import issue: no configuration service'], end($this->written));
	}//end testAnUnresolvableConfigurationServiceIsReportedAsAnIssue()
}//end class
