<?php

/**
 * Tests for ComplianceRollupController: the by-regulation figures, who may
 * read them, and the regulation assignment answering 404 for an unknown id.
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
 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-access
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ComplianceRollupController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ComplianceRollupService;
use OCA\Learniq\Service\ExternalTrainingService;
use OCA\Learniq\Service\RegulationAssignmentService;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Service\RegulationCoverageService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\ComplianceRollupController
 * @uses \OCA\Learniq\Service\ComplianceRollupService
 * @uses \OCA\Learniq\Service\RegulationCoverageService
 * @uses \OCA\Learniq\Service\RegulationAudienceResolver
 * @uses \OCA\Learniq\Service\RunningExemptions
 */
class ComplianceRollupControllerTest extends TestCase {

	/**
	 * The actions the gate was asked for.
	 *
	 * @var string[]
	 */
	private array $asked = [];

	/**
	 * The controller over the REAL roll-up service and a small store.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $allowed Whether the action gate lets the caller through.
	 * @param RegulationAssignmentService|null $assignment Assignment double.
	 *
	 * @return ComplianceRollupController
	 */
	private function controller(bool $signedIn = true, bool $allowed = true, ?RegulationAssignmentService $assignment = null): ComplianceRollupController {
		$store = [
			'learner-profile' => [
				['id' => 'p-ann', 'ncUserId' => 'ann', 'department' => 'Operations/Logistics', 'lifecycle' => 'active'],
				['id' => 'p-dee', 'ncUserId' => 'dee', 'department' => 'Finance', 'lifecycle' => 'active'],
			],
			'regulation' => [
				['id' => 'reg-avg', 'slug' => 'AVG', 'name' => 'AVG', 'audienceScope' => 'all-employees', 'lifecycle' => 'published'],
			],
		];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => OrEntityFactory::makeMany($store[$config['filters']['schema'] ?? ''] ?? [], (string)($config['filters']['schema'] ?? ''))
		);
		$objectService->method('find')->willReturnCallback(
			static function (string $id) {
				if ($id === 'reg-draft') {
					return OrEntityFactory::make(['id' => 'reg-draft', 'lifecycle' => 'draft'], 'regulation');
				}

				throw new DoesNotExistException('no such regulation');
			}
		);

		$training = $this->createMock(ExternalTrainingService::class);
		$training->method('isLearnerCovered')->willReturnCallback(static fn (string $learnerId): bool => $learnerId === 'ann');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willReturnCallback(
			function (IUser $user, string $action) use ($allowed): void {
				$this->asked[] = $action;
				if ($allowed === false) {
					throw new OCSForbiddenException('Not allowed.');
				}
			}
		);

		$rollup = new ComplianceRollupService($objectService, new RegulationAudienceResolver(), $training);

		return new ComplianceRollupController(
			$this->createMock(IRequest::class),
			$session,
			$actionAuth,
			$rollup,
			$assignment ?? $this->createMock(RegulationAssignmentService::class),
			$objectService,
			new RegulationCoverageService($rollup, new RegulationAudienceResolver())
		);
	}//end controller()

	/**
	 * The figures per rule, for everyone and for one department, behind the roll-up's own action.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-the-department-filter-narrows-the-table
	 */
	public function testTheFiguresPerRuleAndPerDepartment(): void {
		$all = $this->controller()->regulations();
		self::assertSame(Http::STATUS_OK, $all->getStatus());
		self::assertSame([2, 1, 50.0], [$all->getData()['regulations'][0]['inScope'], $all->getData()['regulations'][0]['covered'], $all->getData()['regulations'][0]['coveragePercent']]);

		$finance = $this->controller()->regulations(department: ' Finance ');
		self::assertSame([1, 0, 'red'], [$finance->getData()['regulations'][0]['inScope'], $finance->getData()['regulations'][0]['covered'], $finance->getData()['regulations'][0]['rag']]);
		self::assertSame(['compliance.department-rollup', 'compliance.department-rollup'], $this->asked);

		$departments = $this->controller()->departments();
		self::assertSame(['Finance', 'Operations', 'Operations/Logistics'], array_column($departments->getData()['departments'], 'department'));
	}//end testTheFiguresPerRuleAndPerDepartment()

	/**
	 * A learner gets 403; nobody signed in gets 401.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-a-learner-cannot-read-the-table
	 */
	public function testALearnerCannotReadTheTable(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->regulations()->getStatus());

		$this->expectException(OCSForbiddenException::class);
		$this->controller(allowed: false)->regulations();
	}//end testALearnerCannotReadTheTable()

	/**
	 * Assigning an unknown regulation is a 404, a draft one a 409; nothing is assigned.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 */
	public function testAssigningAnUnknownOrDraftRegulation(): void {
		$assignment = $this->createMock(RegulationAssignmentService::class);
		$assignment->expects(self::never())->method('assign');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(assignment: $assignment)->assignRegulation(id: 'reg-missing')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(assignment: $assignment)->assignRegulation()->getStatus());
		self::assertSame(Http::STATUS_CONFLICT, $this->controller(assignment: $assignment)->assignRegulation(id: 'reg-draft')->getStatus());
		self::assertSame(['regulation.assign', 'regulation.assign', 'regulation.assign'], $this->asked);
	}//end testAssigningAnUnknownOrDraftRegulation()
}//end class
