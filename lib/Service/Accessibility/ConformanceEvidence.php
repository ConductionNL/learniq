<?php

/**
 * Learniq Conformance Evidence
 *
 * Builds the conformance table of a published AccessibilityStatement: one row
 * per WCAG 2.1 A and AA success criterion with its recorded result, method,
 * evidence reference, test date and linked limitation. A criterion with no
 * record reads `not-tested`, so the gaps stay visible (design D1).
 *
 * The same table feeds the in-app statement page and the public evidence
 * export. It holds no personal data: who tested a criterion and who approved
 * the statement stay in the register and never reach this output (design D2).
 *
 * @category Service
 * @package  OCA\Learniq\Service\Accessibility
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

namespace OCA\Learniq\Service\Accessibility;

use OCA\Learniq\Service\CsvCellSanitizer;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads a published statement's results and turns them into the evidence table.
 *
 * @psalm-api
 *
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
 */
class ConformanceEvidence {

	/**
	 * OR register slug for Learniq objects.
	 */
	private const REGISTER = 'learniq';

	/**
	 * Schema slugs this table reads.
	 */
	private const STATEMENT_SCHEMA = 'accessibility-statement';
	private const RESULT_SCHEMA = 'accessibility-criterion-result';
	private const LIMITATION_SCHEMA = 'accessibility-limitation';

	/**
	 * The results a criterion can have. Anything else reads as not tested.
	 *
	 * @var string[]
	 */
	public const RESULTS = ['pass', 'fail', 'not-applicable', 'not-tested'];

	/**
	 * Statement fields that are public by law and go into the export.
	 * `approvedBy` (a person's name) is deliberately left out.
	 *
	 * @var string[]
	 */
	private const PUBLIC_STATEMENT_FIELDS = [
		'channelTitle',
		'status',
		'evaluationMethod',
		'evaluationDate',
		'researchReportUrl',
		'standardApplied',
		'feedbackContact',
		'escalationRoute',
		'lastReviewedAt',
	];

	/**
	 * CSV columns, in order.
	 *
	 * @var string[]
	 */
	private const CSV_COLUMNS = [
		'criterion',
		'level',
		'title',
		'result',
		'method',
		'evidenceReference',
		'testedOn',
		'limitation',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object reads.
	 * @param WcagCriteriaCatalogue $catalogue The fifty WCAG 2.1 A and AA criteria.
	 * @param CsvCellSanitizer $sanitizer CSV formula-injection neutraliser.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly WcagCriteriaCatalogue $catalogue,
		private readonly CsvCellSanitizer $sanitizer,
	) {
	}//end __construct()

	/**
	 * The published statement the public asks about.
	 *
	 * With an id, that statement if it is published; without one, the most
	 * recently evaluated published statement. A draft or archived statement
	 * is never returned: only what the school has published is public.
	 *
	 * @param string|null $statementId An AccessibilityStatement uuid, or null for the current one.
	 *
	 * @return array<string,mixed>|null The statement, or null when nothing is published.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function publishedStatement(?string $statementId): ?array {
		// The filter narrows the read; the check on each row is what keeps a draft private.
		$statements = array_values(
			array_filter(
				$this->read(
					schema: self::STATEMENT_SCHEMA,
					filters: ['lifecycle' => 'published']
				),
				static fn (array $row): bool => ($row['lifecycle'] ?? null) === 'published'
			)
		);

		if ($statementId !== null && $statementId !== '') {
			foreach ($statements as $statement) {
				if (self::idOf(row: $statement) === $statementId) {
					return $statement;
				}
			}

			return null;
		}

		if ($statements === []) {
			return null;
		}

		usort(
			$statements,
			static fn (array $left, array $right): int => strcmp(
				(string)($right['evaluationDate'] ?? ''),
				(string)($left['evaluationDate'] ?? '')
			)
		);

		return $statements[0];
	}//end publishedStatement()

	/**
	 * The evidence table of one statement, read from the register.
	 *
	 * @param array<string,mixed> $statement The (published) statement.
	 *
	 * @return array{statement:array<string,mixed>,summary:array<string,int>,criteria:array<int,array<string,mixed>>} The table.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function forStatement(array $statement): array {
		$statementId = self::idOf(row: $statement);
		$filters = ['accessibilityStatementId' => $statementId];

		return $this->build(
			statement: $statement,
			records: $this->read(schema: self::RESULT_SCHEMA, filters: $filters),
			limitations: $this->read(schema: self::LIMITATION_SCHEMA, filters: $filters)
		);
	}//end forStatement()

	/**
	 * Merge the fifty criteria with the recorded results and limitations.
	 *
	 * @param array<string,mixed> $statement The statement.
	 * @param array<int,array<string,mixed>> $records Its AccessibilityCriterionResult rows.
	 * @param array<int,array<string,mixed>> $limitations Its AccessibilityLimitation rows.
	 *
	 * @return array{statement:array<string,mixed>,summary:array<string,int>,criteria:array<int,array<string,mixed>>} The table.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function build(array $statement, array $records, array $limitations): array {
		$byCriterion = $this->latestByCriterion(records: $records);

		$limitationsById = [];
		foreach ($limitations as $limitation) {
			$limitationsById[self::idOf(row: $limitation)] = $limitation;
		}

		$summary = array_fill_keys(self::RESULTS, 0);
		$rows = [];
		foreach ($this->catalogue->all() as $criterion) {
			$record = $byCriterion[$criterion['criterion']] ?? [];
			$result = in_array($record['result'] ?? null, self::RESULTS, true) === true ? $record['result'] : 'not-tested';
			$summary[$result]++;

			$limitation = $limitationsById[(string)($record['limitationId'] ?? '')] ?? null;

			$rows[] = [
				'criterion' => $criterion['criterion'],
				'level' => $criterion['level'],
				'title' => $criterion['title'],
				'result' => $result,
				'method' => self::text(value: $record['method'] ?? null),
				'evidenceReference' => self::text(value: $record['evidenceReference'] ?? null),
				'testedOn' => self::text(value: $record['testedOn'] ?? null),
				'limitationId' => $limitation === null ? null : self::idOf(row: $limitation),
				'limitation' => $limitation === null ? null : self::text(value: $limitation['description'] ?? null),
			];
		}

		$publicStatement = ['id' => self::idOf(row: $statement)];
		foreach (self::PUBLIC_STATEMENT_FIELDS as $field) {
			$publicStatement[$field] = $statement[$field] ?? null;
		}

		$summary['total'] = count($rows);

		return [
			'statement' => $publicStatement,
			'summary' => $summary,
			'criteria' => $rows,
		];
	}//end build()

	/**
	 * The table as CSV: one row per criterion.
	 *
	 * @param array{criteria:array<int,array<string,mixed>>} $evidence The table from build().
	 *
	 * @return string The CSV text, header first.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function toCsv(array $evidence): string {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');
		foreach ($evidence['criteria'] as $row) {
			$cells = [];
			foreach (self::CSV_COLUMNS as $column) {
				$cells[] = $this->sanitizer->sanitize(value: (string)($row[$column] ?? ''));
			}

			fputcsv($handle, $cells, ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end toCsv()

	/**
	 * The latest record per criterion number, by test date.
	 *
	 * @param array<int,array<string,mixed>> $records The result rows.
	 *
	 * @return array<string,array<string,mixed>> Criterion number to record.
	 */
	private function latestByCriterion(array $records): array {
		$latest = [];
		foreach ($records as $record) {
			$number = WcagCriteriaCatalogue::numberOf(reference: $record['wcagCriterion'] ?? null);
			if ($number === null) {
				continue;
			}

			$known = $latest[$number] ?? null;
			if ($known === null || strcmp((string)($record['testedOn'] ?? ''), (string)($known['testedOn'] ?? '')) >= 0) {
				$latest[$number] = $record;
			}
		}

		return $latest;
	}//end latestByCriterion()

	/**
	 * Read rows of one schema as arrays, outside the caller's scope.
	 *
	 * The export is public: the reader is anonymous, so RBAC and tenancy
	 * would hide everything. The scope is the published statement instead,
	 * which publishedStatement() already enforced.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private function read(string $schema, array $filters): array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => array_merge(
					$filters,
					[
						'register' => self::REGISTER,
						'schema' => $schema,
					]
				),
			],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
				continue;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;
	}//end read()

	/**
	 * The uuid of a row, whichever key carries it.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return string The uuid, or '' when there is none.
	 */
	private static function idOf(array $row): string {
		$self = is_array($row['@self'] ?? null) === true ? $row['@self'] : [];
		$id = $row['id'] ?? $row['uuid'] ?? $self['id'] ?? '';

		return is_string($id) === true ? $id : '';
	}//end idOf()

	/**
	 * A stored scalar as text, or null when empty.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The text.
	 */
	private static function text(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		return $text === '' ? null : $text;
	}//end text()
}//end class
