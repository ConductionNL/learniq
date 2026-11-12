<?php

/**
 * Learniq ActionMatrixController tests.
 *
 * The action matrix decides who may call every ADR-023-guarded endpoint in the
 * app, so its admin API is covered at the controller: the seeded action list,
 * a round-tripping write, and a malformed write that must leave the stored
 * matrix alone.
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
 * @spec openspec/changes/archive/2026-09-29-controller-test-coverage-security-critical/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ActionMatrixController;
use OCA\Learniq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests the action-matrix admin API over an in-memory matrix store.
 */
class ActionMatrixControllerTest extends TestCase {
	/**
	 * The stored matrix the ActionAuthService double reads and writes.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $stored = [];

	/**
	 * Number of setMatrix() calls that reached the service.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * With nothing stored, every action the seed declares is listed.
	 *
	 * @return void
	 */
	public function testGetMatrixListsEverySeededActionWhenNothingIsStored(): void {
		$data = (array)$this->controller()->getMatrix()->getData();

		self::assertSame([], $data['matrix']);
		foreach (array_keys($this->seedActions()) as $action) {
			self::assertContains($action, $data['actions']);
		}

		self::assertSame(['admin', 'coordinators'], $data['groups']);
	}//end testGetMatrixListsEverySeededActionWhenNothingIsStored()

	/**
	 * A complete, well-formed matrix is stored and read back unchanged.
	 *
	 * @return void
	 */
	public function testAValidWriteRoundTrips(): void {
		$matrix = $this->completeMatrix();
		$matrix['rollover.plan'] = ['admin', 'coordinators'];

		$response = $this->controller(params: ['matrix' => $matrix])->setMatrix();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($matrix, ((array)$response->getData())['matrix']);
		self::assertSame($matrix, ((array)$this->controller()->getMatrix()->getData())['matrix']);
	}//end testAValidWriteRoundTrips()

	/**
	 * Malformed writes answer 400 and leave the stored matrix as it was.
	 *
	 * @return void
	 */
	public function testAMalformedWriteIsRejectedAndChangesNothing(): void {
		$before = $this->completeMatrix();
		$before['rollover.plan'] = ['admin', 'coordinators'];
		$this->stored = $before;

		$missingAction = $before;
		unset($missingAction['rollover.plan']);

		$nonArrayValue = $before;
		$nonArrayValue['rollover.plan'] = 'coordinators';

		$nonStringGroup = $before;
		$nonStringGroup['rollover.plan'] = ['admin', 42];

		$cases = [
			'no matrix at all' => [],
			'matrix is a string' => ['matrix' => 'everyone'],
			'an action key is missing' => ['matrix' => $missingAction],
			'a value is not a list' => ['matrix' => $nonArrayValue],
			'a group is not a string' => ['matrix' => $nonStringGroup],
		];

		foreach ($cases as $label => $params) {
			$response = $this->controller(params: $params)->setMatrix();

			self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $label);
			self::assertSame($before, $this->stored, $label . ': the stored matrix changed.');
		}

		self::assertSame(0, $this->writes, 'A malformed write reached the matrix store.');
	}//end testAMalformedWriteIsRejectedAndChangesNothing()

	/**
	 * Each refused shape answers with its own reason, checked in a fixed order.
	 *
	 * @return void
	 */
	public function testEachRefusedMatrixNamesItsReason(): void {
		$complete = $this->completeMatrix();

		$withoutPlan = $complete;
		unset($withoutPlan['rollover.plan']);

		$withoutTwo = $withoutPlan;
		unset($withoutTwo['qti.import']);

		$intKey = $complete;
		$intKey[7] = ['admin'];

		$badGroupAndMissing = $withoutPlan;
		$badGroupAndMissing['qti.export'] = ['admin', 42];

		$cases = [
			'no matrix at all' => [[], 'matrix must be an object mapping each action to a list of groups'],
			'an empty matrix' => [['matrix' => []], 'matrix must be an object mapping each action to a list of groups'],
			'matrix is a string' => [['matrix' => 'everyone'], 'matrix must be an object mapping each action to a list of groups'],
			'an action key is an int' => [['matrix' => $intKey], 'every action must map to a list of groups'],
			'a value is not a list' => [['matrix' => array_merge($complete, ['qti.export' => 'admin'])], 'every action must map to a list of groups'],
			'a group is not a string' => [['matrix' => array_merge($complete, ['qti.export' => ['admin', null]])], 'groups must be group ids: qti.export'],
			'one action is missing' => [['matrix' => $withoutPlan], 'matrix is missing actions: rollover.plan'],
			'two actions are missing' => [['matrix' => $withoutTwo], 'matrix is missing actions: qti.import, rollover.plan'],
			'shape is checked before completeness' => [['matrix' => $badGroupAndMissing], 'groups must be group ids: qti.export'],
		];

		foreach ($cases as $label => [$params, $reason]) {
			$response = $this->controller(params: $params)->setMatrix();

			self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $label);
			self::assertSame(['error' => $reason], $response->getData(), $label);
		}

		self::assertSame(0, $this->writes, 'A refused write reached the matrix store.');
	}//end testEachRefusedMatrixNamesItsReason()

	/**
	 * A complete matrix with an extra action and empty group lists is stored.
	 *
	 * @return void
	 */
	public function testACompleteMatrixWithExtrasIsAllowed(): void {
		$matrix = $this->completeMatrix();
		$matrix['qti.export'] = [];
		$matrix['custom.extra'] = ['coordinators'];

		$response = $this->controller(params: ['matrix' => $matrix])->setMatrix();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['matrix' => $matrix], $response->getData());
		self::assertSame(1, $this->writes);
	}//end testACompleteMatrixWithExtrasIsAllowed()

	/**
	 * A matrix naming every seeded action, admin-only.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function completeMatrix(): array {
		$matrix = [];
		foreach (array_keys($this->seedActions()) as $action) {
			$matrix[$action] = ['admin'];
		}

		return $matrix;
	}//end completeMatrix()

	/**
	 * The actions declared in lib/actions.seed.json.
	 *
	 * @return array<string, mixed>
	 */
	private function seedActions(): array {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/actions.seed.json'), true);
		self::assertIsArray($seed['actions'] ?? null);
		self::assertNotSame([], $seed['actions']);

		return $seed['actions'];
	}//end seedActions()

	/**
	 * Build the controller over the in-memory matrix store.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 *
	 * @return ActionMatrixController
	 */
	private function controller(array $params = []): ActionMatrixController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('getMatrix')->willReturnCallback(fn (): array => $this->stored);
		$actionAuth->method('setMatrix')->willReturnCallback(
			function (array $matrix): void {
				$this->writes++;
				$this->stored = $matrix;
			}
		);

		$groups = [];
		foreach (['admin', 'coordinators'] as $gid) {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$groups[] = $group;
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->willReturn($groups);

		return new ActionMatrixController(
			request: $request,
			actionAuth: $actionAuth,
			groupManager: $groupManager,
		);
	}//end controller()
}//end class
