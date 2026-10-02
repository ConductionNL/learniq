<?php

/**
 * An action-matrix refusal is a 403 (live pass D1: it was a 500 page).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Middleware;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Controller\ComplianceRollupController;
use OCA\Learniq\Middleware\ActionForbiddenMiddleware;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The middleware, and that the app registers it.
 */
class ActionForbiddenMiddlewareTest extends TestCase {

	/**
	 * A refusal from the compliance controller answers 403 with the reason.
	 *
	 * @return void
	 */
	public function testARefusalIsA403(): void {
		$response = (new ActionForbiddenMiddleware())->afterException(
			$this->createStub(ComplianceRollupController::class),
			'departments',
			new OCSForbiddenException("Action 'compliance.department-rollup' not allowed for your groups")
		);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(403, $response->getStatus());
		self::assertStringContainsString('not allowed', $response->getData()['error']);
	}//end testARefusalIsA403()

	/**
	 * Any other exception, and any refusal from an OCS controller (which
	 * answers it itself), passes through.
	 *
	 * @return void
	 */
	public function testOtherExceptionsPassThrough(): void {
		$middleware = new ActionForbiddenMiddleware();
		$other = new RuntimeException('boom');
		try {
			$middleware->afterException($this->createStub(ComplianceRollupController::class), 'departments', $other);
			self::fail('rethrown expected');
		} catch (RuntimeException $caught) {
			self::assertSame($other, $caught);
		}

		$ocs = new OCSForbiddenException('no');
		$this->expectExceptionObject($ocs);
		$middleware->afterException($this->createStub(OCSController::class), 'index', $ocs);
	}//end testOtherExceptionsPassThrough()

	/**
	 * The app registers the middleware, or no controller ever uses it.
	 *
	 * @return void
	 */
	public function testTheAppRegistersIt(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);

		try {
			(new Application())->register($context);
		} catch (\Throwable $e) {
			// Bootstrap's own wiring may need a live server; the middleware
			// registration is what this test reads.
		}

		self::assertContains(ActionForbiddenMiddleware::class, $registered);
	}//end testTheAppRegistersIt()
}//end class
