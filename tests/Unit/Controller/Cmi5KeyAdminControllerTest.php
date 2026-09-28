<?php

/**
 * Unit tests for Cmi5KeyAdminController.
 *
 * First generation needs no confirmation; a rotation needs confirm=true and
 * waits 24 hours after the previous generation.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#1-key-provisioning
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\Cmi5KeyAdminController;
use OCA\Learniq\Service\Cmi5LaunchTokenService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for cmi5 key provisioning.
 */
class Cmi5KeyAdminControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param array<string, string>|null $status  Current key status.
	 * @param string                     $confirm The confirm param.
	 * @param string                     $lastAt  Last generation timestamp.
	 * @param bool                       $expectGenerate Whether generateKeyPair must run.
	 *
	 * @return Cmi5KeyAdminController
	 */
	private function controller(?array $status, string $confirm, string $lastAt, bool $expectGenerate): Cmi5KeyAdminController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($confirm);

		$tokens = $this->createMock(Cmi5LaunchTokenService::class);
		$tokens->method('keyStatus')->willReturn($status);
		$tokens->expects($expectGenerate === true ? self::once() : self::never())
			->method('generateKeyPair')
			->willReturn(['fingerprint' => 'f', 'publicKey' => 'p']);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($lastAt);

		return new Cmi5KeyAdminController(request: $request, tokens: $tokens, appConfig: $appConfig);
	}//end controller()

	/**
	 * The first key needs no confirmation.
	 *
	 * @return void
	 */
	public function testFirstGenerationNeedsNoConfirmation(): void {
		$response = $this->controller(status: null, confirm: '', lastAt: '0', expectGenerate: true)->generateKey();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testFirstGenerationNeedsNoConfirmation()

	/**
	 * A rotation without confirm=true is refused.
	 *
	 * @return void
	 */
	public function testRotationNeedsConfirmation(): void {
		$existing = ['fingerprint' => 'f', 'publicKey' => 'p'];
		$response = $this->controller(status: $existing, confirm: '', lastAt: '0', expectGenerate: false)->generateKey();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testRotationNeedsConfirmation()

	/**
	 * A confirmed rotation within 24 hours is throttled; after that it runs.
	 *
	 * @return void
	 */
	public function testRotationIsThrottled(): void {
		$existing = ['fingerprint' => 'f', 'publicKey' => 'p'];
		$recent   = $this->controller(status: $existing, confirm: 'true', lastAt: (string)(time() - 60), expectGenerate: false)->generateKey();
		self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $recent->getStatus());

		$later = $this->controller(status: $existing, confirm: 'true', lastAt: (string)(time() - 90000), expectGenerate: true)->generateKey();
		self::assertSame(Http::STATUS_CREATED, $later->getStatus());
	}//end testRotationIsThrottled()

	/**
	 * Key status reports whether a key exists.
	 *
	 * @return void
	 */
	public function testKeyStatus(): void {
		self::assertSame(['configured' => false], $this->controller(status: null, confirm: '', lastAt: '0', expectGenerate: false)->keyStatus()->getData());
		self::assertSame(
			['configured' => true, 'fingerprint' => 'f', 'publicKey' => 'p'],
			$this->controller(status: ['fingerprint' => 'f', 'publicKey' => 'p'], confirm: '', lastAt: '0', expectGenerate: false)->keyStatus()->getData()
		);
	}//end testKeyStatus()
}//end class
