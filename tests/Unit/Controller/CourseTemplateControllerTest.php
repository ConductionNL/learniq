<?php

/**
 * Learniq CourseTemplateController unit tests.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CourseTemplateController;
use OCA\Learniq\Exception\CourseTemplateRefusedException;
use OCA\Learniq\Service\Course\CourseTemplateService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Signed-in users only, refusals keep their status, and the body's strings
 * reach the service.
 */
class CourseTemplateControllerTest extends TestCase {

	/**
	 * A controller.
	 *
	 * @param CourseTemplateService $service  The service.
	 * @param bool                  $signedIn Whether a user is signed in.
	 * @param array<string, mixed>  $params   Request parameters.
	 *
	 * @return CourseTemplateController
	 */
	private function controller(CourseTemplateService $service, bool $signedIn = true, array $params = []): CourseTemplateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new CourseTemplateController(request: $request, userSession: $session, templates: $service);
	}//end controller()

	/**
	 * Without a signed-in user nothing reaches the service.
	 *
	 * @return void
	 */
	public function testAnonymousCallsAreUnauthorized(): void {
		$service = $this->createMock(CourseTemplateService::class);
		$service->expects(self::never())->method(self::anything());
		$controller = $this->controller(service: $service, signedIn: false);

		self::assertSame(401, $controller->structure(id: 'c-1')->getStatus());
		self::assertSame(401, $controller->saveAsTemplate(id: 'c-1')->getStatus());
		self::assertSame(401, $controller->instantiate(id: 'tpl-1')->getStatus());
	}//end testAnonymousCallsAreUnauthorized()

	/**
	 * The tree comes back as the service built it.
	 *
	 * @return void
	 */
	public function testStructureAnswersTheTree(): void {
		$tree = ['course' => ['id' => 'c-1'], 'modules' => []];
		$service = $this->createMock(CourseTemplateService::class);
		$service->method('structure')->with('c-1')->willReturn($tree);

		$response = $this->controller(service: $service)->structure(id: 'c-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}//end testStructureAnswersTheTree()

	/**
	 * Save as template passes name and description and answers 201.
	 *
	 * @return void
	 */
	public function testSaveAsTemplatePassesTheBody(): void {
		$service = $this->createMock(CourseTemplateService::class);
		$service->expects(self::once())->method('saveAsTemplate')
			->with('c-1', 'Biology template', '')
			->willReturn(['id' => 'tpl-1']);

		$response = $this->controller(service: $service, params: ['name' => 'Biology template', 'description' => ['not', 'a', 'string']])
			->saveAsTemplate(id: 'c-1');

		self::assertSame(201, $response->getStatus());
		self::assertSame(['id' => 'tpl-1'], $response->getData());
	}//end testSaveAsTemplatePassesTheBody()

	/**
	 * A refusal answers with its own status and reason.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatus(): void {
		$service = $this->createMock(CourseTemplateService::class);
		$service->method('instantiate')->willThrowException(
			new CourseTemplateRefusedException(status: 403, reason: 'not-allowed', message: 'You are not allowed to do this.')
		);

		$response = $this->controller(service: $service, params: ['name' => 'Biology 2027', 'language' => 'en'])->instantiate(id: 'tpl-1');

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'You are not allowed to do this.', 'reason' => 'not-allowed'], $response->getData());
	}//end testARefusalKeepsItsStatus()
}//end class
