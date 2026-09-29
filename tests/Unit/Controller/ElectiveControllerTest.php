<?php

/**
 * ElectiveController: the learner is the session user, withdrawal is the
 * owner's, staff doors sit behind the action matrix.
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
 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
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
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who may write what, and how a refusal comes back.
 */
class ElectiveControllerTest extends TestCase {

	/**
	 * Every saveObject call: [object, rbac].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: bool}>
	 */
	private array $saves = [];

	/**
	 * The controller with its doubles.
	 *
	 * @param array<string, mixed> $params  Request parameters.
	 * @param bool                 $allowed Whether elective.manage is granted.
	 * @param string|null          $refuse  A refusal the rules listener answers, or null.
	 *
	 * @return ElectiveController
	 */
	private function controller(array $params=[], bool $allowed=true, ?string $refuse=null): ElectiveController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default=null) => ($params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.bakker');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($this->anything(), ElectiveController::ACTION);
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		$electives = $this->createMock(ElectiveService::class);
		$electives->method('offer')->willReturnCallback(static fn (string $id) => ($id === 'offer-1') ? ['id' => 'offer-1', 'tenant_id' => 't1'] : null);
		$electives->method('signUp')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'mine' => ['id' => 'mine', 'learnerId' => 'j.bakker', 'status' => 'signed-up'],
				'theirs' => ['id' => 'theirs', 'learnerId' => 't.smit', 'status' => 'signed-up'],
				default => null,
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $extend=[], $register=null, $schema=null, $uuid=null, $_rbac=true) use ($refuse) {
				if ($refuse !== null) {
					throw new \RuntimeException($refuse);
				}

				$this->saves[] = [$object, $_rbac];
				return OrEntityFactory::make($object, 'elective-sign-up');
			}
		);

		$board = $this->createMock(ElectiveBoard::class);
		$board->method('forLearner')->willReturn([['id' => 'offer-1']]);
		$board->method('roster')->willReturnCallback(static fn (string $id) => ($id === 'offer-1') ? ['id' => 'offer-1', 'lessons' => []] : null);

		return new ElectiveController($request, $session, $auth, $electives, $board, $objects);
	}//end controller()

	/**
	 * The learner is always the session user, whatever the body says.
	 *
	 * @return void
	 */
	public function testLearnerIdComesFromTheSession(): void {
		$response = $this->controller(['sessionId' => 'lesson-1', 'learnerId' => 't.smit'])->signUp('offer-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('j.bakker', $this->saves[0][0]['learnerId']);
		self::assertSame('signed-up', $this->saves[0][0]['status']);
		self::assertSame('lesson-1', $this->saves[0][0]['sessionId']);
		self::assertFalse($this->saves[0][1], 'A learner writes without RBAC, so the rules are the only rules.');
	}//end testLearnerIdComesFromTheSession()

	/**
	 * A refusal from the rules comes back as 422 with its reason.
	 *
	 * @return void
	 */
	public function testARefusalIsAnswered(): void {
		$response = $this->controller(['sessionId' => 'lesson-1'], refuse: 'This lesson is full.')->signUp('offer-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('This lesson is full.', $response->getData()['error']);
	}//end testARefusalIsAnswered()

	/**
	 * A learner withdraws their own sign-up, never another's.
	 *
	 * @return void
	 */
	public function testWithdrawOnlyYourOwn(): void {
		self::assertSame(Http::STATUS_OK, $this->controller()->withdraw('mine')->getStatus());
		self::assertSame('withdrawn', $this->saves[0][0]['status']);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->withdraw('theirs')->getStatus());
		self::assertCount(1, $this->saves);
	}//end testWithdrawOnlyYourOwn()

	/**
	 * Staff place with their own RBAC; without the action, 403.
	 *
	 * @return void
	 */
	public function testPlacingIsForStaff(): void {
		$response = $this->controller(['learnerId' => 't.smit', 'sessionId' => 'lesson-1'])->place('offer-1');
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['t.smit', 'placed', true], [$this->saves[0][0]['learnerId'], $this->saves[0][0]['status'], $this->saves[0][1]]);

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(['learnerId' => 't.smit'], allowed: false)->place('offer-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(allowed: false)->roster('offer-1')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->place('offer-1')->getStatus());
	}//end testPlacingIsForStaff()

	/**
	 * The learner's offers, the roster, and unknown offers.
	 *
	 * @return void
	 */
	public function testReads(): void {
		self::assertSame(['offers' => [['id' => 'offer-1']]], $this->controller()->mine()->getData());
		self::assertSame('offer-1', $this->controller()->roster('offer-1')->getData()['id']);
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->roster('nope')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(['sessionId' => 'x'])->signUp('nope')->getStatus());
	}//end testReads()
}//end class
