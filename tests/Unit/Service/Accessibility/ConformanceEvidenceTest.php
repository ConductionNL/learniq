<?php

/**
 * Learniq ConformanceEvidence unit tests.
 *
 * governance-wcag-evidence-report tasks 1.1 and 2.2: the table holds all fifty
 * WCAG 2.1 A and AA criteria, a criterion with no record reads not tested and
 * is counted, and the export (JSON and CSV) carries no personal data. The
 * OpenRegister reads answer with real ObjectEntity instances.
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
 *
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Accessibility;

use OCA\Learniq\Service\Accessibility\ConformanceEvidence;
use OCA\Learniq\Service\Accessibility\WcagCriteriaCatalogue;
use OCA\Learniq\Service\CsvCellSanitizer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The conformance table and its export.
 *
 * @covers \OCA\Learniq\Service\Accessibility\ConformanceEvidence
 * @covers \OCA\Learniq\Service\Accessibility\WcagCriteriaCatalogue
 * @uses \OCA\Learniq\Service\CsvCellSanitizer
 */
class ConformanceEvidenceTest extends TestCase {

	/**
	 * A tester's name that must never reach the export.
	 */
	private const TESTER = 'Fatima El-Amrani';

	/**
	 * An approver's name that must never reach the export.
	 */
	private const APPROVER = 'Henk Bakker';

	/**
	 * The published statement.
	 *
	 * @return array<string,mixed>
	 */
	private static function statement(): array {
		return [
			'id' => 'statement-1',
			'channelTitle' => 'Learniq De Vrije School',
			'status' => 'partially-compliant',
			'evaluationMethod' => 'expert-review',
			'evaluationDate' => '2026-09-01',
			'feedbackContact' => 'toegankelijkheid@school.example',
			'approvedBy' => self::APPROVER,
			'approvedByRole' => 'director',
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
	 * The service over an ObjectService that answers per schema.
	 *
	 * @param array<string,array<int,mixed>> $bySchema Rows per schema slug.
	 * @param array<int,array<string,mixed>> $calls Collects every findAll() call.
	 *
	 * @return ConformanceEvidence
	 */
	private function service(array $bySchema, array &$calls = []): ConformanceEvidence {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config, bool $_rbac = true, bool $_multitenancy = true) use ($bySchema, &$calls): array {
				$calls[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $bySchema[$config['filters']['schema'] ?? ''] ?? [];
			}
		);

		return new ConformanceEvidence($objectService, new WcagCriteriaCatalogue(), new CsvCellSanitizer());
	}//end service()

	/**
	 * The shipped list holds fifty unique criteria: thirty at A, twenty at AA.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
	 */
	public function testTheSeedHoldsFiftyUniqueCriteria(): void {
		$criteria = (new WcagCriteriaCatalogue())->all();

		self::assertCount(50, $criteria);
		self::assertCount(50, array_unique(array_column($criteria, 'criterion')));
		self::assertSame(['A' => 30, 'AA' => 20], array_count_values(array_column($criteria, 'level')));
		self::assertSame('1.1.1', $criteria[0]['criterion']);
		self::assertContains('4.1.3', array_column($criteria, 'criterion'));
		self::assertSame('2.1.1', (new WcagCriteriaCatalogue())->numberOf(reference: '2.1.1 Keyboard'));
		self::assertNull((new WcagCriteriaCatalogue())->numberOf(reference: 'Keyboard'));
		self::assertNull((new WcagCriteriaCatalogue())->numberOf(reference: null));
	}//end testTheSeedHoldsFiftyUniqueCriteria()

	/**
	 * A recorded pass shows its method, link and date; the rest read not tested and are counted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-an-owner-records-results
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-untested-criteria-are-visible
	 */
	public function testTheTableShowsResultsAndCountsTheUntested(): void {
		$records = [
			self::entity('r-1', ['accessibilityStatementId' => 'statement-1', 'wcagCriterion' => '1.4.3', 'result' => 'pass', 'method' => 'axe and manual check', 'evidenceReference' => 'https://example.org/report', 'testedOn' => '2026-08-01', 'testedBy' => self::TESTER]),
			self::entity('r-2', ['accessibilityStatementId' => 'statement-1', 'wcagCriterion' => '2.1.1', 'result' => 'fail', 'limitationId' => 'l-1', 'testedOn' => '2026-08-01']),
			self::entity('r-3', ['accessibilityStatementId' => 'statement-1', 'wcagCriterion' => '1.2.4', 'result' => 'not-applicable']),
			self::entity('r-4', ['accessibilityStatementId' => 'statement-1', 'wcagCriterion' => 'not a number', 'result' => 'pass']),
		];
		$calls = [];
		$service = $this->service(
			bySchema: [
				'accessibility-criterion-result' => $records,
				'accessibility-limitation' => [self::entity('l-1', ['wcagCriterion' => '2.1.1 Keyboard', 'description' => 'The lesson reorder needs a mouse.'])],
			],
			calls: $calls
		);

		$table = $service->forStatement(statement: self::statement());

		$rows = array_column($table['criteria'], null, 'criterion');
		self::assertCount(50, $rows);
		self::assertSame(
			['criterion' => '1.4.3', 'level' => 'AA', 'title' => 'Contrast (Minimum)', 'result' => 'pass', 'method' => 'axe and manual check', 'evidenceReference' => 'https://example.org/report', 'testedOn' => '2026-08-01', 'limitationId' => null, 'limitation' => null],
			$rows['1.4.3']
		);
		self::assertSame(['fail', 'l-1', 'The lesson reorder needs a mouse.'], [$rows['2.1.1']['result'], $rows['2.1.1']['limitationId'], $rows['2.1.1']['limitation']]);
		self::assertSame('not-tested', $rows['1.1.1']['result']);
		self::assertSame(['pass' => 1, 'fail' => 1, 'not-applicable' => 1, 'not-tested' => 47, 'total' => 50], $table['summary']);

		foreach ($calls as $call) {
			self::assertSame([false, false], [$call['rbac'], $call['multitenancy']], 'an anonymous reader sees what is published');
			self::assertSame('statement-1', $call['config']['filters']['accessibilityStatementId']);
		}
	}//end testTheTableShowsResultsAndCountsTheUntested()

	/**
	 * Twelve untested criteria read as twelve in the summary.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-untested-criteria-are-visible
	 */
	public function testTwelveUntestedCriteriaAreCounted(): void {
		$records = [];
		foreach (array_slice((new WcagCriteriaCatalogue())->all(), 0, 38) as $index => $criterion) {
			$records[] = ['wcagCriterion' => $criterion['criterion'], 'result' => 'pass', 'testedOn' => '2026-08-0' . (($index % 9) + 1)];
		}

		$records[] = ['wcagCriterion' => '1.1.1', 'result' => 'fail', 'testedOn' => '2026-07-01'];

		$table = $this->service(bySchema: [])->build(statement: self::statement(), records: $records, limitations: []);

		self::assertSame(12, $table['summary']['not-tested']);
		self::assertSame(38, $table['summary']['pass'], 'the older fail on 1.1.1 is not the latest record');
	}//end testTwelveUntestedCriteriaAreCounted()

	/**
	 * Neither the JSON nor the CSV carries a tester or an approver.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function testTheExportHoldsNoPersonalData(): void {
		$service = $this->service(bySchema: []);
		$table = $service->build(
			statement: self::statement(),
			records: [['wcagCriterion' => '1.4.3', 'result' => 'pass', 'testedBy' => self::TESTER, 'method' => '=HYPERLINK("x")']],
			limitations: []
		);

		$json = (string)json_encode($table);
		$csv = $service->toCsv(evidence: $table);
		foreach ([$json, $csv] as $export) {
			self::assertStringNotContainsString(self::TESTER, $export);
			self::assertStringNotContainsString(self::APPROVER, $export);
			self::assertStringNotContainsString('testedBy', $export);
			self::assertStringNotContainsString('approvedBy', $export);
		}

		self::assertSame('Learniq De Vrije School', $table['statement']['channelTitle']);
		self::assertSame('statement-1', $table['statement']['id']);
	}//end testTheExportHoldsNoPersonalData()

	/**
	 * The CSV has a header and one row per criterion, with formula cells neutralised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-procurement-officer-asks-for-evidence
	 */
	public function testTheCsvHasOneRowPerCriterion(): void {
		$service = $this->service(bySchema: []);
		$table = $service->build(
			statement: self::statement(),
			records: [['wcagCriterion' => '1.4.3', 'result' => 'pass', 'method' => '=cmd', 'evidenceReference' => 'https://example.org/r', 'testedOn' => '2026-08-01']],
			limitations: []
		);

		$lines = array_values(array_filter(explode("\n", $service->toCsv(evidence: $table))));
		self::assertCount(51, $lines);
		self::assertSame('criterion,level,title,result,method,evidenceReference,testedOn,limitation', $lines[0]);

		$row = null;
		foreach ($lines as $line) {
			if (str_starts_with($line, '1.4.3,') === true) {
				$row = str_getcsv($line, ',', '"', '');
			}
		}

		self::assertSame(['1.4.3', 'AA', 'Contrast (Minimum)', 'pass', "\t=cmd", 'https://example.org/r', '2026-08-01', ''], $row);
	}//end testTheCsvHasOneRowPerCriterion()

	/**
	 * Only a published statement is public: by id, else the latest evaluated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function testOnlyAPublishedStatementIsServed(): void {
		$calls = [];
		$service = $this->service(
			bySchema: [
				'accessibility-statement' => [
					self::entity('old', ['evaluationDate' => '2025-09-01', 'lifecycle' => 'published']),
					self::entity('new', ['evaluationDate' => '2026-09-01', 'lifecycle' => 'published']),
				],
			],
			calls: $calls
		);

		self::assertSame('new', $service->publishedStatement(statementId: null)['id']);
		self::assertSame('old', $service->publishedStatement(statementId: 'old')['id']);
		self::assertNull($service->publishedStatement(statementId: 'draft-one'));
		self::assertSame('published', $calls[0]['config']['filters']['lifecycle']);

		self::assertNull($this->service(bySchema: [])->publishedStatement(statementId: null));
	}//end testOnlyAPublishedStatementIsServed()

	/**
	 * A draft the store hands back anyway (a filter it did not apply) is still not served.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function testADraftIsNeverServedEvenWhenTheFilterIsIgnored(): void {
		$service = $this->service(
			bySchema: [
				'accessibility-statement' => [
					self::entity('live', ['evaluationDate' => '2026-03-01', 'lifecycle' => 'published']),
					self::entity('draft', ['evaluationDate' => '2026-09-30', 'lifecycle' => 'draft']),
					self::entity('bare', ['evaluationDate' => '2026-10-01']),
				],
			]
		);

		self::assertSame('live', $service->publishedStatement(statementId: null)['id']);
		self::assertNull($service->publishedStatement(statementId: 'draft'));
		self::assertNull($service->publishedStatement(statementId: 'bare'));
	}//end testADraftIsNeverServedEvenWhenTheFilterIsIgnored()
}//end class
