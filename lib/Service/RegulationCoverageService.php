<?php

/**
 * Learniq Regulation Coverage Service
 *
 * Coverage per active regulation for the compliance page's per-rule table:
 * learners in scope, covered, excused, the percentage and a red, amber or
 * green state from the regulation's own thresholds
 * (compliance-rule-coverage-table). It asks ComplianceRollupService for the
 * learners, the regulations, the exemptions and the coverage answer, so the
 * table and the department roll-up count the same things.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Coverage per active regulation.
 *
 * @psalm-api
 *
 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
 */
class RegulationCoverageService {

	/**
	 * Regulation.ragRedThreshold and ragAmberThreshold defaults, as the register declares them.
	 */
	private const RAG_RED_DEFAULT = 70;
	private const RAG_AMBER_DEFAULT = 90;

	/**
	 * Constructor.
	 *
	 * @param ComplianceRollupService    $rollup            Learners, regulations, exemptions and coverage.
	 * @param RegulationAudienceResolver $audience          Audience-scope predicate and department levels.
	 * @param RunningExemptions          $runningExemptions The exemptions that run on a day.
	 */
	public function __construct(
		private readonly ComplianceRollupService $rollup,
		private readonly RegulationAudienceResolver $audience,
		private readonly RunningExemptions $runningExemptions=new RunningExemptions(),
	) {
	}//end __construct()

	/**
	 * Coverage per active regulation: in scope, covered, excused, percent and RAG.
	 *
	 * Walks the same learners and asks the same audience, exemption and
	 * coverage questions as ComplianceRollupService::byDepartment(), so the two
	 * cannot disagree
	 * (compliance-rule-coverage-table design D1). The RAG state uses the
	 * regulation's own thresholds (D2); a rule nobody is in scope for has no
	 * percentage and no state.
	 *
	 * @param string|null            $department Only learners of this department and its teams; null for all.
	 * @param DateTimeImmutable|null $now        Evaluation instant (injectable for tests).
	 *
	 * @return array<int,array<string,mixed>> One row per regulation, by name.
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
	 */
	public function byRegulation(?string $department=null, ?DateTimeImmutable $now=null): array {
		$now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$population = $this->rollup->population();
		$regulations = $population['regulations'];
		$exemptions = $population['exemptions'];

		$rows = [];
		foreach ($regulations as $index => $regulation) {
			$rows[$index] = [
				'id' => (string)($regulation['id'] ?? ($regulation['uuid'] ?? '')),
				'slug' => (string)($regulation['slug'] ?? ''),
				'name' => (string)($regulation['name'] ?? ($regulation['slug'] ?? '')),
				'inScope' => 0,
				'covered' => 0,
				'excused' => 0,
			];
		}

		foreach ($population['learners'] as $profile) {
			if ($this->inDepartment(profile: $profile, department: $department) === false) {
				continue;
			}

			$keys = $this->rollup->learnerKeys(profile: $profile);
			$excusedFrom = $this->runningExemptions->regulationsFor(keys: $keys, exemptions: $exemptions, now: $now);
			foreach ($regulations as $index => $regulation) {
				if ($this->audience->covers(regulation: $regulation, profile: $profile) === false) {
					continue;
				}

				$slug = (string)($regulation['slug'] ?? '');
				if (isset($excusedFrom[$slug]) === true) {
					$rows[$index]['excused']++;
					continue;
				}

				$rows[$index]['inScope']++;
				if ($this->rollup->isCovered(keys: $keys, regulationSlug: $slug, now: $now) === true) {
					$rows[$index]['covered']++;
				}
			}
		}//end foreach

		$finished = [];
		foreach ($rows as $index => $row) {
			$finished[] = $this->finishRegulationRow(row: $row, regulation: $regulations[$index]);
		}

		usort($finished, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

		return $finished;
	}//end byRegulation()

	/**
	 * Whether a learner counts under the department filter: no filter, or
	 * the filter is their department or one of its parent levels.
	 *
	 * @param array<string,mixed> $profile    The learner.
	 * @param string|null         $department The filter.
	 *
	 * @return bool
	 */
	private function inDepartment(array $profile, ?string $department): bool {
		if ($department === null || $department === '') {
			return true;
		}

		return in_array($department, $this->audience->departmentLevels(department: (string)($profile['department'] ?? '')), true);
	}//end inDepartment()

	/**
	 * Add the percentage and the RAG state to a regulation row.
	 *
	 * Red below `ragRedThreshold` (default 70), amber below
	 * `ragAmberThreshold` (default 90), green otherwise.
	 *
	 * @param array<string,mixed> $row        The counted row.
	 * @param array<string,mixed> $regulation The regulation.
	 *
	 * @return array<string,mixed>
	 */
	private function finishRegulationRow(array $row, array $regulation): array {
		$row['coveragePercent'] = null;
		$row['rag'] = null;
		if ($row['inScope'] === 0) {
			return $row;
		}

		$percent = round(100 * $row['covered'] / $row['inScope'], 1);
		$row['coveragePercent'] = $percent;
		$row['rag'] = 'green';
		if ($percent < (float)($regulation['ragAmberThreshold'] ?? self::RAG_AMBER_DEFAULT)) {
			$row['rag'] = 'amber';
		}

		if ($percent < (float)($regulation['ragRedThreshold'] ?? self::RAG_RED_DEFAULT)) {
			$row['rag'] = 'red';
		}

		return $row;
	}//end finishRegulationRow()
}//end class
