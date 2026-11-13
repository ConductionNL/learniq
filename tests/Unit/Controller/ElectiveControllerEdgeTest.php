<?php

/**
 * ElectiveController: anonymous callers, timetable references and how a refusal's reason is read.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ElectiveController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ElectiveBoard;
use OCA\Learniq\Service\ElectiveService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\ElectiveController
 * @uses   \OCA\Learniq\Tests\Support\OrEntityFactory
 */
class ElectiveControllerEdgeTest extends TestCase {

	/**
	 * Every object saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The controller with its doubles.
	 *
	 * @param array<string, mixed> $params   Request parameters.
	 * @param bool                 $signedIn Whether a user is in the session.
	 * @param \Throwable|null      $throw    What the save throws, or null.
	 *
	 * @return ElectiveController
	 */
	private function controller(array $params=[], bool $signedIn=true, ?\Throwable $throw=null): ElectiveController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default=null) => ($params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.bakker');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$electives = $this->createMock(ElectiveService::class);
		$electives->method('offer')->willReturn(['id' => 'offer-1', 'tenant_id' => 't1']);
		$electives->method('signUp')->willReturn(['id' => 'mine', 'learnerId' => 'j.bakker', 'status' => 'signed-up']);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object) use ($throw) {
				if ($throw !== null) {
					throw $throw;
				}

				$this->saves[] = $object;
				return OrEntityFactory::make($object, 'elective-sign-up');
			}
		);

		return new ElectiveController(
			$request,
			$session,
			$this->createMock(ActionAuthService::class),
			$electives,
			$this->createMock(ElectiveBoard::class),
			$objects
		);
	}//end controller()

	/**
	 * Without a session user every door answers 401 and nothing is saved.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthorized(): void {
		$controller = $this->controller(['sessionId' => 'lesson-1'], signedIn: false);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->mine()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->signUp('offer-1')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->withdraw('mine')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->roster('offer-1')->getStatus());
		self::assertSame('Not authenticated', $controller->place('offer-1')->getData()['error']);
		self::assertSame([], $this->saves);
	}//end testAnonymousIsUnauthorized()

	/**
	 * Without a session id, a timetable reference is kept as its two string fields.
	 *
	 * @return void
	 */
	public function testATimetableReferenceIsKept(): void {
		$ref = ['sourceSystem' => 'untis', 'externalRef' => 42, 'extra' => 'dropped'];
		$response = $this->controller(['sessionId' => '', 'timetableSessionRef' => $ref])->signUp('offer-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertArrayNotHasKey('sessionId', $this->saves[0]);
		self::assertSame(['sourceSystem' => 'untis', 'externalRef' => '42'], $this->saves[0]['timetableSessionRef']);
	}//end testATimetableReferenceIsKept()

	/**
	 * A refusal carrying structured errors answers with their message.
	 *
	 * @return void
	 */
	public function testAStructuredRefusalIsReadFromItsErrors(): void {
		$refusal = new class('generic') extends \RuntimeException {
			/**
			 * The structured errors.
			 *
			 * @return array<string, string>
			 */
			public function getErrors(): array {
				return ['message' => 'The sign-up window has closed.'];
			}//end getErrors()
		};

		$response = $this->controller(['sessionId' => 'lesson-1'], throw: $refusal)->signUp('offer-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('The sign-up window has closed.', $response->getData()['error']);
	}//end testAStructuredRefusalIsReadFromItsErrors()

	/**
	 * A refusal without a message falls back to a generic sentence.
	 *
	 * @return void
	 */
	public function testAnEmptyRefusalGetsAGenericReason(): void {
		$response = $this->controller(['sessionId' => 'lesson-1'], throw: new \RuntimeException('   '))->signUp('offer-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('This sign-up could not be saved.', $response->getData()['error']);
	}//end testAnEmptyRefusalGetsAGenericReason()
}//end class
