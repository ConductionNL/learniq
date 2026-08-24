<?php

/**
 * SettingsController write-path unit tests.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\SettingsController;
use OCA\Learniq\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The canonical AppHost dialect routes `PUT /api/settings` to
 * `settings#update` and keeps `POST /api/settings` (`settings#create`) as a
 * legacy alias. Learniq ships its own SettingsController, so no generic is
 * aliased in to cover either verb.
 *
 * These tests assert the ITEM — that the write actually reaches
 * `SettingsService::updateSettings()` carrying the request's own parameters,
 * and that the response envelope carries the service's result. A test that
 * only checked for a JSONResponse, or only for `success === true`, would pass
 * against a controller that silently wrote nothing.
 *
 * @covers \OCA\Learniq\Controller\SettingsController
 */
class SettingsControllerWriteTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock SettingsService.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * The controller under test.
	 *
	 * @var SettingsController
	 */
	private SettingsController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);

		$this->controller = new SettingsController(
			request: $this->request,
			settingsService: $this->settingsService,
		);

	}//end setUp()

	/**
	 * PUT /api/settings must persist the request parameters and return the
	 * config the service actually stored.
	 *
	 * @return void
	 */
	public function testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig(): void {
		$submitted = ['register' => 'new-uuid'];
		$stored = [
			'register' => 'new-uuid',
			'openregisters' => true,
			'isAdmin' => true,
		];

		$this->request->expects($this->once())
			->method('getParams')
			->willReturn($submitted);

		// The ITEM: the write reaches the service, with the submitted params.
		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller->update();

		$this->assertSame(
			[
				'success' => true,
				'config' => $stored,
			],
			$response->getData(),
			'update() must return the config the service stored, not the raw submission'
		);

	}//end testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig()

	/**
	 * POST /api/settings is the legacy alias and must write identically.
	 *
	 * Both of Learniq's own frontend writers still POST here, so the alias
	 * staying a real write — not an empty success — is load-bearing.
	 *
	 * @return void
	 */
	public function testCreateDelegatesToUpdateAndStillWrites(): void {
		$submitted = ['default_register' => 'learniq'];
		$stored = [
			'default_register' => 'learniq',
			'openregisters' => true,
			'isAdmin' => true,
		];

		$this->request->expects($this->once())
			->method('getParams')
			->willReturn($submitted);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller->create();

		$this->assertSame(
			[
				'success' => true,
				'config' => $stored,
			],
			$response->getData(),
			'create() must produce the same written result as update()'
		);

	}//end testCreateDelegatesToUpdateAndStillWrites()

	/**
	 * The two verbs must be behaviourally indistinguishable.
	 *
	 * Asserted by driving both against the same stubbed service and comparing
	 * the payloads, rather than by inspecting `create()`'s source — a source
	 * check would not notice a future divergence introduced inside `update()`.
	 *
	 * @return void
	 */
	public function testCreateAndUpdateProduceIdenticalPayloads(): void {
		$submitted = ['register' => 'shared-uuid'];
		$stored = ['register' => 'shared-uuid'];

		$this->request->method('getParams')->willReturn($submitted);

		$this->settingsService->expects($this->exactly(2))
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$viaUpdate = $this->controller->update()->getData();
		$viaCreate = $this->controller->create()->getData();

		$this->assertSame(
			$viaUpdate,
			$viaCreate,
			'create() is a legacy alias for update() — the payloads must not diverge'
		);

	}//end testCreateAndUpdateProduceIdenticalPayloads()

	/**
	 * An empty submission must still be a real round-trip: the service is
	 * called (it decides which keys are managed) and the refreshed config is
	 * returned.
	 *
	 * This pins the "no silent empty-success" half of the contract.
	 *
	 * @return void
	 */
	public function testUpdateWithNoManagedKeysStillReturnsTheRefreshedConfig(): void {
		$stored = [
			'register' => 'unchanged-uuid',
			'openregisters' => true,
		];

		$this->request->expects($this->once())
			->method('getParams')
			->willReturn([]);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with([])
			->willReturn($stored);

		$response = $this->controller->update();

		$this->assertSame(
			[
				'success' => true,
				'config' => $stored,
			],
			$response->getData()
		);

	}//end testUpdateWithNoManagedKeysStillReturnsTheRefreshedConfig()

}//end class
