<?php

/**
 * Tests for AssessmentScoringHandler as an OpenRegister lifecycle guard.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AssessmentScoringHandler;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The submit guard refuses an attempt whose parent Assessment can not be
 * resolved (learniq#983), and the scoring it hands the action is unchanged.
 *
 * Fixtures are the object OpenRegister hands a guard: the flat AssessmentResult
 * with the lifecycle field already at `submitted`.
 */
class AssessmentScoringHandlerTest extends TestCase {

	/**
	 * Build the handler over the given Assessment and Item fixtures.
	 *
	 * @param TestCase $test The test that owns the mocks.
	 * @param array<string,mixed>|null $assessment The parent Assessment, or null when unreachable.
	 * @param array<string,array<string,mixed>> $items Item fixtures by uuid.
	 *
	 * @return AssessmentScoringHandler
	 */
	public static function makeHandler(TestCase $test, ?array $assessment, array $items = []): AssessmentScoringHandler {
		$objectService = $test->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($assessment, $items): array {
				$uuid = ($config['filters']['uuid'] ?? '');
				if ($config['filters']['schema'] === 'exam') {
					if ($assessment === null) {
						return [];
					}

					return [$assessment];
				}

				if (isset($items[$uuid]) === true) {
					return [$items[$uuid]];
				}

				return [];
			}
		);

		$logger = $test->getMockBuilder(LoggerInterface::class)->getMock();

		return new AssessmentScoringHandler($objectService, $logger);
	}//end makeHandler()

	/**
	 * The submitted AssessmentResult as OpenRegister saves it.
	 *
	 * @return array<string,mixed>
	 */
	public static function submittedResult(): array {
		return [
			'id' => 'result-1',
			'lifecycle' => 'submitted',
			'assessmentId' => 'exam-1',
			'tenant_id' => 'tenant-1',
			'responses' => [
				['itemId' => 'item-choice', 'response' => 'B', 'autoScore' => 99],
				['itemId' => 'item-essay', 'response' => 'My essay.', 'autoScore' => 99],
			],
		];
	}//end submittedResult()

	/**
	 * OpenRegister only runs a guard that implements its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, self::makeHandler($this, null));
	}//end testImplementsTheGuardInterface()

	/**
	 * An unreachable parent Assessment refuses the submit, as the old guard did.
	 *
	 * @return void
	 */
	public function testUnreachableAssessmentRefusesSubmit(): void {
		$result = self::makeHandler($this, null)->check(self::submittedResult(), 'submit', 'learner-1');

		self::assertFalse($result->isAllowed());
		self::assertNotSame('', (string)$result->getMessage());
	}//end testUnreachableAssessmentRefusesSubmit()

	/**
	 * A reachable parent Assessment allows the submit.
	 *
	 * @return void
	 */
	public function testReachableAssessmentAllowsSubmit(): void {
		$handler = self::makeHandler($this, ['id' => 'exam-1', 'itemRefs' => []]);

		self::assertTrue($handler->check(self::submittedResult(), 'submit', 'learner-1')->isAllowed());
	}//end testReachableAssessmentAllowsSubmit()

	/**
	 * An attempt with no responses has nothing to score and is allowed.
	 *
	 * @return void
	 */
	public function testAttemptWithoutResponsesIsAllowed(): void {
		$object = self::submittedResult();
		$object['responses'] = [];

		self::assertTrue(self::makeHandler($this, null)->check($object, 'submit', 'learner-1')->isAllowed());
	}//end testAttemptWithoutResponsesIsAllowed()
}//end class
