<?php

/**
 * SettingsController connection-registry tests.
 *
 * A settings save sends integriq the recorded connection outcomes. That may not
 * change what the save answers, and a controller built without the reporter
 * must still save.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\AppInfo\Registrar\ServiceOverrideRegistrar;
use OCA\Learniq\Controller\SettingsController;
use OCA\Learniq\Service\ConnectionReportService;
use OCA\Learniq\Service\SettingsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * Unit tests for the connection report a settings save sends.
 *
 * @covers \OCA\Learniq\Controller\SettingsController
 */
class SettingsControllerConnectionReportTest extends TestCase {

	/**
	 * The payload an admin saves from the Configuration section.
	 *
	 * @var array<string, string>
	 */
	private const SAVE = ['register' => 'learniq'];

	/**
	 * Build the controller with a request carrying the payload.
	 *
	 * @param ConnectionReportService|null $reporter The reporter, or none.
	 *
	 * @return SettingsController
	 */
	private function controller(?ConnectionReportService $reporter): SettingsController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn(self::SAVE);

		$settingsService = $this->createMock(originalClassName: SettingsService::class);
		$settingsService->method('updateSettings')->willReturn(self::SAVE);

		return new SettingsController(
			request: $request,
			settingsService: $settingsService,
			connectionReports: $reporter,
		);
	}//end controller()

	/**
	 * A save sends the recorded outcomes once, after the write.
	 *
	 * @return void
	 */
	public function testASaveReportsTheRecordedOutcomes(): void {
		$reporter = $this->createMock(originalClassName: ConnectionReportService::class);
		$reporter->expects($this->once())->method('reportObservations');
		$reporter->expects($this->never())->method('observe');

		$response = $this->controller(reporter: $reporter)->update();

		$this->assertSame(expected: ['success' => true, 'config' => self::SAVE], actual: $response->getData());
	}//end testASaveReportsTheRecordedOutcomes()

	/**
	 * The save answers the same whether or not anything was reported.
	 *
	 * @return void
	 */
	public function testASaveWithoutTheReporterAnswersTheSame(): void {
		$withReporter = $this->controller(reporter: $this->createMock(originalClassName: ConnectionReportService::class))->update();
		$withoutReporter = $this->controller(reporter: null)->update();

		$this->assertSame(expected: $withReporter->getData(), actual: $withoutReporter->getData());
		$this->assertSame(expected: $withReporter->getStatus(), actual: $withoutReporter->getStatus());
	}//end testASaveWithoutTheReporterAnswersTheSame()

	/**
	 * The container builds the controller with the reporter.
	 *
	 * The app registers its own factory for this controller. A factory that
	 * leaves the reporter out builds a controller that saves fine and never
	 * reports, and nothing else would notice.
	 *
	 * @return void
	 */
	public function testTheRegisteredFactoryPassesTheReporter(): void {
		$factories = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new ServiceOverrideRegistrar())->register(context: $context, appId: 'learniq');
		$this->assertArrayHasKey(key: SettingsController::class, array: $factories);

		$reporter = $this->createMock(originalClassName: ConnectionReportService::class);
		$bindings = [
			'OCP\\IRequest' => $this->createMock(originalClassName: IRequest::class),
			SettingsService::class => $this->createMock(originalClassName: SettingsService::class),
			ConnectionReportService::class => $reporter,
		];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $bindings[$id]);

		$controller = $factories[SettingsController::class]($container);

		$property = new ReflectionProperty(SettingsController::class, 'connectionReports');
		$this->assertSame(expected: $reporter, actual: $property->getValue($controller));
	}//end testTheRegisteredFactoryPassesTheReporter()
}//end class
