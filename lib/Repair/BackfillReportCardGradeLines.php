<?php

/**
 * Repair step that back-fills ReportCard.periodName and ReportCard.gradeLines
 * on report cards composed before the server started writing them.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-existing-report-cards-get-their-readable-grades
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Service\ReportCardGradeLines;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes on every existing ReportCard what ReportCardGradeLinesStamp stores
 * today: `periodName` and `gradeLines`. The parent portal shows those, so a
 * report card composed before the stamp existed showed a guardian no grades.
 *
 * Idempotent: a row whose stored copies already equal the derived ones is not
 * saved, so a second run saves nothing. A failed lookup skips the row and
 * never overwrites a stored value. Runs without a session, so every read and
 * write passes `_rbac: false` and `_multitenancy: false`. The save runs the
 * stamp listener too, which derives the same values.
 *
 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-existing-report-cards-get-their-readable-grades
 */
class BackfillReportCardGradeLines implements IRepairStep {

	private const LEARNIQ_REGISTER = 'learniq';
	private const REPORT_CARD_SCHEMA = 'report-card';
	private const PAGE_SIZE = 200;

	/**
	 * Hard stop so a paging defect can never loop forever: 10,000 pages of
	 * 200 is two million report cards.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * How many distinct refusal reasons the summary names.
	 */
	private const REASONS_SHOWN = 3;

	/**
	 * The message of the last refused write, read by run() to group it.
	 *
	 * @var string
	 */
	private string $lastRefusal = '';

	/**
	 * Constructor.
	 *
	 * @param ObjectService        $objectService OpenRegister object access.
	 * @param ReportCardGradeLines $gradeLines    Derives the readable copies.
	 * @param LoggerInterface      $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ReportCardGradeLines $gradeLines,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-existing-report-cards-get-their-readable-grades
	 */
	public function getName(): string {
		return 'Write the readable period and subject grades on existing report cards so guardians see them in the portal';
	}//end getName()

	/**
	 * Page through every ReportCard and write the ones whose copies differ.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-existing-report-cards-get-their-readable-grades
	 */
	public function run(IOutput $output): void {
		$counts = ['scanned' => 0, 'stamped' => 0, 'failed' => 0];
		$refusals = [];

		try {
			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$rows = $this->page(offset: ($page * self::PAGE_SIZE));
				foreach ($rows as $row) {
					$counts['scanned']++;
					$outcome = $this->stampRow(row: $row);
					if ($outcome !== null) {
						$counts[$outcome]++;
					}

					if ($outcome === 'failed') {
						$refusals = self::countRefusal(refusals: $refusals, message: $this->lastRefusal);
					}
				}

				if (count($rows) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $exception) {
			// OpenRegister absent or the register not imported yet: nothing
			// to back-fill on this run, the next upgrade retries.
			$this->logger->warning(
				'[BackfillReportCardGradeLines] Stopped early: {msg}',
				['msg' => $exception->getMessage()]
			);
		}//end try

		$output->info(
			'BackfillReportCardGradeLines: ' . $counts['stamped'] . ' stamped, ' . $counts['failed']
			. ' failed, of ' . $counts['scanned'] . ' scanned.'
		);

		// Live pass lane 10: "0 stamped, 294 failed" said nothing about why;
		// the reasons were only in the log, one warning per row. The upgrade
		// output now names them, grouped, so the cause shows where it is read.
		if ($refusals !== []) {
			$output->warning('BackfillReportCardGradeLines: report cards refused: ' . self::refusalSummary(refusals: $refusals));
		}
	}//end run()

	/**
	 * The most frequent refusal reasons, as "N x reason; ...".
	 *
	 * @param array<string, int> $refusals Reasons with their counts.
	 *
	 * @return string
	 */
	private static function refusalSummary(array $refusals): string {
		arsort($refusals);
		$parts = [];
		foreach (array_slice($refusals, 0, self::REASONS_SHOWN, true) as $reason => $count) {
			$parts[] = $count . ' x ' . $reason;
		}

		$others = (count($refusals) - count($parts));
		if ($others > 0) {
			$parts[] = 'and ' . $others . ' other reason(s), see the log';
		}

		return implode('; ', $parts);
	}//end refusalSummary()

	/**
	 * Count one refusal under its reason, with uuids and list positions
	 * replaced so the same cause on many rows groups into one line.
	 *
	 * @param array<string, int> $refusals Reasons counted so far.
	 * @param string             $message  The exception message.
	 *
	 * @return array<string, int>
	 */
	private static function countRefusal(array $refusals, string $message): array {
		$reason = (string)preg_replace(
			['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/\.\d+\./'],
			['<uuid>', '.N.'],
			$message
		);
		$refusals[$reason] = (($refusals[$reason] ?? 0) + 1);
		return $refusals;
	}//end countRefusal()

	/**
	 * One page of ReportCard rows as arrays.
	 *
	 * @param int $offset Row offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page(int $offset): array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::REPORT_CARD_SCHEMA,
				],
				'limit' => self::PAGE_SIZE,
				'offset' => $offset,
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
	}//end page()

	/**
	 * Write one row when its stored copies differ from the derived ones.
	 *
	 * @param array<string, mixed> $row The ReportCard.
	 *
	 * @return string|null Counter to bump, or null when the row needed nothing.
	 */
	private function stampRow(array $row): ?string {
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		try {
			$derived = $this->gradeLines->derive(reportCard: $row);
			if (($row['periodName'] ?? null) === $derived['periodName']
				&& array_values((array)($row['gradeLines'] ?? [])) === $derived['gradeLines']
			) {
				return null;
			}

			// The row as read carries OpenRegister's `@self` block. Saving it
			// back makes OpenRegister check the acting user may use the row's
			// folder, which a session-less step never may. The stored
			// metadata stays on the object either way.
			$object = array_merge($row, $derived);
			unset($object['@self']);

			$this->objectService->saveObject(
				object: $object,
				register: self::LEARNIQ_REGISTER,
				schema: self::REPORT_CARD_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillReportCardGradeLines] Could not write report card {id}: {msg}',
				['id' => $uuid, 'msg' => $exception->getMessage()]
			);
			$this->lastRefusal = $exception->getMessage();
			return 'failed';
		}//end try

		return 'stamped';
	}//end stampRow()
}//end class
