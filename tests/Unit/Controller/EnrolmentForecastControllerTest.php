<?php

/**
 * EnrolmentForecastController: staff only, the scenario read as the caller,
 * the result stored on it, and a refusal named.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Learniq\Controller\EnrolmentForecastController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\EnrolmentForecastService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Compute and store, or refuse.
 */
class EnrolmentForecastControllerTest extends TestCase {

	/**
	 * What was saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The controller with its doubles.
	 *
	 * @param EnrolmentForecastService $service The service double.
	 * @param bool                     $allowed Whether the action is granted.
	 *
	 * @return EnrolmentForecastController
	 */
	private function controller(EnrolmentForecastService $service, bool $allowed=true): EnrolmentForecastController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($this->anything(), EnrolmentForecastController::ACTION);
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static fn ($id) => ($id === 'scenario-1') ? OrEntityFactory::make(['id' => 'scenario-1', 'name' => 'Basis', 'targetYear' => '2027-2028'], 'enrolment-forecast') : null
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object) {
				$this->saves[] = $object;
				return OrEntityFactory::make($object, 'enrolment-forecast');
			}
		);

		return new EnrolmentForecastController($this->createMock(IRequest::class), $session, $auth, $service, $objects);
	}//end controller()

	/**
	 * The result is answered and stored on the scenario.
	 *
	 * @return void
	 */
	public function testComputeStoresTheResult(): void {
		$service = $this->createMock(EnrolmentForecastService::class);
		$service->method('compute')->willReturn(['programmeYears' => [], 'subjects' => []]);

		$response = $this->controller($service)->compute('scenario-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['programmeYears' => [], 'subjects' => []], $this->saves[0]['result']);
		self::assertSame('Basis', $this->saves[0]['name']);
	}//end testComputeStoresTheResult()

	/**
	 * Rates that do not add up are a 400 with the reason, and nothing is stored.
	 *
	 * @return void
	 */
	public function testRefusalIsNamed(): void {
		$service = $this->createMock(EnrolmentForecastService::class);
		$service->method('compute')->willThrowException(new InvalidArgumentException('The rates for vwo 5 add up to 1.1, not 1.'));

		$response = $this->controller($service)->compute('scenario-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('vwo 5', $response->getData()['error']);
		self::assertSame([], $this->saves);
	}//end testRefusalIsNamed()

	/**
	 * Without the action 403; an unseen scenario 404.
	 *
	 * @return void
	 */
	public function testAccess(): void {
		$service = $this->createMock(EnrolmentForecastService::class);
		$service->expects($this->never())->method('compute');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($service, false)->compute('scenario-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller($service)->compute('nope')->getStatus());
	}//end testAccess()
}//end class
