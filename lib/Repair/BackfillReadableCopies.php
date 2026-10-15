<?php

/**
 * Learniq BackfillReadableCopies
 *
 * Writes on every existing grade entry, enrolment, portfolio share and
 * teacher availability what ReadableCopyStamp stores today: `courseName`,
 * `cohortName`, `portfolioTitle`, `learnerName` and `teacherName`. A row written before the stamp existed would otherwise
 * show a guardian, pupil or assessor no name. Idempotent: a row whose stored
 * copies already equal the derived ones is not saved, so a second run saves
 * nothing. A failed lookup skips the row and never overwrites a stored value.
 * Runs without a session, so every read and write passes `_rbac: false` and
 * `_multitenancy: false`.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
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
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Service\ReadableCopies;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Back-fills the readable copies on rows written before the stamp.
 *
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
 */
class BackfillReadableCopies implements IRepairStep {

	private const REGISTER = 'learniq';

	private const SCHEMAS = ['grade-entry', 'enrolment', 'portfolio-share', 'teacher-availability', 'learner-profile', 'bpv-hour-week'];

	private const PAGE_SIZE = 200;

	/**
	 * Hard stop so a paging defect can never loop forever.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object access.
	 * @param ReadableCopies  $copies        Derives the readable copies.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ReadableCopies $copies,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
	 */
	public function getName(): string {
		return 'Write the readable course, group, portfolio, learner and teacher names on existing grades, enrolments, '
			. 'portfolio shares and teacher availability';
	}//end getName()

	/**
	 * Page through every covered schema and write the rows whose copies differ.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
	 */
	public function run(IOutput $output): void {
		foreach (self::SCHEMAS as $schema) {
			$counts = $this->backfill(schema: $schema);
			$output->info(
				'BackfillReadableCopies ' . $schema . ': ' . $counts['stamped'] . ' stamped, ' . $counts['failed']
				. ' failed, of ' . $counts['scanned'] . ' scanned.'
			);
		}
	}//end run()

	/**
	 * Back-fill one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array{scanned: int, stamped: int, failed: int}
	 */
	private function backfill(string $schema): array {
		$counts = ['scanned' => 0, 'stamped' => 0, 'failed' => 0];

		try {
			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$rows = $this->page(schema: $schema, offset: ($page * self::PAGE_SIZE));
				foreach ($rows as $row) {
					$counts['scanned']++;
					$outcome = $this->stampRow(schema: $schema, row: $row);
					if ($outcome !== null) {
						$counts[$outcome]++;
					}
				}

				if (count($rows) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $exception) {
			// OpenRegister absent or the register not imported yet: the next
			// upgrade retries.
			$this->logger->warning(
				'[BackfillReadableCopies] Stopped early on {schema}: {msg}',
				['schema' => $schema, 'msg' => $exception->getMessage()]
			);
		}

		return $counts;
	}//end backfill()

	/**
	 * One page of rows as arrays.
	 *
	 * @param string $schema The schema slug.
	 * @param int    $offset Row offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page(string $schema, int $offset): array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema'   => $schema,
				],
				'limit'   => self::PAGE_SIZE,
				'offset'  => $offset,
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
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return string|null Counter to bump, or null when the row needed nothing.
	 */
	private function stampRow(string $schema, array $row): ?string {
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		try {
			$derived = $this->copies->derive(slug: $schema, row: $row);
			if ($this->unchanged(row: $row, derived: $derived) === true) {
				return null;
			}

			// The `@self` block would make OpenRegister check the acting
			// user's folder rights, which a session-less step never has.
			$object = array_merge($row, $derived);
			unset($object['@self']);

			$this->objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillReadableCopies] Could not write {schema} {id}: {msg}',
				['schema' => $schema, 'id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}//end try

		return 'stamped';
	}//end stampRow()

	/**
	 * Whether every derived copy already equals the stored one.
	 *
	 * @param array<string, mixed>       $row     The stored row.
	 * @param array<string, string|null> $derived The derived copies.
	 *
	 * @return bool
	 */
	private function unchanged(array $row, array $derived): bool {
		foreach ($derived as $field => $value) {
			if (($row[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end unchanged()
}//end class
