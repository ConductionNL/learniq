<?php

/**
 * Every lifecycle action the register names must be one OpenRegister can run.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use PHPUnit\Framework\TestCase;

/**
 * The writes the learniq#983 guards used to make into their context now live in
 * transition actions. OpenRegister's LifecycleActionRegistry resolves an action
 * name from the container and throws when it does not implement
 * LifecycleActionInterface, so a learniq action named in the register must be a
 * class that implements it, and each converted transition must still name its
 * action (removing one would silently drop the write again).
 */
class LifecycleActionsImplementInterfaceTest extends TestCase {

	/**
	 * Transitions whose write moved from the guard into an action (learniq#983).
	 *
	 * @var array<string, string> "Schema.action" => action class.
	 */
	private const CONVERTED = [
		'AssessmentResult.submit' => 'OCA\\Learniq\\Lifecycle\\Action\\AssessmentAutoScoreAction',
		'Credential.revoke' => 'OCA\\Learniq\\Lifecycle\\Action\\WalletRevocationPropagationAction',
		'LearningRecordExport.generate' => 'OCA\\Learniq\\Lifecycle\\Action\\LearningRecordExportGenerateAction',
		'LearningRecordImport.parse' => 'OCA\\Learniq\\Lifecycle\\Action\\LearningRecordImportParseAction',
		'LearningPlan.activate' => 'OCA\\Learniq\\Lifecycle\\Action\\SupersedePriorLearningPlanAction',
	];

	/**
	 * Collect every declared action name with the transitions that name it.
	 *
	 * @return array<string, list<string>> Action name => list of "Schema.action".
	 */
	private static function declaredActions(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);

		$actions = [];
		foreach (($register['components']['schemas'] ?? []) as $schemaKey => $schema) {
			$transitions = ($schema['x-openregister-lifecycle']['transitions'] ?? []);
			foreach ($transitions as $transition => $spec) {
				foreach (($spec['actions'] ?? []) as $envelope) {
					$name = (string)($envelope['action'] ?? '');
					$actions[$name][] = $schemaKey . '.' . $transition;
				}
			}
		}

		return $actions;
	}//end declaredActions()

	/**
	 * Every learniq class named as an action exists and implements the interface.
	 *
	 * @return void
	 */
	public function testEveryLearniqActionImplementsTheInterface(): void {
		$broken = [];
		foreach (self::declaredActions() as $name => $transitions) {
			if (str_starts_with($name, 'OCA\\Learniq\\') === false) {
				continue;
			}

			if (class_exists($name) === false) {
				$broken[] = $name . ' (' . implode(', ', $transitions) . '): class does not exist';
				continue;
			}

			if (is_subclass_of($name, LifecycleActionInterface::class) === false) {
				$broken[] = $name . ' (' . implode(', ', $transitions) . '): does not implement LifecycleActionInterface';
			}
		}

		self::assertSame([], $broken, implode("\n", $broken));
	}//end testEveryLearniqActionImplementsTheInterface()

	/**
	 * Each converted transition still declares the action that carries its write.
	 *
	 * @return void
	 */
	public function testConvertedTransitionsDeclareTheirAction(): void {
		$declared = self::declaredActions();
		$missing = [];
		foreach (self::CONVERTED as $transition => $action) {
			if (in_array($transition, ($declared[$action] ?? []), true) === false) {
				$missing[] = $transition . ' does not declare ' . $action;
			}
		}

		self::assertSame([], $missing, implode("\n", $missing));
	}//end testConvertedTransitionsDeclareTheirAction()
}//end class
