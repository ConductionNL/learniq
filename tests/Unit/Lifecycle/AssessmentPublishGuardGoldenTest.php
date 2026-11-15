<?php

/**
 * Golden tests for AssessmentPublishGuard.
 *
 * Pins every allowed and refused outcome of the assessment publish guard
 * (fixed items, a random-draw pool, and the AI-proctoring DPO and locality
 * gates), the exact log line per refusal, the queries, and the order in
 * which the gates are consulted.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AssessmentPublishGuard;
use OCA\Learniq\Service\AiLocalityClassifier;
use OCA\Learniq\Service\ItemPoolFilter;
use OCA\Learniq\Service\SovereigntyPolicyService;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden tests for the assessment publish guard.
 */
class AssessmentPublishGuardGoldenTest extends TestCase {

	private const DENIAL = 'This assessment has no usable item source or its AI proctoring review is not allowed, so it can not be published.';

	private const P = '[AssessmentPublishGuard] ';

	/**
	 * Every collaborator call and log line, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $trace = [];

	/**
	 * Run the guard.
	 *
	 * @param array<string,mixed> $object   The assessment.
	 * @param array<string,mixed> $world    hermiq (bool), feature (bool), groups (int), locality (array), compliant (bool).
	 *
	 * @return GuardResult
	 */
	private function runGuard(array $object, array $world = []): GuardResult {
		$world += ['hermiq' => true, 'feature' => true, 'groups' => 3, 'locality' => ['locality' => 'eu', 'verified' => true, 'evidence' => 'ev'], 'compliant' => true];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($world): array {
				$this->trace[] = ['findAll', $config];
				if ($config['filters']['schema'] === 'item') {
					return [['id' => 'item-1']];
				}

				return $world['feature'] === true ? [['slug' => 'assessment-ai-proctor-review']] : [];
			}
		);

		$pool = $this->createMock(ItemPoolFilter::class);
		$pool->method('filterAndGroupByVariant')->willReturnCallback(
			function (array $items, array $poolConfig) use ($world): array {
				$this->trace[] = ['group', $items, $poolConfig];
				return array_fill(0, $world['groups'], ['x']);
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(
			function (string $app) use ($world): bool {
				$this->trace[] = ['installed', $app];
				return $world['hermiq'];
			}
		);

		$classifier = $this->createMock(AiLocalityClassifier::class);
		$classifier->method('classifyActiveProvider')->willReturnCallback(
			function () use ($world): array {
				$this->trace[] = ['classify'];
				return $world['locality'];
			}
		);

		$policy = $this->createMock(SovereigntyPolicyService::class);
		$policy->method('isCompliant')->willReturnCallback(
			function (string $locality, bool $verified) use ($world): bool {
				$this->trace[] = ['compliant', $locality, $verified];
				return $world['compliant'];
			}
		);

		$test = $this;
		$logger = new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param AssessmentPublishGuardGoldenTest $test Recorder.
			 */
			public function __construct(private AssessmentPublishGuardGoldenTest $test) {
			}

			/**
			 * Record.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array<mixed>       $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->trace[] = ['log', (string)$level, (string)$message, $context];
			}
		};

		return (new AssessmentPublishGuard($objects, $pool, $apps, $classifier, $policy, $logger))->check($object, 'publish', 'u');
	}//end runGuard()

	/**
	 * The log lines in the trace.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function logs(): array {
		return array_values(array_filter($this->trace, static fn (array $t): bool => $t[0] === 'log'));
	}//end logs()

	/**
	 * Assert a refusal with exactly one log line.
	 *
	 * @param GuardResult         $result  The verdict.
	 * @param string              $message The log line.
	 * @param array<string,mixed> $context The log context.
	 *
	 * @return void
	 */
	private function assertRefusedWith(GuardResult $result, string $message, array $context = []): void {
		self::assertFalse($result->isAllowed());
		self::assertSame(self::DENIAL, $result->getMessage());
		self::assertSame([['log', 'info', $message, $context]], $this->logs());
		$this->trace = [];
	}//end assertRefusedWith()

	/**
	 * Fixed items: allowed with itemRefs and no AI review, refused without itemRefs.
	 *
	 * @return void
	 */
	public function testFixedItems(): void {
		self::assertTrue($this->runGuard(['itemRefs' => ['i-1']])->isAllowed());
		self::assertTrue($this->runGuard(['itemSelectionMode' => 'fixed', 'itemRefs' => ['i-1'], 'proctoring' => ['flagReviewMode' => 'manual']])->isAllowed());
		self::assertTrue($this->runGuard(['itemRefs' => ['i-1'], 'proctoring' => []])->isAllowed());
		self::assertTrue($this->runGuard(['itemRefs' => ['i-1'], 'proctoring' => 'ai-assisted'])->isAllowed());
		self::assertSame([], $this->logs());
		$this->trace = [];

		$this->assertRefusedWith($this->runGuard([]), self::P . 'Assessment has no itemRefs; blocking publish.');
		$this->assertRefusedWith(
			$this->runGuard(['itemRefs' => [], 'proctoring' => ['flagReviewMode' => 'ai-assisted']]),
			self::P . 'Assessment has no itemRefs; blocking publish.'
		);
	}//end testFixedItems()

	/**
	 * A random-draw pool: config present, a bank and a positive draw count, and enough variant groups.
	 *
	 * @return void
	 */
	public function testRandomDrawPool(): void {
		$pool = ['itemBankId' => 'bank-1', 'drawCount' => '3', 'tags' => ['t']];
		self::assertTrue($this->runGuard(['itemSelectionMode' => 'random-draw', 'itemPoolConfig' => $pool])->isAllowed());
		self::assertSame(
			[
				['findAll', ['filters' => ['register' => 'learniq', 'schema' => 'item', 'itemBankId' => 'bank-1', 'lifecycle' => 'published']]],
				['group', [['id' => 'item-1']], $pool],
			],
			$this->trace
		);
		$this->trace = [];

		$this->assertRefusedWith(
			$this->runGuard(['itemSelectionMode' => 'random-draw', 'itemPoolConfig' => 'x']),
			self::P . 'itemSelectionMode is random-draw but itemPoolConfig is not set; blocking publish.'
		);
		foreach ([['drawCount' => 2], ['itemBankId' => 'bank-1'], ['itemBankId' => 'bank-1', 'drawCount' => 0]] as $bad) {
			$this->assertRefusedWith(
				$this->runGuard(['itemSelectionMode' => 'random-draw', 'itemPoolConfig' => $bad]),
				self::P . 'itemPoolConfig is missing itemBankId or a valid drawCount; blocking publish.'
			);
		}

		$this->assertRefusedWith(
			$this->runGuard(['itemSelectionMode' => 'random-draw', 'itemPoolConfig' => ['itemBankId' => 'bank-1', 'drawCount' => 4]]),
			self::P . 'ItemBank {bank} has only {found} distinct variant group(s) '
			. 'matching the configured filters, fewer than drawCount {drawCount}; blocking publish.',
			['bank' => 'bank-1', 'found' => 3, 'drawCount' => 4]
		);
	}//end testRandomDrawPool()

	/**
	 * AI-assisted review: Hermiq installed, the feature DPO-enabled, and the provider's locality accepted, in that order.
	 *
	 * @return void
	 */
	public function testAiAssistedReviewGates(): void {
		$ai = ['itemRefs' => ['i-1'], 'proctoring' => ['flagReviewMode' => 'ai-assisted']];

		self::assertTrue($this->runGuard($ai)->isAllowed());
		self::assertSame(
			[
				['installed', 'hermiq'],
				['findAll', ['filters' => ['register' => 'hermiq', 'schema' => 'agentaifeature', 'slug' => 'assessment-ai-proctor-review', 'lifecycle' => 'enabled'], 'limit' => 1]],
				['classify'],
				['compliant', 'eu', true],
			],
			$this->trace
		);
		$this->trace = [];

		$this->assertRefusedWith(
			$this->runGuard($ai, ['hermiq' => false]),
			self::P . 'flagReviewMode is ai-assisted but the Hermiq governance '
			. 'app is not installed; blocking publish. Install and enable Hermiq, then have the '
			. 'DPO enable the "{slug}" AI feature (ADR-005 DPO gate).',
			['slug' => 'assessment-ai-proctor-review']
		);

		$this->assertRefusedWith(
			$this->runGuard($ai, ['feature' => false]),
			self::P . 'flagReviewMode is ai-assisted but no enabled Hermiq AI '
			. 'feature with slug "{slug}" was found; blocking publish (ADR-005 DPO gate). Register '
			. 'and DPO-enable it in the Hermiq AI-feature register.',
			['slug' => 'assessment-ai-proctor-review']
		);

		$line = self::P . 'DPO-enabled but locality violates SovereigntyPolicy — '
			. 'blocking publish. Verdict: locality={locality}, verified={verified}. {evidence}';
		$this->assertRefusedWith($this->runGuard($ai, ['compliant' => false]), $line, ['locality' => 'eu', 'verified' => 'true', 'evidence' => 'ev']);
		$this->assertRefusedWith(
			$this->runGuard($ai, ['compliant' => false, 'locality' => ['locality' => 'us', 'verified' => false, 'evidence' => '']]),
			$line,
			['locality' => 'us', 'verified' => 'false', 'evidence' => '']
		);
	}//end testAiAssistedReviewGates()
}//end class
