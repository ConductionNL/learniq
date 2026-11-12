<?php

/**
 * Learniq PeerReviewAllocationService characterization test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\PeerReview
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
 * @spec openspec/specs/assignments/spec.md#scenario-round-robin-allocates-the-configured-reviewer-count-while-excluding-self
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\PeerReview;

use OCA\Learniq\Tests\Support\SaveCallShape;
use OCA\Learniq\Service\PeerReviewAllocationService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Pins every ObjectService call PeerReviewAllocationService::allocate() makes,
 * and its result, per scenario, against
 * tests/Unit/PeerReview/golden/PeerReviewAllocation.json. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\PeerReviewAllocationService
 * @covers \OCA\Learniq\Service\PeerReviewNeed
 * @covers \OCA\Learniq\Service\PeerReviewRotation
 */
class PeerReviewAllocationGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/PeerReviewAllocation.json';

	/**
	 * Each scenario: the assignment (or null), its submissions and its existing reviews.
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3?: string}>
	 */
	private static function scenarios(): array {
		$sub = static fn (string $id, array $learners, string $at, string $lifecycle = 'submitted', array $extra = []) => array_merge(
			['id' => $id, 'assignmentId' => 'a1', 'learnerIds' => $learners, 'submittedAt' => $at, 'lifecycle' => $lifecycle, 'tenant_id' => 't1'],
			$extra
		);
		$subs = [
			$sub('s3', ['carol'], '2026-10-03'),
			$sub('s1', ['ann', 'bob'], '2026-10-01'),
			$sub('s2', ['dave'], '2026-10-01', 'late', ['tenant_id' => null]),
			$sub('s4', ['erin'], '2026-10-04', 'returned'),
			$sub('s5', ['frank'], '2026-10-05', 'draft'),
			$sub('', ['gina'], '2026-10-06'),
			$sub('s6', ['hank', 7, ''], '2026-10-02'),
		];
		$reviews = [
			['id' => 'r1', 'assignmentId' => 'a1', 'submissionId' => 's3', 'reviewerId' => 'erin'],
			['id' => 'r2', 'assignmentId' => 'a1', 'submissionId' => 's4', 'reviewerId' => 'ann'],
			['id' => 'r3', 'assignmentId' => 'a1', 'submissionId' => 's4', 'reviewerId' => 'bob'],
			['id' => 'r4', 'assignmentId' => 'a1', 'submissionId' => 's2', 'reviewerId' => ''],
		];
		$assignment = static fn (array $config) => array_merge(['id' => 'a1'], $config);
		return [
			'round robin, two per submission' => [$assignment([]), $subs, $reviews],
			'round robin, three per submission' => [$assignment(['peerReviewAllocationStrategy' => 'round-robin', 'peerReviewersPerSubmission' => 3]), $subs, []],
			'random strategy' => [$assignment(['peerReviewAllocationStrategy' => 'random', 'peerReviewersPerSubmission' => 1]), $subs, $reviews],
			'pool smaller than the quota' => [$assignment(['peerReviewersPerSubmission' => 5]), [$sub('s1', ['ann'], '2026-10-01'), $sub('s2', ['bob'], '2026-10-02')], []],
			'zero reviewers falls back to the default' => [$assignment(['peerReviewersPerSubmission' => 0, 'peerReviewAllocationStrategy' => '']), [$sub('s1', ['ann'], '2026-10-01'), $sub('s2', ['bob'], '2026-10-02'), $sub('s3', ['carol'], '2026-10-03')], []],
			'manual strategy' => [$assignment(['peerReviewAllocationStrategy' => 'manual']), $subs, []],
			'no handed-in submissions' => [$assignment([]), [$sub('s5', ['frank'], '2026-10-05', 'draft')], []],
			'no reviewer pool' => [$assignment([]), [$sub('s1', [7], '2026-10-01')], []],
			'missing assignment' => [null, $subs, []],
			'rotation skips a reviewer already on the submission' => [
				$assignment([]),
				[$sub('s1', ['ann'], '2026-10-01'), $sub('s2', ['bob'], '2026-10-02'), $sub('s3', ['carol'], '2026-10-03')],
				[['id' => 'r1', 'assignmentId' => 'a1', 'submissionId' => 's1', 'reviewerId' => 'bob']],
			],
			'another assignment id' => [
				['id' => 'a2', 'peerReviewersPerSubmission' => 1],
				[$sub('s1', ['ann'], '2026-10-01', 'submitted', ['assignmentId' => 'a2']), $sub('s2', ['bob'], '2026-10-02', 'submitted', ['assignmentId' => 'a2'])],
				[],
				'a2',
			],
		];
	}//end scenarios()

	/**
	 * Every scenario's calls and result equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryScenarioMakesTheRecordedCalls(): void {
		$actual = [];
		foreach (self::scenarios() as $name => $scenario) {
			mt_srand(42);
			$actual[$name] = $this->callsFor($scenario[0], $scenario[1], $scenario[2], ($scenario[3] ?? 'a1'));
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryScenarioMakesTheRecordedCalls()

	/**
	 * Run allocate() over one store and return the calls it made and its result.
	 *
	 * @param array<string, mixed>|null  $assignment  The assignment, or null when missing.
	 * @param list<array<string, mixed>> $submissions The stored submissions.
	 * @param list<array<string, mixed>> $reviews     The stored peer reviews.
	 * @param string                     $id          The assignment id allocate() is called with.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function callsFor(?array $assignment, array $submissions, array $reviews, string $id): array {
		$calls   = [];
		$service = $this->createMock(ObjectService::class);
		$service->method('find')->willReturnCallback(
			static function (...$args) use ($assignment) {
				if ($assignment === null) {
					throw new DoesNotExistException('Object not found');
				}

				return OrEntityFactory::make($assignment, 'assignment');
			}
		);
		$service->method('findAll')->willReturnCallback(
			static function (...$args) use ($submissions, $reviews, &$calls): array {
				$calls[] = ['findAll' => self::named('findAll', $args)];
				$rows    = ($args[0]['filters']['schema'] === 'submission') ? $submissions : $reviews;
				return array_map(static fn (array $row) => OrEntityFactory::make($row, (string)$args[0]['filters']['schema']), $rows);
			}
		);
		$service->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$calls) {
				$named = self::named('saveObject', $args);
				$calls[] = ['saveObject' => $named];
				return OrEntityFactory::make($named['object'], (string)$named['schema']);
			}
		);

		$calls[] = ['result' => (new PeerReviewAllocationService($service, new NullLogger()))->allocate($id)];
		return $calls;
	}//end callsFor()

	/**
	 * A mock call's positional arguments keyed by the loaded ObjectService's parameter names.
	 *
	 * @param string      $method The ObjectService method.
	 * @param list<mixed> $args   The positional arguments.
	 *
	 * @return array<string, mixed>
	 */
	private static function named(string $method, array $args): array {
		if ($method === 'saveObject') {
			return SaveCallShape::named(args: $args);
		}

		$params = (new ReflectionMethod(ObjectService::class, $method))->getParameters();
		$named  = [];
		foreach ($args as $i => $value) {
			if ($params[$i]->isDefaultValueAvailable() === true && $params[$i]->getDefaultValue() === $value) {
				continue;
			}

			$named[$params[$i]->getName()] = $value;
		}

		return $named;
	}//end named()
}//end class
