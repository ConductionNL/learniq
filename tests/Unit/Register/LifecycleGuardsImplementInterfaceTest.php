<?php

/**
 * Every lifecycle guard the register names must be one OpenRegister can run.
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

use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * OpenRegister's LifecycleGuardRegistry::resolve() throws for any `requires`
 * class that does not implement LifecycleGuardInterface, so the transition
 * answers 422 and a direct lifecycle PATCH answers 500 (learniq#983). This test
 * walks every `requires` value in learniq_register.json and fails unless the
 * class exists and implements the interface with the exact check() signature.
 */
class LifecycleGuardsImplementInterfaceTest extends TestCase {

	/**
	 * Guards not converted yet, each owned by a follow-up PR on learniq#983.
	 * The list may only shrink: an entry that now implements the interface
	 * fails the test until it is removed here.
	 *
	 * @var list<string>
	 */
	private const PENDING = [
		// Write data inside the guard; the write moves to an action (learniq#983 part B).
		'OCA\\Learniq\\Lifecycle\\AssessmentScoringHandler',
		'OCA\\Learniq\\Service\\WalletOfferDelegationService',
		'OCA\\Learniq\\Service\\WalletClaimSyncService',
		'OCA\\Learniq\\Service\\WalletRevocationPropagationService',
		'OCA\\Learniq\\Service\\LearningRecordExportService',
		'OCA\\Learniq\\Service\\LearningRecordImportService',
		'OCA\\Learniq\\Service\\ReportCardPdfDelegationService',
		'OCA\\Learniq\\Lifecycle\\AttestationSigningGuard',
		'OCA\\Learniq\\Lifecycle\\BsaDecisionGuard',
		'OCA\\Learniq\\Lifecycle\\BsaWarningSigningGuard',
		'OCA\\Learniq\\Lifecycle\\ExamAccommodationApprovalGuard',
		'OCA\\Learniq\\Lifecycle\\ExternalTrainingVerificationGuard',
		'OCA\\Learniq\\Lifecycle\\FraudCaseDecisionGuard',
		'OCA\\Learniq\\Lifecycle\\MunicipalityFeedbackGuard',
		'OCA\\Learniq\\Lifecycle\\RejectionResubmitGuard',
		'OCA\\Learniq\\Lifecycle\\RejectionWaiveGuard',
		// Redirects the target state to `late`; gets its own transition (learniq#983 part C).
		'OCA\\Learniq\\Lifecycle\\SubmissionWindowGuard',
	];

	/**
	 * Collect every `requires` value with the transitions that name it.
	 *
	 * @return array<string, list<string>> Guard class => list of "schema.action".
	 */
	private static function requiredGuards(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);

		$guards = [];
		foreach (($register['components']['schemas'] ?? []) as $schemaKey => $schema) {
			$transitions = ($schema['x-openregister-lifecycle']['transitions'] ?? []);
			foreach ($transitions as $action => $spec) {
				$requires = ($spec['requires'] ?? null);
				if (is_string($requires) === true && $requires !== '') {
					$guards[$requires][] = $schemaKey . '.' . $action;
				}
			}
		}

		return $guards;
	}//end requiredGuards()

	/**
	 * The walk must find the register's guards at all, or every other assertion is vacuous.
	 *
	 * @return void
	 */
	public function testRegisterNamesGuards(): void {
		self::assertGreaterThanOrEqual(50, count(self::requiredGuards()));
	}//end testRegisterNamesGuards()

	/**
	 * Every guard outside PENDING resolves to a class OpenRegister can run.
	 *
	 * @return void
	 */
	public function testEveryRequiredGuardImplementsTheInterface(): void {
		$broken = [];
		foreach (self::requiredGuards() as $class => $transitions) {
			if (in_array($class, self::PENDING, true) === true) {
				continue;
			}

			$problem = self::problemWith(class: $class);
			if ($problem !== null) {
				$broken[] = sprintf('%s (%s): %s', $class, implode(', ', $transitions), $problem);
			}
		}

		self::assertSame([], $broken, "Guards OpenRegister cannot run:\n" . implode("\n", $broken));
	}//end testEveryRequiredGuardImplementsTheInterface()

	/**
	 * PENDING only shrinks: a converted or unreferenced entry must be removed.
	 *
	 * @return void
	 */
	public function testPendingListHoldsOnlyUnconvertedGuards(): void {
		$guards = self::requiredGuards();
		$stale = [];
		foreach (self::PENDING as $class) {
			if (isset($guards[$class]) === false) {
				$stale[] = $class . ': no longer named by any transition';
				continue;
			}

			if (self::problemWith(class: $class) === null) {
				$stale[] = $class . ': implements the interface now, remove it from PENDING';
			}
		}

		self::assertSame([], $stale, implode("\n", $stale));
	}//end testPendingListHoldsOnlyUnconvertedGuards()

	/**
	 * Describe why OpenRegister's registry would refuse a class, or null when it would not.
	 *
	 * @param string $class The fully qualified class name from `requires`.
	 *
	 * @return string|null The refusal reason, or null when the class is runnable.
	 */
	private static function problemWith(string $class): ?string {
		if (class_exists($class) === false) {
			return 'class does not exist';
		}

		$reflection = new ReflectionClass($class);
		if ($reflection->implementsInterface(LifecycleGuardInterface::class) === false) {
			return 'does not implement LifecycleGuardInterface';
		}

		$returnType = $reflection->getMethod('check')->getReturnType();
		if (($returnType instanceof ReflectionNamedType) === false
			|| $returnType->getName() !== 'OCA\\OpenRegister\\Lifecycle\\GuardResult'
		) {
			return 'check() does not return GuardResult';
		}

		return null;
	}//end problemWith()
}//end class
