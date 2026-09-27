<?php

/**
 * The writes the lifecycle guards used to make now reach the saved object.
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * These guards wrote fields into a mutable transition payload. OpenRegister
 * calls guards by value, so the writes moved into transition actions
 * (learniq#983). An action only runs when the transition declares it, and a
 * payload key the guard reads is only accepted when the transition declares it
 * in `inputs` (TransitionEngine::resolveTransitionInputs() answers 422 for any
 * other key). This test holds the register to both, from the caller's side.
 */
class LifecycleWriteActionsTest extends TestCase {

	/**
	 * Transition => the action classes it must declare (with their actionParameters) and the inputs it must accept.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, array<string, string>>, 3: list<string>}>
	 */
	public static function transitions(): array {
		return [
			'Attestation.sign' => [
				'Attestation',
				'sign',
				['OCA\\Learniq\\Lifecycle\\Action\\TenantSignatureAction' => []],
				[],
			],
			'BsaWarning.issue' => [
				'BsaWarning',
				'issue',
				['OCA\\Learniq\\Lifecycle\\Action\\TenantSignatureAction' => []],
				[],
			],
			'BsaDecision.decide' => [
				'BsaDecision',
				'decide',
				['OCA\\Learniq\\Lifecycle\\Action\\TenantSignatureAction' => []],
				[],
			],
			'ExamAccommodation.approve' => [
				'ExamAccommodation',
				'approve',
				['OCA\\Learniq\\Lifecycle\\Action\\StampTransitionActorAction' => ['actorField' => 'approvedBy']],
				[],
			],
			'ExternalTrainingRecord.verify' => [
				'ExternalTrainingRecord',
				'verify',
				[
					'OCA\\Learniq\\Lifecycle\\Action\\StampTransitionActorAction' => [
						'actorField' => 'verifiedBy',
						'timeField' => 'verifiedAt',
					],
				],
				[],
			],
			'ExchangeRejection.waive' => [
				'ExchangeRejection',
				'waive',
				[
					'OCA\\Learniq\\Lifecycle\\Action\\StampTransitionActorAction' => [
						'actorField' => 'waivedBy',
						'timeField' => 'waivedAt',
					],
				],
				['waiveReason'],
			],
			'FraudCase.decide' => [
				'FraudCase',
				'decide',
				['OCA\\Learniq\\Lifecycle\\Action\\FraudCaseAppealDeadlineAction' => []],
				['verdict', 'decisionRationale', 'sanctionType', 'sanctionDurationMonths', 'sanctionScope'],
			],
			'ExchangeRejection.resubmit' => [
				'ExchangeRejection',
				'resubmit',
				['OCA\\Learniq\\Lifecycle\\Action\\RejectionResubmissionAction' => []],
				[],
			],
		];
	}//end transitions()

	/**
	 * Read one transition from the register.
	 *
	 * @param string $schema The schema key.
	 * @param string $action The transition name.
	 *
	 * @return array<string,mixed>
	 */
	private static function transition(string $schema, string $action): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);

		return $register['components']['schemas'][$schema]['x-openregister-lifecycle']['transitions'][$action];
	}//end transition()

	/**
	 * The transition declares each action, and each resolves to a runnable handler.
	 *
	 * @param string       $schema  The schema key.
	 * @param string       $action  The transition name.
	 * @param array<string, array<string, string>> $actions Action class => the actionParameters it must be declared with.
	 * @param list<string> $inputs  The input fields the transition must accept.
	 *
	 * @return void
	 */
	#[DataProvider('transitions')]
	public function testTransitionDeclaresItsWriteActions(string $schema, string $action, array $actions, array $inputs): void {
		$declared = [];
		foreach ((self::transition(schema: $schema, action: $action)['actions'] ?? []) as $envelope) {
			$declared[(string)($envelope['action'] ?? '')] = ($envelope['actionParameters'] ?? []);
		}

		foreach ($actions as $class => $parameters) {
			self::assertArrayHasKey(key: $class, array: $declared, message: $schema . '.' . $action . ' does not declare ' . $class);
			self::assertSame(
				expected: $parameters,
				actual: $declared[$class],
				message: $schema . '.' . $action . ' declares ' . $class . ' with other parameters'
			);
			self::assertTrue(condition: class_exists($class), message: $class . ' does not exist');
			self::assertTrue(
				condition: is_subclass_of($class, LifecycleActionInterface::class),
				message: $class . ' does not implement LifecycleActionInterface'
			);
		}

		self::assertNotSame(expected: [], actual: $actions);
	}//end testTransitionDeclaresItsWriteActions()

	/**
	 * Every payload key the guard reads is a declared input of its transition.
	 *
	 * @param string       $schema  The schema key.
	 * @param string       $action  The transition name.
	 * @param array<string, array<string, string>> $actions Action class => the actionParameters it must be declared with.
	 * @param list<string> $inputs  The input fields the transition must accept.
	 *
	 * @return void
	 */
	#[DataProvider('transitions')]
	public function testTransitionAcceptsTheInputsItsGuardReads(string $schema, string $action, array $actions, array $inputs): void {
		$declared = array_map(
			static fn (array $input): string => (string)($input['field'] ?? ''),
			(self::transition(schema: $schema, action: $action)['inputs'] ?? [])
		);

		foreach ($inputs as $field) {
			self::assertContains(needle: $field, haystack: $declared, message: $schema . '.' . $action . ' does not accept input ' . $field);
		}

		self::assertIsArray(actual: $declared);
	}//end testTransitionAcceptsTheInputsItsGuardReads()

	/**
	 * recordMunicipalityFeedback is a self-loop, so its write is a listener, not
	 * an action; the feedback the caller sends must still be an accepted input.
	 *
	 * @return void
	 */
	public function testRecordMunicipalityFeedbackAcceptsTheFeedback(): void {
		$spec = self::transition(schema: 'DataExchangeJob', action: 'recordMunicipalityFeedback');
		$declared = array_map(
			static fn (array $input): string => (string)($input['field'] ?? ''),
			($spec['inputs'] ?? [])
		);

		self::assertSame(expected: $spec['from'], actual: $spec['to'], message: 'recordMunicipalityFeedback is expected to be a self-loop');
		self::assertContains(needle: 'municipalityFeedback', haystack: $declared);
	}//end testRecordMunicipalityFeedbackAcceptsTheFeedback()

	/**
	 * A guard or action declaring TRANSITION_INPUTS has each key accepted by
	 * every transition that names it, in `requires` or in `actions`.
	 *
	 * @return void
	 */
	public function testEveryDeclaredTransitionInputIsAccepted(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);

		$missing = [];
		$checked = 0;
		foreach (($register['components']['schemas'] ?? []) as $schemaKey => $schema) {
			foreach (($schema['x-openregister-lifecycle']['transitions'] ?? []) as $action => $spec) {
				$classes = array_map(
					static fn (array $envelope): string => (string)($envelope['action'] ?? ''),
					($spec['actions'] ?? [])
				);
				$classes[] = (string)($spec['requires'] ?? '');

				$accepted = array_map(
					static fn (array $input): string => (string)($input['field'] ?? ''),
					($spec['inputs'] ?? [])
				);

				foreach ($classes as $class) {
					if ($class === '' || class_exists($class) === false || defined($class . '::TRANSITION_INPUTS') === false) {
						continue;
					}

					$checked++;
					foreach (constant($class . '::TRANSITION_INPUTS') as $field) {
						if (in_array($field, $accepted, true) === false) {
							$missing[] = $schemaKey . '.' . $action . ' does not accept ' . $field . ' (read by ' . $class . ')';
						}
					}
				}
			}//end foreach
		}//end foreach

		self::assertGreaterThan(expected: 0, actual: $checked);
		self::assertSame(expected: [], actual: $missing, message: implode("\n", $missing));
	}//end testEveryDeclaredTransitionInputIsAccepted()
}//end class
