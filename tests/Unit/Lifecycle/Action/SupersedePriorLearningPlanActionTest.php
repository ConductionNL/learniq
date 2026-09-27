<?php

/**
 * Unit tests for SupersedePriorLearningPlanAction and the guard it was moved out of.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/learning-plan/spec.md#requirement-append-on-version-with-immutable-prior-versions
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use OCA\Learniq\Lifecycle\Action\SupersedePriorLearningPlanAction;
use OCA\Learniq\Lifecycle\LearningPlanSignatureGuard;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

/**
 * Superseding the prior plan version used to happen inside LearningPlanSignatureGuard.
 * A guard only authorises (learniq#983), so the write is now the `activate`
 * transition's action, which OpenRegister runs only after the guard allowed.
 *
 * @spec openspec/specs/learning-plan/spec.md#requirement-append-on-version-with-immutable-prior-versions
 */
class SupersedePriorLearningPlanActionTest extends TestCase {

	use GuardVerdicts;

	/**
	 * The action supersedes the version the plan names and returns the plan unchanged.
	 *
	 * @return void
	 */
	public function testSupersedesThePriorVersion(): void {
		$engine = $this->createMock(TransitionEngine::class);
		$engine->expects(self::once())->method('transition')->with('plan-v1', 'supersede');

		$plan = ['id' => 'plan-v2', 'supersedesId' => 'plan-v1', 'lifecycle' => 'active'];
		$result = (new SupersedePriorLearningPlanAction($engine))->execute($plan, ['lifecycle' => 'draft'], [], 'x');

		self::assertSame($plan, $result);
	}//end testSupersedesThePriorVersion()

	/**
	 * A first version supersedes nothing.
	 *
	 * @return void
	 */
	public function testFirstVersionSupersedesNothing(): void {
		$engine = $this->createMock(TransitionEngine::class);
		$engine->expects(self::never())->method('transition');

		$plan = ['id' => 'plan-v1', 'lifecycle' => 'active'];
		self::assertSame($plan, (new SupersedePriorLearningPlanAction($engine))->execute($plan, [], [], 'x'));
	}//end testFirstVersionSupersedesNothing()

	/**
	 * A failed supersede fails the activation loudly instead of leaving two active versions.
	 *
	 * @return void
	 */
	public function testFailedSupersedeThrows(): void {
		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willThrowException(new RuntimeException('Transition "supersede" is not allowed from current state "closed".'));

		$this->expectException(RuntimeException::class);
		(new SupersedePriorLearningPlanAction($engine))->execute(['id' => 'plan-v2', 'supersedesId' => 'plan-v1'], [], [], 'x');
	}//end testFailedSupersedeThrows()

	/**
	 * A refused signature supersedes nothing: the guard denies and can not reach the transition engine.
	 *
	 * @return void
	 */
	public function testRefusedSignatureSupersedesNothing(): void {
		$constructor = (new ReflectionClass(LearningPlanSignatureGuard::class))->getConstructor();
		$types = array_map(static fn ($p): string => (string)$p->getType(), $constructor->getParameters());
		self::assertNotContains(TransitionEngine::class, $types, 'The guard must not be able to transition anything.');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config): array {
				if ($config['schema'] === 'learning-plan-template') {
					return [['id' => 'tpl-1', 'requiredSignerRoles' => ['mentor', 'parent']]];
				}

				// No signatures at all.
				return [];
			}
		);

		$guard = new LearningPlanSignatureGuard($objectService, new NullLogger());
		$plan = [
			'id' => 'plan-v2',
			'templateId' => 'tpl-1',
			'version' => 2,
			'kind' => 'opp',
			'supersedesId' => 'plan-v1',
			'learnerId' => 'learner-1',
			'lifecycle' => 'active',
		];

		self::assertDenied($guard->check($plan, 'activate', 'mentor-1'));
	}//end testRefusedSignatureSupersedesNothing()

	/**
	 * The register runs the action on `activate`, so an allowed activation still supersedes.
	 *
	 * @return void
	 */
	public function testActivateDeclaresTheAction(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/learniq_register.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);
		$activate = $register['components']['schemas']['LearningPlan']['x-openregister-lifecycle']['transitions']['activate'];
		$actions = array_column(($activate['actions'] ?? []), 'action');

		self::assertContains(SupersedePriorLearningPlanAction::class, $actions);
		self::assertSame(LearningPlanSignatureGuard::class, $activate['requires']);
	}//end testActivateDeclaresTheAction()
}//end class
