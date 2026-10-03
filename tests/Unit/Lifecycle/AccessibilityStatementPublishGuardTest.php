<?php

/**
 * Learniq AccessibilityStatementPublishGuard unit tests.
 *
 * Coverage for accessibility-conformance-statement tasks.md#2.1/#2.2: an
 * AccessibilityStatement may not publish without recorded evaluation
 * evidence, and may not publish as `fully-compliant` while an `open` or
 * `mitigated` AccessibilityLimitation still references it.
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
 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\AccessibilityStatementPublishGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AccessibilityStatementPublishGuard::check() — the
 * AccessibilityStatement `draft -> published` transition.
 */
class AccessibilityStatementPublishGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * A statement missing evaluation evidence is refused, regardless of what
	 * limitations exist.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
	 */
	public function testMissingEvaluationEvidenceRefusesPublish(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');

		$guard = new AccessibilityStatementPublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = [
				'id' => 'statement-1',
				'status' => 'partially-compliant',
				'evaluationMethod' => 'self-assessment',
				'evaluationDate' => null,
				'feedbackContact' => 'accessibility@school.example',
				'tenant_id' => 'tenant-a',
				'lifecycle' => 'published',
			];

		self::assertDenied($guard->check($object, 'publish', ''));

	}//end testMissingEvaluationEvidenceRefusesPublish()

	/**
	 * A statement missing its feedback contact is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
	 */
	public function testMissingFeedbackContactRefusesPublish(): void {
		$objectService = $this->createMock(ObjectService::class);

		$guard = new AccessibilityStatementPublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = [
				'id' => 'statement-2',
				'status' => 'partially-compliant',
				'evaluationMethod' => 'expert-review',
				'evaluationDate' => '2026-06-01',
				'feedbackContact' => '   ',
				'tenant_id' => 'tenant-a',
				'lifecycle' => 'published',
			];

		self::assertDenied($guard->check($object, 'publish', ''));

	}//end testMissingFeedbackContactRefusesPublish()

	/**
	 * A statement with complete evidence and a non-fully-compliant status
	 * publishes without needing to consult the limitations register at all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
	 */
	public function testCompleteEvidenceAllowsPublish(): void {
		// No limitation read: the status is not fully-compliant. The one read
		// left is the conformance table's failures (governance-wcag-evidence-report).
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('findAll')->willReturn([]);

		$guard = new AccessibilityStatementPublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = [
				'id' => 'statement-3',
				'status' => 'partially-compliant',
				'evaluationMethod' => 'automated-scan',
				'evaluationDate' => '2026-07-01',
				'feedbackContact' => 'accessibility@school.example',
				'tenant_id' => 'tenant-a',
				'lifecycle' => 'published',
			];

		self::assertAllowed($guard->check($object, 'publish', ''));

	}//end testCompleteEvidenceAllowsPublish()

	/**
	 * A `fully-compliant` statement with an `open` AccessibilityLimitation
	 * referencing it is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	 */
	public function testOpenLimitationBlocksFullyCompliantStatus(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[
				['id' => 'limitation-1', 'accessibilityStatementId' => 'statement-4', 'lifecycle' => 'open'],
			]
		);

		$guard = new AccessibilityStatementPublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = [
				'id' => 'statement-4',
				'status' => 'fully-compliant',
				'evaluationMethod' => 'expert-review',
				'evaluationDate' => '2026-07-01',
				'feedbackContact' => 'accessibility@school.example',
				'tenant_id' => 'tenant-a',
				'lifecycle' => 'published',
			];

		self::assertDenied($guard->check($object, 'publish', ''));

	}//end testOpenLimitationBlocksFullyCompliantStatus()

	/**
	 * A `fully-compliant` statement with a `mitigated` (not just `open`)
	 * AccessibilityLimitation referencing it is also refused — the design's
	 * deliberately stricter reading (a workaround is not compliance).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	 */
	public function testMitigatedLimitationBlocksFullyCompliantStatus(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[
				['id' => 'limitation-2', 'accessibilityStatementId' => 'statement-5', 'lifecycle' => 'mitigated'],
			]
		);

		$guard = new AccessibilityStatementPublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = [
				'id' => 'statement-5',
				'status' => 'fully-compliant',
				'evaluationMethod' => 'expert-review',
				'evaluationDate' => '2026-07-01',
				'feedbackContact' => 'accessibility@school.example',
				'tenant_id' => 'tenant-a',
				'lifecycle' => 'published',
			];

		self::assertDenied($guard->check($object, 'publish', ''));

	}//end testMitigatedLimitationBlocksFullyCompliantStatus()

	/**
	 * A `fully-compliant` statement with only `fixed` limitations (none open
	 * or mitigated) is allowed to publish.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	 */
	public function testFullyCompliantWithOnlyFixedLimitationsAllowsPublish(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[
				['id' => 'limitation-3', 'accessibilityStatementId' => 'statement-6', 'lifecycle' => 'fixed'],
			]
		);

		$guard = new AccessibilityStatementPublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = [
				'id' => 'statement-6',
				'status' => 'fully-compliant',
				'evaluationMethod' => 'expert-review',
				'evaluationDate' => '2026-07-01',
				'feedbackContact' => 'accessibility@school.example',
				'tenant_id' => 'tenant-a',
				'lifecycle' => 'published',
			];

		self::assertAllowed($guard->check($object, 'publish', ''));

	}//end testFullyCompliantWithOnlyFixedLimitationsAllowsPublish()

	/**
	 * The AccessibilityLimitation lookup is scoped to the statement's own
	 * tenant (H1 isolation).
	 *
	 * @return void
	 */
	public function testLimitationLookupIsScopedToTenant(): void {
		$calls = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->exactly(2))
			->method('findAll')
			->willReturnCallback(
				function (array $params) use (&$calls): array {
					$calls[] = $params['filters'];
					return [];
				}
			);

		$guard = new AccessibilityStatementPublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = [
				'id' => 'statement-7',
				'status' => 'fully-compliant',
				'evaluationMethod' => 'expert-review',
				'evaluationDate' => '2026-07-01',
				'feedbackContact' => 'accessibility@school.example',
				'tenant_id' => 'tenant-b',
				'lifecycle' => 'published',
			];

		self::assertAllowed($guard->check($object, 'publish', ''));

		// The limitation lookup and the criterion-result lookup both stay in the statement's tenant.
		self::assertSame(['accessibility-limitation', 'accessibility-criterion-result'], array_column($calls, 'schema'));
		foreach ($calls as $filters) {
			self::assertSame('tenant-b', $filters['tenant_id'] ?? null);
			self::assertSame('statement-7', $filters['accessibilityStatementId'] ?? null);
		}
	}//end testLimitationLookupIsScopedToTenant()
}//end class
