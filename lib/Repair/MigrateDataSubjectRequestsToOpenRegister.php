<?php

/**
 * Repair step moving learniq's own DataSubjectRequest rows into OpenRegister's
 * shared data-subject-requests register (D20, privacy-reuse-openregister-register).
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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies every learniq `data-subject-request` row into OpenRegister's
 * `data-subject-requests` register as a `dataSubjectRequest` case.
 *
 * WHY. OpenRegister ships the fleet's data subject request register, with six
 * request types, the art-12 deadline, escalation and a handling service.
 * Learniq's own schema was a smaller copy (two kinds, four states), so D20
 * retires it and points the pages at OpenRegister's register.
 *
 * MAPPING. `kind` correction → `type` rectification, deletion → erasure;
 * `learnerId` → `subjectId` with `subjectType` `nextcloud-user`; `requestedAt`
 * → `receivedAt`; `decidedAt` → `closedAt`; lifecycle requested → received,
 * in-review → in-progress, completed → fulfilled, rejected → refused;
 * `submittedBy` → `handler`, so the staff member who logged the request gets
 * OpenRegister's deadline reminders. The description and the audit trail have
 * no field of their own in OpenRegister, so they are written into `notes`
 * under a first line that names the source row.
 *
 * IDEMPOTENT ON THE TARGET, the way pipelinq's MigrateAvgVerzoekenToOrDsar is:
 * each case's `notes` starts with `learniq-migration: <source uuid>`, and a
 * re-run skips every source that already has a case. The source rows are left
 * in place: nothing reads them any more, and keeping them costs nothing while
 * a school checks the migrated cases.
 *
 * ORDER. Runs before InitializeSettings, while the learniq schema and its rows
 * are certainly still there. OpenRegister imports its own register on its own
 * upgrade; when it is not there yet the saves fail, are counted, and the next
 * upgrade retries.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
 */
class MigrateDataSubjectRequestsToOpenRegister implements IRepairStep {

	private const LEARNIQ_REGISTER = 'learniq';
	private const LEARNIQ_SCHEMA = 'data-subject-request';
	private const OR_REGISTER = 'data-subject-requests';
	private const OR_SCHEMA = 'dataSubjectRequest';

	/**
	 * First-line marker in a migrated case's `notes`.
	 */
	public const MARKER = 'learniq-migration: ';

	/**
	 * Page size for both reads.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Hard stop so a paging defect can never loop forever.
	 */
	private const MAX_PAGES = 1000;

	/**
	 * learniq `kind` → OpenRegister `type`.
	 *
	 * @var array<string, string>
	 */
	private const KIND_TO_TYPE = [
		'correction' => 'rectification',
		'deletion' => 'erasure',
	];

	/**
	 * learniq lifecycle → OpenRegister `status`.
	 *
	 * @var array<string, string>
	 */
	private const LIFECYCLE_TO_STATUS = [
		'requested' => 'received',
		'in-review' => 'in-progress',
		'completed' => 'fulfilled',
		'rejected' => 'refused',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
	 */
	public function getName(): string {
		return 'Move correction and deletion requests to the OpenRegister data subject request register';
	}//end getName()

	/**
	 * Copy every not yet migrated learniq request into OpenRegister.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
	 */
	public function run(IOutput $output): void {
		try {
			$sources = $this->readAll(register: self::LEARNIQ_REGISTER, schema: self::LEARNIQ_SCHEMA);
		} catch (Throwable $exception) {
			// A fresh install, or the schema already gone: nothing to move.
			$output->info('Learniq privacy requests: no learniq data subject requests to move (' . $exception->getMessage() . ').');
			return;
		}

		if ($sources === []) {
			$output->info('Learniq privacy requests: no learniq data subject requests to move.');
			return;
		}

		try {
			$migrated = $this->alreadyMigrated();
		} catch (Throwable $exception) {
			// Without knowing what exists, a run could duplicate cases: stop.
			$output->warning('Learniq privacy requests: could not read the OpenRegister register, nothing moved (' . $exception->getMessage() . ').');
			return;
		}

		$counts = ['moved' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($sources as $row) {
			$counts[$this->migrateOne(row: $row, migrated: $migrated)]++;
		}

		$summary = sprintf(
			'Learniq privacy requests: %d moved, %d already moved, %d failed (of %d).',
			$counts['moved'],
			$counts['skipped'],
			$counts['failed'],
			count($sources)
		);
		$output->info($summary);
		$this->logger->info('[MigrateDataSubjectRequestsToOpenRegister] ' . $summary);
	}//end run()

	/**
	 * Move one source row, returning the outcome.
	 *
	 * @param array<string, mixed> $row      The learniq request.
	 * @param array<string, bool>  $migrated Source uuids that already have a case.
	 *
	 * @return string One of moved, skipped, failed.
	 */
	private function migrateOne(array $row, array $migrated): string {
		$sourceId = $this->idOf(row: $row);
		if ($sourceId === '') {
			return 'failed';
		}

		if (isset($migrated[$sourceId]) === true) {
			return 'skipped';
		}

		try {
			$this->objectService->saveObject(
				object: self::toCase(row: $row, sourceId: $sourceId),
				register: self::OR_REGISTER,
				schema: self::OR_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[MigrateDataSubjectRequestsToOpenRegister] Could not move request {id}: {msg}',
				['id' => $sourceId, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}

		return 'moved';
	}//end migrateOne()

	/**
	 * Map one learniq request onto an OpenRegister dataSubjectRequest case.
	 *
	 * @param array<string, mixed> $row      The learniq request.
	 * @param string               $sourceId Its uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
	 */
	public static function toCase(array $row, string $sourceId): array {
		$kind = (string)($row['kind'] ?? '');
		$lifecycle = (string)($row['lifecycle'] ?? 'requested');
		$status = (self::LIFECYCLE_TO_STATUS[$lifecycle] ?? 'received');

		$case = [
			'subjectId' => (string)($row['learnerId'] ?? ''),
			'subjectType' => 'nextcloud-user',
			'type' => (self::KIND_TO_TYPE[$kind] ?? 'rectification'),
			'status' => $status,
			'receivedAt' => (string)($row['requestedAt'] ?? ''),
			'handler' => (string)($row['submittedBy'] ?? ''),
			'notes' => self::notesFor(row: $row, sourceId: $sourceId),
		];

		$decidedAt = ($row['decidedAt'] ?? null);
		if (is_string($decidedAt) === true && $decidedAt !== '' && in_array($status, ['fulfilled', 'refused'], true) === true) {
			$case['closedAt'] = $decidedAt;
		}

		return array_filter($case, static fn ($value): bool => $value !== '');
	}//end toCase()

	/**
	 * The case notes: the marker line, then the description and the audit trail.
	 *
	 * @param array<string, mixed> $row      The learniq request.
	 * @param string               $sourceId Its uuid.
	 *
	 * @return string
	 */
	private static function notesFor(array $row, string $sourceId): string {
		$lines = [self::MARKER . $sourceId];

		$kind = (string)($row['kind'] ?? '');
		if ($kind !== '' && isset(self::KIND_TO_TYPE[$kind]) === false) {
			$lines[] = 'Original kind: ' . $kind;
		}

		$description = ($row['description'] ?? null);
		if (is_string($description) === true && trim($description) !== '') {
			$lines[] = '';
			$lines[] = trim($description);
		}

		$history = self::historyLines(trail: ($row['auditTrail'] ?? []));
		if ($history !== []) {
			$lines[] = '';
			$lines[] = 'History:';
			array_push($lines, ...$history);
		}

		// OpenRegister caps notes at 4000 characters.
		return mb_substr(implode("\n", $lines), 0, 4000);
	}//end notesFor()

	/**
	 * One line per audit trail entry: when, who, what, and the note if any.
	 *
	 * @param mixed $trail The learniq `auditTrail` value.
	 *
	 * @return array<int, string>
	 */
	private static function historyLines(mixed $trail): array {
		if (is_array($trail) === false) {
			return [];
		}

		$lines = [];
		foreach ($trail as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$line = '- ' . (string)($entry['recordedAt'] ?? '') . ' ' . (string)($entry['recordedBy'] ?? '')
				. ': ' . (string)($entry['action'] ?? '');
			$note = ($entry['note'] ?? null);
			if (is_string($note) === true && $note !== '') {
				$line .= ' (' . $note . ')';
			}

			$lines[] = $line;
		}

		return $lines;
	}//end historyLines()

	/**
	 * Source uuids that already have a case, read from the marker line.
	 *
	 * @return array<string, bool>
	 */
	private function alreadyMigrated(): array {
		$migrated = [];
		foreach ($this->readAll(register: self::OR_REGISTER, schema: self::OR_SCHEMA) as $case) {
			$notes = ($case['notes'] ?? null);
			if (is_string($notes) === false || str_starts_with($notes, self::MARKER) === false) {
				continue;
			}

			$firstLine = strtok(substr($notes, strlen(self::MARKER)), "\n");
			if (is_string($firstLine) === true && trim($firstLine) !== '') {
				$migrated[trim($firstLine)] = true;
			}
		}

		return $migrated;
	}//end alreadyMigrated()

	/**
	 * Every row of one schema, as arrays, without RBAC (no session in a repair step).
	 *
	 * @param string $register Register slug.
	 * @param string $schema   Schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readAll(string $register, string $schema): array {
		$rows = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$objects = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => self::PAGE_SIZE,
					'offset' => ($page * self::PAGE_SIZE),
				],
				_rbac: false,
				_multitenancy: false
			);

			foreach ($objects as $object) {
				if (is_array($object) === true) {
					$rows[] = $object;
					continue;
				}

				if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
					$rows[] = (array)$object->jsonSerialize();
				}
			}

			if (count($objects) < self::PAGE_SIZE) {
				break;
			}
		}//end for

		return $rows;
	}//end readAll()

	/**
	 * The object uuid of a serialised row: `id`, `uuid`, or `@self.id`.
	 *
	 * @param array<string, mixed> $row Serialised row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($row['@self']['id'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return '';
	}//end idOf()
}//end class
