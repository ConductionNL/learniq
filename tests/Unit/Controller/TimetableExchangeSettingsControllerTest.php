<?php

/**
 * Learniq TimetableExchangeSettingsController unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/timetabling/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\TimetableExchangeSettingsController;
use OCA\Learniq\Service\TimetableExchangeSettings;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The admin page's timetable and SWV exchange section.
 */
class TimetableExchangeSettingsControllerTest extends TestCase {

	/**
	 * A controller over an in-memory app config and the posted body.
	 *
	 * @param array<string, mixed> $params The posted body.
	 *
	 * @return TimetableExchangeSettingsController
	 */
	private function controller(array $params=[]): TimetableExchangeSettingsController {
		$values    = new \ArrayObject();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($values[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($values): bool {
				$values[$key] = $value;
				return true;
			}
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new TimetableExchangeSettingsController($request, new TimetableExchangeSettings($appConfig), $l10n);
	}//end controller()

	/**
	 * A valid save answers with what is now kept.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-saving-a-map-for-zermelo
	 */
	public function testASaveAnswersWithTheKeptSettings(): void {
		$data = $this->controller(
			params: ['groupMaps' => ['roster-zermelo' => ['4H1' => 'cohort-1']], 'swvReceiverId' => ' swv-ldos ']
		)->update()->getData();

		self::assertSame(['4H1' => 'cohort-1'], $data['groupMaps']['roster-zermelo']);
		self::assertSame('swv-ldos', $data['swvReceiverId']);
		self::assertContains('roster-untis-oneroster', $data['sources']);
	}//end testASaveAnswersWithTheKeptSettings()

	/**
	 * A bad receiver is a 400 with the reason, and nothing is kept.
	 *
	 * @return void
	 */
	public function testABadReceiverIsRefused(): void {
		$response = $this->controller(params: ['groupMaps' => [], 'swvReceiverId' => 'SWV!'])->update();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('SWV receiver', $response->getData()['error']);
	}//end testABadReceiverIsRefused()

	/**
	 * Show answers with every source, even with nothing kept.
	 *
	 * @return void
	 */
	public function testShowListsEverySource(): void {
		$data = $this->controller()->show()->getData();

		self::assertCount(4, $data['groupMaps']);
		self::assertSame('', $data['swvReceiverId']);
	}//end testShowListsEverySource()
}//end class
