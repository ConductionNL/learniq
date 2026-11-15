<?php

/**
 * Learniq RolloverController::proposeMapping golden tests.
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
 * @spec openspec/changes/archive/2026-06-15-school-year-rollover/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\RolloverController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\RolloverService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins who may ask for a mapping proposal, the exact cohort query, and the answer.
 */
class RolloverControllerProposeMappingTest extends TestCase {

	/**
	 * Every findAll config the controller sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The controller over the given session user, authorization and cohort rows.
	 *
	 * @param IUser|null        $user       The signed-in user, or null.
	 * @param ActionAuthService $actionAuth The action check.
	 * @param array<int, mixed> $rows       What findAll returns.
	 *
	 * @return RolloverController
	 */
	private function controller(?IUser $user, ActionAuthService $actionAuth, array $rows = []): RolloverController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->queries[] = $config;
				return $rows;
			}
		);

		$tenant = $this->createMock(CallerTenantResolver::class);
		$tenant->method('resolve')->willReturn('tenant-a');

		$rollover = $this->createMock(RolloverService::class);
		$rollover->method('proposeDefaultMapping')->willReturnCallback(
			static fn (array $fromCohorts): array => ['received' => $fromCohorts]
		);

		return new RolloverController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			actionAuth: $actionAuth,
			rolloverService: $rollover,
			objectService: $objectService,
			callerTenant: $tenant,
		);
	}//end controller()

	/**
	 * A user.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('planner-1');

		return $user;
	}//end user()

	/**
	 * Allowed: the caller's tenant scopes the exact query; entity and array rows
	 * reach the service as arrays; a cohort without an id gets no name and a
	 * cohort without a name is named by its id.
	 *
	 * @return void
	 */
	public function testAnAllowedPlannerGetsTheProposalForTheirTenant(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects(self::once())->method('requireAction')->with($this->anything(), 'rollover.plan');
		$rows = [
			['id' => 'c-1', 'name' => '2A'],
			OrEntityFactory::make(['uuid' => 'c-2'], 'cohort'),
			['name' => 'nameless id'],
		];

		$response = $this->controller(user: $this->user(), actionAuth: $actionAuth, rows: $rows)->proposeMapping(fromAcademicYear: '2025-2026');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[['filters' => ['register' => 'learniq', 'schema' => 'cohort', 'academicYear' => '2025-2026', 'tenant_id' => 'tenant-a']]],
			$this->queries
		);
		$data = $response->getData();
		self::assertSame(['c-1' => '2A', 'c-2' => 'c-2'], $data['cohortNames']);
		self::assertCount(3, $data['mappings']['received']);
		self::assertSame(['name' => 'nameless id'], $data['mappings']['received'][2]);
	}//end testAnAllowedPlannerGetsTheProposalForTheirTenant()

	/**
	 * Refused: no session is a 401, a caller without the action never reaches
	 * the query, and the year is checked only after the action.
	 *
	 * @return void
	 */
	public function testRefusedCallersReadNothing(): void {
		$unchecked = $this->createMock(ActionAuthService::class);
		$unchecked->expects(self::never())->method('requireAction');
		$response = $this->controller(user: null, actionAuth: $unchecked)->proposeMapping(fromAcademicYear: '2025-2026');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $response->getData());

		$refusing = $this->createMock(ActionAuthService::class);
		$refusing->method('requireAction')->willThrowException(new RuntimeException('not allowed'));
		foreach (['2025-2026', ''] as $year) {
			try {
				$this->controller(user: $this->user(), actionAuth: $refusing)->proposeMapping(fromAcademicYear: $year);
				self::fail('The refusal must reach the caller.');
			} catch (RuntimeException $refusal) {
				self::assertSame('not allowed', $refusal->getMessage());
			}
		}

		self::assertSame([], $this->queries);

		$response = $this->controller(user: $this->user(), actionAuth: $this->createMock(ActionAuthService::class))->proposeMapping(fromAcademicYear: '');
		self::assertSame(['error' => 'fromAcademicYear is required'], $response->getData());
		self::assertSame([], $this->queries);
	}//end testRefusedCallersReadNothing()
}//end class
