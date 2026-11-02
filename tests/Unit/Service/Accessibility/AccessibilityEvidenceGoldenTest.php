<?php

/**
 * Learniq accessibility evidence golden tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Accessibility
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

namespace OCA\Learniq\Tests\Unit\Service\Accessibility;

use OCA\Learniq\Lifecycle\AccessibilityStatementPublishGuard;
use OCA\Learniq\Service\Accessibility\ConformanceEvidence;
use OCA\Learniq\Service\Accessibility\WcagCriteriaCatalogue;
use OCA\Learniq\Service\CsvCellSanitizer;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Pins ConformanceEvidence::build() and the publish guard's
 * failuresWithoutLimitation() for seeded random evaluations against
 * tests/Unit/Service/Accessibility/golden/AccessibilityEvidence.json. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\Accessibility\ConformanceEvidence
 * @covers \OCA\Learniq\Lifecycle\AccessibilityStatementPublishGuard
 * @uses   \OCA\Learniq\Service\Accessibility\WcagCriteriaCatalogue
 * @uses   \OCA\Learniq\Service\CsvCellSanitizer
 */
class AccessibilityEvidenceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/AccessibilityEvidence.json';

	/**
	 * The generator state.
	 *
	 * @var integer
	 */
	private int $seed = 1;

	/**
	 * Every output equals the recorded golden.
	 *
	 * @return void
	 */
	public function testEvidenceMatchesGolden(): void {
		$criteria = array_column((new WcagCriteriaCatalogue())->all(), 'criterion');
		$actual   = [];
		foreach ([1, 2, 3, 4, 5, 6] as $seed) {
			$this->seed = $seed;
			[$records, $limitations] = $this->evaluation(criteria: $criteria, count: ($seed * 7));
			$statement = ['id' => 'st-' . $seed, 'title' => 'Verklaring ' . $seed, 'status' => 'partial', 'secret' => 'not public'];
			if ($seed % 2 === 0) {
				$statement = ['uuid' => 'st-' . $seed];
			}

			$actual['build ' . $seed] = (new ConformanceEvidence($this->createMock(ObjectService::class), new WcagCriteriaCatalogue(), new CsvCellSanitizer()))
				->build($statement, $records, $limitations);
			$actual['failures ' . $seed] = $this->failures(records: $records, limitations: $limitations, tenant: ($seed % 3 === 0 ? '' : 't-1'));
		}

		$c = $criteria;
		$actual['failures handcrafted'] = $this->failures(
			records: [
				['wcagCriterion' => $c[0], 'result' => 'fail', 'limitationId' => 'open-same'],
				['wcagCriterion' => $c[1], 'result' => 'fail', 'limitationId' => 'fixed-same'],
				['wcagCriterion' => $c[2], 'result' => 'fail', 'limitationId' => 'open-other'],
				['wcagCriterion' => $c[3], 'result' => 'fail', 'limitationId' => 'uuid-only'],
				['wcagCriterion' => $c[4], 'result' => 'fail', 'limitationId' => 'no-lifecycle'],
				['wcagCriterion' => $c[5], 'result' => 'pass', 'limitationId' => ''],
			],
			limitations: [
				['id' => 'open-same', 'wcagCriterion' => $c[0], 'lifecycle' => 'open'],
				['id' => 'fixed-same', 'wcagCriterion' => $c[1], 'lifecycle' => 'fixed'],
				['id' => 'open-other', 'wcagCriterion' => $c[9], 'lifecycle' => 'accepted'],
				['uuid' => 'uuid-only', 'wcagCriterion' => $c[3], 'lifecycle' => 'open'],
				['id' => 'no-lifecycle', 'wcagCriterion' => $c[4]],
				['id' => 42, 'wcagCriterion' => $c[5]],
			],
			tenant: 't-1'
		);
		$actual['failures none'] = $this->failures(records: [['wcagCriterion' => $c[0], 'result' => 'pass']], limitations: [], tenant: 't-1');

		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}//end testEvidenceMatchesGolden()

	/**
	 * The guard's unlinked failures and its reads.
	 *
	 * @param list<array<string,mixed>> $records     Criterion results.
	 * @param list<array<string,mixed>> $limitations Limitations.
	 * @param string                    $tenant      Tenant id.
	 *
	 * @return array<string,mixed>
	 */
	private function failures(array $records, array $limitations, string $tenant): array {
		$reads   = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($records, $limitations, &$reads): array {
				$reads[] = $config;
				$rows = $limitations;
				if ($config['filters']['schema'] === 'accessibility-criterion-result') {
					$rows = $records;
				}

				return array_map(static fn (array $row) => OrEntityFactory::make($row, 'x'), $rows);
			}
		);
		$guard  = new AccessibilityStatementPublishGuard($objects, new NullLogger());
		$method = new ReflectionMethod($guard, 'failuresWithoutLimitation');
		return ['unlinked' => $method->invoke($guard, 'st-1', $tenant), 'reads' => $reads];
	}//end failures()

	/**
	 * The next number from the seeded generator, from 0 to $max - 1.
	 *
	 * @param int $max Upper bound (exclusive).
	 *
	 * @return int
	 */
	private function next(int $max): int {
		$this->seed = ((($this->seed * 1103515245) + 12345) % 2147483648);
		return (intdiv($this->seed, 65536) % $max);
	}//end next()

	/**
	 * Random criterion results and limitations.
	 *
	 * @param list<string> $criteria The catalogue's criteria.
	 * @param int          $count    Results.
	 *
	 * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
	 */
	private function evaluation(array $criteria, int $count): array {
		$limitations = [];
		for ($l = 0; $l < 5; $l++) {
			$limitation = ['id' => 'lim-' . $l, 'wcagCriterion' => $criteria[$this->next(count($criteria))], 'description' => ['Uitleg ' . $l, '  ', null, 7][$this->next(4)], 'lifecycle' => ['open', 'fixed', 'accepted', null][$this->next(4)]];
			if ($l === 4) {
				$limitation = ['uuid' => 'lim-4', 'wcagCriterion' => 'unknown'];
			}

			$limitations[] = $limitation;
		}

		$records = [];
		for ($r = 0; $r < $count; $r++) {
			$records[] = [
				'id' => 'res-' . $r,
				'wcagCriterion' => ($this->next(9) === 0) ? 'nonsense' : $criteria[$this->next(count($criteria))],
				'result' => ['pass', 'fail', 'fail', 'not-applicable', 'not-tested', 'maybe'][$this->next(6)],
				'method' => ['manual', 'automated', null][$this->next(3)],
				'evidenceReference' => ['https://evidence/' . $r, '', null][$this->next(3)],
				'testedOn' => '2026-0' . (1 + $this->next(9)) . '-1' . $this->next(9),
				'limitationId' => ['lim-' . $this->next(5), '', 'missing'][$this->next(3)],
			];
		}

		return [$records, $limitations];
	}//end evaluation()
}//end class
