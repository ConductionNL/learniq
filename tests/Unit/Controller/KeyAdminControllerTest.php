<?php

/**
 * Learniq KeyAdminController tests.
 *
 * Runs the controller over the real KeyManagementService and a real RSA
 * keypair, with only the app config and ICrypto in memory, so "the private key
 * never leaves the server" is checked against a key that actually exists.
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
 * @spec openspec/changes/controller-test-coverage-security-critical/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\KeyAdminController;
use OCA\Learniq\Service\KeyManagementService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Tests key generation, rotation gates and status for the signing keypair.
 */
class KeyAdminControllerTest extends TestCase {
	private const TENANT = 'tenant-a';

	/**
	 * In-memory learniq app config.
	 *
	 * @var array<string, string>
	 */
	private array $appValues = [];

	/**
	 * Every private key PEM ICrypto was asked to encrypt.
	 *
	 * @var array<int, string>
	 */
	private array $privateKeys = [];

	/**
	 * Reset the in-memory stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appValues = [];
		$this->privateKeys = [];
	}//end setUp()

	/**
	 * A first key is generated, answered with 201, and no private key material.
	 *
	 * @return void
	 */
	public function testFirstKeyIsGeneratedWithoutPrivateKeyMaterial(): void {
		$response = $this->controller(params: ['tenantId' => self::TENANT])->generateKey();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['fingerprint', 'publicKey'], array_keys((array)$response->getData()));
		self::assertCount(1, $this->privateKeys, 'A real private key was generated and encrypted.');
		$this->assertNoPrivateKeyMaterial(response: $response);
	}//end testFirstKeyIsGeneratedWithoutPrivateKeyMaterial()

	/**
	 * Rotating an existing key without confirm=true is refused and rotates nothing.
	 *
	 * @return void
	 */
	public function testRotationWithoutConfirmationIsRefused(): void {
		$this->controller(params: ['tenantId' => self::TENANT])->generateKey();
		$this->appValues = array_filter($this->appValues, static fn (string $key): bool => str_starts_with($key, 'keygen.') === false, ARRAY_FILTER_USE_KEY);
		$before = $this->appValues;

		$response = $this->controller(params: ['tenantId' => self::TENANT])->generateKey();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame($before, $this->appValues, 'The stored keypair changed.');
		self::assertCount(1, $this->privateKeys, 'A second keypair was generated.');
		$this->assertNoPrivateKeyMaterial(response: $response);
	}//end testRotationWithoutConfirmationIsRefused()

	/**
	 * A confirmed rotation within 24 hours of the last one is throttled.
	 *
	 * @return void
	 */
	public function testRotationWithinTheThrottleWindowIsRefused(): void {
		$this->controller(params: ['tenantId' => self::TENANT])->generateKey();
		$before = $this->appValues;

		$response = $this->controller(params: ['tenantId' => self::TENANT, 'confirm' => 'true'])->generateKey();

		self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		self::assertSame($before, $this->appValues, 'The stored keypair changed.');
		self::assertCount(1, $this->privateKeys, 'A second keypair was generated.');
		$this->assertNoPrivateKeyMaterial(response: $response);
	}//end testRotationWithinTheThrottleWindowIsRefused()

	/**
	 * A confirmed rotation after the window replaces the key the status reports.
	 *
	 * @return void
	 */
	public function testConfirmedRotationAfterTheWindowReplacesTheActiveKey(): void {
		$first = (array)$this->controller(params: ['tenantId' => self::TENANT])->generateKey()->getData();
		$this->appValues['keygen.last_at.' . self::TENANT] = (string)(time() - 86401);

		$response = $this->controller(params: ['tenantId' => self::TENANT, 'confirm' => 'true'])->generateKey();
		$status = (array)$this->controller(params: ['tenantId' => self::TENANT])->keyStatus()->getData();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertNotSame($first['fingerprint'], $status['fingerprint']);
		self::assertSame(((array)$response->getData())['fingerprint'], $status['fingerprint']);
		$this->assertNoPrivateKeyMaterial(response: $response);
	}//end testConfirmedRotationAfterTheWindowReplacesTheActiveKey()

	/**
	 * A tenantId other than the caller's bound tenant is refused before any key work.
	 *
	 * @return void
	 */
	public function testAnotherTenantsKeyCannotBeGenerated(): void {
		$keys = $this->createMock(KeyManagementService::class);
		$keys->expects(self::never())->method('getTenantKeyStatus');
		$keys->expects(self::never())->method('generateTenantKeypair');

		$response = $this->controller(params: ['tenantId' => 'tenant-b'], keys: $keys)->generateKey();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnotherTenantsKeyCannotBeGenerated()

	/**
	 * Status reports "not configured" without a key, and only public data with one.
	 *
	 * @return void
	 */
	public function testKeyStatusReportsOnlyPublicData(): void {
		$empty = $this->controller(params: ['tenantId' => self::TENANT])->keyStatus();
		self::assertSame(['configured' => false], $empty->getData());

		$this->controller(params: ['tenantId' => self::TENANT])->generateKey();
		$status = $this->controller(params: ['tenantId' => self::TENANT])->keyStatus();

		self::assertSame(['configured', 'fingerprint', 'publicKey'], array_keys((array)$status->getData()));
		self::assertTrue(((array)$status->getData())['configured']);
		$this->assertNoPrivateKeyMaterial(response: $status);
	}//end testKeyStatusReportsOnlyPublicData()

	/**
	 * Assert a response body carries neither a PEM private key nor its ciphertext.
	 *
	 * @param JSONResponse $response The controller response.
	 *
	 * @return void
	 */
	private function assertNoPrivateKeyMaterial(JSONResponse $response): void {
		$body = (string)json_encode($response->getData());

		self::assertStringNotContainsString('PRIVATE KEY', $body);
		foreach ($this->privateKeys as $pem) {
			self::assertStringNotContainsString(base64_encode($pem), $body);
		}
	}//end assertNoPrivateKeyMaterial()

	/**
	 * Build the controller for an admin bound to TENANT.
	 *
	 * @param array<string, string> $params Request parameters.
	 * @param KeyManagementService|null $keys Key service; the real one when null.
	 *
	 * @return KeyAdminController
	 */
	private function controller(array $params, ?KeyManagementService $keys = null): KeyAdminController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appValues[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appValues[$key] = $value;
				return true;
			}
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(
			function (string $plain): string {
				$this->privateKeys[] = $plain;
				return 'enc:' . base64_encode($plain);
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $appName, string $key, mixed $default = ''): mixed => ($key === 'tenant_id' ? self::TENANT : $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new KeyAdminController(
			request: $request,
			keyManagementService: ($keys ?? new KeyManagementService($appConfig, $crypto)),
			appConfig: $appConfig,
			config: $config,
			userSession: $userSession,
		);
	}//end controller()
}//end class
