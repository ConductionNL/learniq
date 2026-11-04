<?php

/**
 * Controllers that check the ADR-023 action matrix keep the caller's session and matrix.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CoursePackageImportController;
use OCA\Learniq\Controller\DisplayScreenController;
use OCA\Learniq\Controller\EnrolmentForecastController;
use OCA\Learniq\Controller\ExchangeGateController;
use OCA\Learniq\Controller\ExchangeRequestController;
use OCA\Learniq\Controller\QtiImportController;
use OCA\Learniq\Controller\SessionChangeBatchController;
use OCA\Learniq\Controller\TimetableImportController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Builds each controller from its constructor signature and reads back the
 * session and the action matrix its guards use.
 *
 * @covers \OCA\Learniq\Controller\CoursePackageImportController
 * @covers \OCA\Learniq\Controller\DisplayScreenController
 * @covers \OCA\Learniq\Controller\EnrolmentForecastController
 * @covers \OCA\Learniq\Controller\ExchangeGateController
 * @covers \OCA\Learniq\Controller\ExchangeRequestController
 * @covers \OCA\Learniq\Controller\QtiImportController
 * @covers \OCA\Learniq\Controller\SessionChangeBatchController
 * @covers \OCA\Learniq\Controller\TimetableImportController
 * @uses   \OCA\Learniq\Service\CallerActions
 */
class CallerActionsWiringTest extends TestCase {

	/**
	 * The controllers.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function controllers(): array {
		$controllers = [
			CoursePackageImportController::class,
			DisplayScreenController::class,
			EnrolmentForecastController::class,
			ExchangeGateController::class,
			ExchangeRequestController::class,
			QtiImportController::class,
			SessionChangeBatchController::class,
			TimetableImportController::class,
		];

		return array_combine($controllers, array_map(static fn (string $controller): array => [$controller], $controllers));
	}//end controllers()

	/**
	 * The guards read the very session and matrix the controller was built with.
	 *
	 * @param class-string $controller The controller class.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testTheGuardsUseTheCallersSessionAndMatrix(string $controller): void {
		$session = $this->createMock(IUserSession::class);
		$matrix = $this->createMock(ActionAuthService::class);

		$arguments = [];
		foreach ((new ReflectionClass($controller))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[] = match ($type->getName()) {
				IUserSession::class => $session,
				ActionAuthService::class => $matrix,
				CallerActions::class => new CallerActions(userSession: $session, actionAuth: $matrix),
				default => (new ReflectionClass($type->getName()))->isFinal() === true
					? (new ReflectionClass($type->getName()))->newInstanceWithoutConstructor()
					: $this->createMock($type->getName()),
			};
		}

		$instance = (new ReflectionClass($controller))->newInstanceArgs($arguments);

		self::assertSame($session, (new ReflectionProperty($controller, 'userSession'))->getValue($instance));
		self::assertSame($matrix, (new ReflectionProperty($controller, 'actionAuth'))->getValue($instance));
	}//end testTheGuardsUseTheCallersSessionAndMatrix()
}//end class
