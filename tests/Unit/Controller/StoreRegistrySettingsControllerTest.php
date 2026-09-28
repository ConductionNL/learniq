<?php

/**
 * Unit tests for StoreRegistrySettingsController: the course registry
 * connection is admin-only, the token is write-only and sensitive, and a
 * malformed address or register is refused without storing anything.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\StoreRegistrySettingsController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\Learniq\Controller\StoreRegistrySettingsController
 */
class StoreRegistrySettingsControllerTest extends TestCase {

	/**
	 * The stored app config, key => value.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Keys written as sensitive.
	 *
	 * @var array<int, string>
	 */
	private array $sensitive = [];

	/**
	 * Build the controller over an in-memory app config and request parameters.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 *
	 * @return StoreRegistrySettingsController
	 */
	private function controller(array $params=[]): StoreRegistrySettingsController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->stored[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy=false, bool $sensitive=false): bool {
				$this->stored[$key] = $value;
				if ($sensitive === true) {
					$this->sensitive[] = $key;
				}

				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->stored[$key]);
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		return new StoreRegistrySettingsController($request, $appConfig);
	}//end controller()

	/**
	 * The response never carries the token, only whether one is set.
	 *
	 * @return void
	 */
	public function testShowNeverReturnsTheToken(): void {
		$this->stored = ['registry_url' => 'https://store.example.nl', 'registry_register' => 'learniq', 'registry_token' => 'YOUR_TOKEN_HERE'];

		$data = $this->controller()->show()->getData();

		self::assertSame(['url' => 'https://store.example.nl', 'register' => 'learniq', 'tokenSet' => true], $data);
		self::assertStringNotContainsString('YOUR_TOKEN_HERE', (string)json_encode($data));
	}//end testShowNeverReturnsTheToken()

	/**
	 * Connecting stores the three keys, the token as sensitive.
	 *
	 * @return void
	 */
	public function testConnectingStoresTheTokenAsSensitive(): void {
		$response = $this->controller(['url' => ' https://store.example.nl ', 'register' => 'learniq', 'token' => 'YOUR_TOKEN_HERE'])->update();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('https://store.example.nl', $this->stored['registry_url']);
		self::assertSame('learniq', $this->stored['registry_register']);
		self::assertSame('YOUR_TOKEN_HERE', $this->stored['registry_token']);
		self::assertSame(['registry_token'], $this->sensitive);
		self::assertTrue($response->getData()['tokenSet']);
		self::assertArrayNotHasKey('token', $response->getData());
	}//end testConnectingStoresTheTokenAsSensitive()

	/**
	 * A save without a token keeps the stored one; clearToken removes it.
	 *
	 * @return void
	 */
	public function testTheTokenIsKeptUnlessCleared(): void {
		$this->stored = ['registry_token' => 'YOUR_TOKEN_HERE'];

		$this->controller(['url' => 'https://store.example.nl', 'register' => ''])->update();
		self::assertSame('YOUR_TOKEN_HERE', $this->stored['registry_token']);

		$this->controller(['url' => 'https://store.example.nl', 'token' => '   '])->update();
		self::assertSame('YOUR_TOKEN_HERE', $this->stored['registry_token'], 'a blank token is not a new token');

		$response = $this->controller(['url' => 'https://store.example.nl', 'clearToken' => 'true'])->update();
		self::assertArrayNotHasKey('registry_token', $this->stored);
		self::assertFalse($response->getData()['tokenSet']);
	}//end testTheTokenIsKeptUnlessCleared()

	/**
	 * An empty address disconnects the store.
	 *
	 * @return void
	 */
	public function testAnEmptyAddressDisconnects(): void {
		$this->stored = ['registry_url' => 'https://store.example.nl'];

		self::assertSame(Http::STATUS_OK, $this->controller(['url' => ''])->update()->getStatus());
		self::assertSame('', $this->stored['registry_url']);
	}//end testAnEmptyAddressDisconnects()

	/**
	 * Malformed input is refused and nothing is stored.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function malformed(): array {
		return [
			'not http'           => [['url' => 'ftp://store.example.nl']],
			'no scheme'          => [['url' => 'store.example.nl']],
			'credentials in url' => [['url' => 'https://user:CHANGE_ME@store.example.nl']],
			'register capitals'  => [['url' => 'https://store.example.nl', 'register' => 'Learniq']],
			'register path'      => [['url' => 'https://store.example.nl', 'register' => '../other']],
		];
	}//end malformed()

	/**
	 * @dataProvider malformed
	 *
	 * @param array<string, string> $params The request.
	 *
	 * @return void
	 */
	public function testMalformedInputIsRefused(array $params): void {
		$response = $this->controller([...$params, 'token' => 'YOUR_TOKEN_HERE'])->update();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([], $this->stored);
	}//end testMalformedInputIsRefused()

	/**
	 * Both methods are admin-only: they carry AuthorizedAdminSetting and not
	 * NoAdminRequired, so Nextcloud refuses a non-administrator before the
	 * controller runs.
	 *
	 * @return void
	 */
	public function testBothMethodsAreAdminOnly(): void {
		foreach (['show', 'update'] as $method) {
			$reflection = new ReflectionMethod(StoreRegistrySettingsController::class, $method);
			self::assertCount(1, $reflection->getAttributes(AuthorizedAdminSetting::class), $method);
			self::assertCount(0, $reflection->getAttributes(NoAdminRequired::class), $method);
		}
	}//end testBothMethodsAreAdminOnly()
}//end class
