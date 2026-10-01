<?php

/**
 * Learniq AccessibilityStatementPublishGuard: a failing criterion needs a limitation.
 *
 * governance-wcag-evidence-report task 1.2. A statement whose conformance
 * table records a `fail` may publish only when that failure links a known
 * limitation of the same statement, for the same criterion, that is not
 * fixed. The OpenRegister reads answer with real ObjectEntity instances (the
 * stub that mirrors OpenRegister's class), which is also what the older
 * fully-compliant check reads: it indexed them as arrays.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AccessibilityStatementPublishGuard;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The fail-needs-a-limitation rule of the publish guard.
 *
 * @covers \OCA\Learniq\Lifecycle\AccessibilityStatementPublishGuard
 * @uses \OCA\Learniq\Service\Accessibility\WcagCriteriaCatalogue
 */
class AccessibilityStatementFailureGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * A statement with complete evaluation evidence.
	 *
	 * @param string $status The conformance status.
	 *
	 * @return array<string,mixed>
	 */
	private static function statement(string $status = 'partially-compliant'): array {
		return [
			'id' => 'statement-1',
			'status' => $status,
			'evaluationMethod' => 'expert-review',
			'evaluationDate' => '2026-09-01',
			'feedbackContact' => 'toegankelijkheid@school.example',
			'tenant_id' => 'tenant-a',
			'lifecycle' => 'published',
		];
	}//end statement()

	/**
	 * An ObjectEntity as OpenRegister's findAll() returns it.
	 *
	 * @param string $uuid The object uuid.
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		return (new ObjectEntity())->hydrateObject(array_merge($data, ['@self' => ['uuid' => $uuid]]));
	}//end entity()

	/**
	 * A guard over an ObjectService that answers per schema.
	 *
	 * @param array<int,ObjectEntity> $results The criterion-result rows.
	 * @param array<int,ObjectEntity> $limitations The limitation rows.
	 * @param array<int,array<string,mixed>> $configs Collects every findAll() config.
	 *
	 * @return AccessibilityStatementPublishGuard
	 */
	private function guard(array $results, array $limitations, array &$configs = []): AccessibilityStatementPublishGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($results, $limitations, &$configs): array {
				$configs[] = $config;
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'accessibility-criterion-result') {
					return $results;
				}

				if ($schema === 'accessibility-limitation') {
					return $limitations;
				}

				return [];
			}
		);

		return new AccessibilityStatementPublishGuard($objectService, $this->createMock(LoggerInterface::class));
	}//end guard()

	/**
	 * A failure with no limitation is refused, and the reason names the criterion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-failure-needs-a-limitation
	 */
	public function testAFailureWithoutALimitationIsRefusedWithTheCriterion(): void {
		$configs = [];
		$guard = $this->guard(
			results: [self::entity('result-1', ['accessibilityStatementId' => 'statement-1', 'wcagCriterion' => '1.4.3', 'result' => 'fail'])],
			limitations: [],
			configs: $configs
		);

		$verdict = $guard->check(self::statement(), 'publish', 'officer');

		self::assertDenied($verdict);
		self::assertStringContainsString('1.4.3', (string)$verdict->getMessage());
		self::assertSame(
			['accessibilityStatementId' => 'statement-1', 'tenant_id' => 'tenant-a', 'result' => 'fail', 'register' => 'learniq', 'schema' => 'accessibility-criterion-result'],
			$configs[0]['filters']
		);
	}//end testAFailureWithoutALimitationIsRefusedWithTheCriterion()

	/**
	 * A failure linked to an open limitation for the same criterion may publish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-failure-needs-a-limitation
	 */
	public function testAFailureWithItsLimitationPublishes(): void {
		$guard = $this->guard(
			results: [self::entity('result-1', ['accessibilityStatementId' => 'statement-1', 'wcagCriterion' => '1.4.3', 'result' => 'fail', 'limitationId' => 'limitation-1'])],
			limitations: [self::entity('limitation-1', ['accessibilityStatementId' => 'statement-1', 'wcagCriterion' => '1.4.3 Contrast (Minimum)', 'lifecycle' => 'open'])]
		);

		self::assertAllowed($guard->check(self::statement(), 'publish', 'officer'));
	}//end testAFailureWithItsLimitationPublishes()

	/**
	 * A limitation for another criterion, or one already fixed, does not cover a failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-failure-needs-a-limitation
	 */
	public function testAWrongOrFixedLimitationDoesNotCoverAFailure(): void {
		$failure = self::entity('result-1', ['accessibilityStatementId' => 'statement-1', 'wcagCriterion' => '1.4.3', 'result' => 'fail', 'limitationId' => 'limitation-1']);

		$otherCriterion = $this->guard(
			results: [$failure],
			limitations: [self::entity('limitation-1', ['wcagCriterion' => '2.1.1 Keyboard', 'lifecycle' => 'open'])]
		);
		self::assertDenied($otherCriterion->check(self::statement(), 'publish', 'officer'), 'another criterion');

		$fixed = $this->guard(
			results: [$failure],
			limitations: [self::entity('limitation-1', ['wcagCriterion' => '1.4.3', 'lifecycle' => 'fixed'])]
		);
		self::assertDenied($fixed->check(self::statement(), 'publish', 'officer'), 'a fixed limitation');
	}//end testAWrongOrFixedLimitationDoesNotCoverAFailure()

	/**
	 * Passing and untested criteria need nothing, and the limitation read is skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-untested-criteria-are-visible
	 */
	public function testNoFailuresPublishes(): void {
		$configs = [];
		$guard = $this->guard(results: [], limitations: [], configs: $configs);

		self::assertAllowed($guard->check(self::statement(), 'publish', 'officer'));
		self::assertCount(1, $configs, 'only the failure read runs');
	}//end testNoFailuresPublishes()

	/**
	 * The fully-compliant check reads OpenRegister's entities, not only arrays.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	 */
	public function testAnOpenLimitationEntityBlocksFullyCompliant(): void {
		$guard = $this->guard(
			results: [],
			limitations: [self::entity('limitation-1', ['accessibilityStatementId' => 'statement-1', 'wcagCriterion' => '2.1.1', 'lifecycle' => 'open'])]
		);

		self::assertDenied($guard->check(self::statement(status: 'fully-compliant'), 'publish', 'officer'));
	}//end testAnOpenLimitationEntityBlocksFullyCompliant()

	/**
	 * A statement with no id cannot be looked up, so only the evidence rules apply.
	 *
	 * @return void
	 */
	public function testAStatementWithoutAnIdSkipsTheTable(): void {
		$configs = [];
		$guard = $this->guard(results: [], limitations: [], configs: $configs);
		$statement = self::statement();
		unset($statement['id']);

		self::assertAllowed($guard->check($statement, 'publish', 'officer'));
		self::assertSame([], $configs);
	}//end testAStatementWithoutAnIdSkipsTheTable()
}//end class
