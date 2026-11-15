<?php

/**
 * Learniq WCAG Criteria Catalogue
 *
 * The fixed list of WCAG 2.1 level A and AA success criteria a conformance
 * table is kept against. It is read from a static file next to this class,
 * never typed into the register by hand, so every statement is measured
 * against the same fifty criteria and a criterion nobody tested still shows
 * up as not tested (governance-wcag-evidence-report design D1).
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
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Accessibility;

use RuntimeException;

/**
 * Reads the WCAG 2.1 A and AA success criteria from the shipped static file.
 *
 * @psalm-api
 *
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
 */
class WcagCriteriaCatalogue {

	/**
	 * The static criteria file, shipped next to this class.
	 */
	private const FILE = __DIR__ . '/wcag21-a-aa-criteria.json';

	/**
	 * The criteria, read once per request.
	 *
	 * @var array<int,array{criterion:string,level:string,title:string}>|null
	 */
	private ?array $criteria = null;

	/**
	 * Every WCAG 2.1 A and AA success criterion, in criterion order.
	 *
	 * @return array<int,array{criterion:string,level:string,title:string}> The criteria.
	 *
	 * @throws RuntimeException When the shipped file is missing or malformed.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
	 */
	public function all(): array {
		if ($this->criteria !== null) {
			return $this->criteria;
		}

		$raw = file_get_contents(self::FILE);
		$decoded = json_decode((string)$raw, true);
		if (is_array($decoded) === false || is_array($decoded['criteria'] ?? null) === false) {
			throw new RuntimeException('The WCAG criteria file is missing or malformed.');
		}

		$criteria = [];
		foreach ($decoded['criteria'] as $row) {
			$criteria[] = [
				'criterion' => (string)($row['criterion'] ?? ''),
				'level' => (string)($row['level'] ?? ''),
				'title' => (string)($row['title'] ?? ''),
			];
		}

		$this->criteria = $criteria;
		return $criteria;
	}//end all()

	/**
	 * The criterion number a free-text reference starts with.
	 *
	 * A limitation names its criterion as free text ("2.1.1 Keyboard"); a
	 * result record names it by number. Both are compared on the number.
	 *
	 * @param mixed $reference The stored reference, for example "2.1.1 Keyboard".
	 *
	 * @return string|null The number, for example "2.1.1", or null when there is none.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
	 */
	public function numberOf(mixed $reference): ?string {
		if (is_string($reference) === false) {
			return null;
		}

		if (preg_match('/^\s*(\d+\.\d+\.\d+)/', $reference, $match) !== 1) {
			return null;
		}

		return $match[1];
	}//end numberOf()
}//end class
