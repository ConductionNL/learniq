<?php

/**
 * Learniq walk over every row of one learniq schema, for a repair step.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Repair plumbing, not a behavioural requirement: it pages
 *       through a schema so each back-fill step only says what to do with
 *       one row.
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair\Support;

use Closure;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pages through every row of a learniq schema, 200 at a time, and hands each
 * row to a visit. A repair step runs without a session, so every read passes
 * `_rbac: false` and `_multitenancy: false`. A failed read stops the walk
 * with a warning instead of breaking the upgrade: OpenRegister absent or the
 * register not imported yet means nothing to back-fill on this run, and the
 * next upgrade retries.
 *
 * @spec exclude Repair plumbing, not a behavioural requirement.
 */
final class RepairRowWalk {

	private const REGISTER = 'learniq';
	private const PAGE_SIZE = 200;

	/**
	 * Hard stop so a paging defect can never loop forever: 10,000 pages of
	 * 200 is two million rows.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param LoggerInterface $logger PSR logger.
	 * @param string $label The owning step's name, prefixed to its warnings.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly string $label,
	) {
	}//end __construct()

	/**
	 * Visit every row of a schema; a failed read stops the walk.
	 *
	 * @param string $schema The schema slug.
	 * @param Closure(array<string, mixed>): ?string $visit Handles one row and
	 *     names its outcome, or null.
	 *
	 * @return RepairTally
	 *
	 * @spec exclude Repair plumbing, not a behavioural requirement.
	 */
	public function walk(string $schema, Closure $visit): RepairTally {
		$tally = new RepairTally(visit: $visit);
		$stop = $this->stopOf(schema: $schema, tally: $tally);
		if ($stop !== null) {
			$this->logger->warning('['.$this->label.'] Stopped early: {msg}', ['msg' => $stop->getMessage()]);
		}

		return $tally;
	}//end walk()

	/**
	 * Visit every row of a schema; a failed read stops the walk, and the
	 * warning names the schema.
	 *
	 * @param string $schema The schema slug.
	 * @param Closure(array<string, mixed>): ?string $visit Handles one row and
	 *     names its outcome, or null.
	 *
	 * @return RepairTally
	 *
	 * @spec exclude Repair plumbing, not a behavioural requirement.
	 */
	public function walkNamingSchema(string $schema, Closure $visit): RepairTally {
		$tally = new RepairTally(visit: $visit);
		$stop = $this->stopOf(schema: $schema, tally: $tally);
		if ($stop !== null) {
			$this->logger->warning(
				'['.$this->label.'] Stopped early on {schema}: {msg}',
				['schema' => $schema, 'msg' => $stop->getMessage()]
			);
		}

		return $tally;
	}//end walkNamingSchema()

	/**
	 * Walk the pages; the failure that stopped the walk, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param RepairTally $tally Counts the rows and hands them on.
	 *
	 * @return Throwable|null
	 */
	private function stopOf(string $schema, RepairTally $tally): ?Throwable {
		try {
			$this->visitPages(schema: $schema, tally: $tally);
		} catch (Throwable $exception) {
			return $exception;
		}

		return null;
	}//end stopOf()

	/**
	 * Hand every row of every page to the tally, up to the short page.
	 *
	 * @param string $schema The schema slug.
	 * @param RepairTally $tally Counts the rows and hands them on.
	 *
	 * @return void
	 */
	private function visitPages(string $schema, RepairTally $tally): void {
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->page(schema: $schema, offset: ($page * self::PAGE_SIZE));
			foreach ($rows as $row) {
				$tally->visit(row: $row);
			}

			if (count($rows) < self::PAGE_SIZE) {
				return;
			}
		}
	}//end visitPages()

	/**
	 * One page of a schema's rows as arrays.
	 *
	 * @param string $schema The schema slug.
	 * @param int $offset Row offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page(string $schema, int $offset): array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => $schema,
				],
				'limit' => self::PAGE_SIZE,
				'offset' => $offset,
			],
			_rbac: false,
			_multitenancy: false
		);

		return self::rowsOf(objects: $objects);
	}//end page()

	/**
	 * The arrays among the objects read, serialisable objects as arrays.
	 *
	 * @param array<int, mixed> $objects The objects read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rowsOf(array $objects): array {
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
	}//end rowsOf()
}//end class
