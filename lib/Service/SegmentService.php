<?php

/**
 * Learniq Segment Service
 *
 * Resolves the one segment this instance runs as: primary school, secondary
 * school, MBO, higher education, company or training institute. The value is
 * stored on the `LearniqSettings` singleton and published to the browser by
 * PageController, where `src/main.js` puts it at
 * `manifest.runtime.workspace.segment` for menu `visibleIf` predicates.
 *
 * Legitimate PHP per ADR-031: framework glue that hands a stored value to the
 * page, the same category as DashboardRoleService's role provider. OpenRegister
 * has no declaration that pushes a field into another app's initial state.
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
 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#requirement-the-server-resolves-one-current-segment
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the current segment from the `LearniqSettings` singleton.
 *
 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#requirement-the-server-resolves-one-current-segment
 */
class SegmentService {

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug of the settings singleton.
	 */
	private const SETTINGS_SCHEMA = 'learniqsettings';

	/**
	 * The six segment codes, in the order the schema enum declares them.
	 *
	 * 🔴 THREE COPIES, ONE TRUTH. The schema enum in learniq_register.json, this
	 * constant, and `SEGMENTS` in src/utils/workspaceRuntime.js must agree; a
	 * unit test on each side compares its copy to the schema, so a value added
	 * in one place and forgotten in another fails a test instead of silently
	 * falling back to the default.
	 *
	 * @var string[]
	 */
	public const SEGMENTS = ['po', 'vo', 'mbo', 'he', 'corporate', 'training'];

	/**
	 * The schema default: the no-behaviour-change segment every existing
	 * customer runs today.
	 */
	public const DEFAULT_SEGMENT = 'corporate';

	/**
	 * How many settings rows the resolver reads at most.
	 *
	 * A singleton normally has one row; the generated demo data adds three.
	 * The cap keeps the page request cheap when something went badly wrong.
	 */
	private const MAX_ROWS = 50;

	/**
	 * The card the setup wizard shows for each segment, in SEGMENTS order.
	 *
	 * English source strings; the wizard translates `label` and `description`
	 * through the app catalogue by literal lookup, so neither may interpolate
	 * anything.
	 *
	 * @var array<string, array{label: string, description: string, icon: string}>
	 */
	private const CHOICES = [
		'po'        => [
			'label'       => 'Primary school',
			'description' => 'Groups 1 to 8, guardians, report cards and pupil tracking.',
			'icon'        => 'SchoolOutline',
		],
		'vo'        => [
			'label'       => 'Secondary school',
			'description' => 'Classes, subjects, report cards and exam preparation.',
			'icon'        => 'School',
		],
		'mbo'       => [
			'label'       => 'Vocational education (MBO)',
			'description' => 'Programmes, work placements and progress per student.',
			'icon'        => 'BriefcaseOutline',
		],
		'he'        => [
			'label'       => 'Higher education (HBO or university)',
			'description' => 'Programmes, credits and first-year study advice.',
			'icon'        => 'CertificateOutline',
		],
		'corporate' => [
			'label'       => 'Company',
			'description' => 'Mandatory training, certificates and compliance for staff.',
			'icon'        => 'OfficeBuilding',
		],
		'training'  => [
			'label'       => 'Training institute',
			'description' => 'Courses and trainings for people from outside the organisation.',
			'icon'        => 'AccountSchoolOutline',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OR object service for the singleton read.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The segment this instance runs as.
	 *
	 * 🔴 THE NEWEST VALID ROW WINS, NOT THE FIRST ONE. OpenRegister does not
	 * enforce a singleton, and the generated demo register ships three rows of
	 * this schema. Taking `findAll()[0]` would make the answer depend on storage
	 * order, so loading example data could switch an instance's menus. The last
	 * deliberate write (the settings page or the setup wizard) is always the
	 * most recently updated row.
	 *
	 * 🔴 NO RBAC ON THIS READ. Every signed-in user's menu depends on the value,
	 * the schema has no authorization block of its own, and the register
	 * cascade does not include learners. Only the segment code leaves this
	 * method, validated against SEGMENTS.
	 *
	 * @return string One of SEGMENTS; DEFAULT_SEGMENT when nothing valid is stored.
	 *
	 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#requirement-the-server-resolves-one-current-segment
	 */
	public function currentSegment(): string {
		$current = $this->currentRow();
		if ($current === null) {
			return self::DEFAULT_SEGMENT;
		}

		return $current['segment'];
	}//end currentSegment()

	/**
	 * Whether a valid segment has been stored at all.
	 *
	 * The setup wizard's `segment` step is done once this is true, so an admin
	 * who already chose on the App settings page is not asked again.
	 *
	 * @return bool True when a LearniqSettings row carries a known segment.
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
	 */
	public function hasSegment(): bool {
		return $this->currentRow() !== null;
	}//end hasSegment()

	/**
	 * The six cards the setup wizard's `segment` step offers.
	 *
	 * @return array<int, array{id: string, label: string, description: string, icon: string}> The cards, in SEGMENTS order.
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
	 */
	public function listChoices(): array {
		$choices = [];
		foreach (self::SEGMENTS as $code) {
			$choices[] = array_merge(['id' => $code], self::CHOICES[$code]);
		}

		return $choices;
	}//end listChoices()

	/**
	 * Store the instance segment.
	 *
	 * Updates the row currentSegment() reads, or creates the record when none
	 * holds a valid value, so the answer is what the next page load publishes.
	 * Runs without RBAC: it is only called from the admin-only setup contract,
	 * which ADR-042 runs with system privileges.
	 *
	 * @param string      $segment One of SEGMENTS.
	 * @param string|null $actor   Nextcloud user id of the admin who chose it.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the segment is not one of SEGMENTS.
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
	 */
	public function setSegment(string $segment, ?string $actor): void {
		if (in_array($segment, self::SEGMENTS, true) === false) {
			throw new InvalidArgumentException('Unknown segment "' . $segment . '".');
		}

		$data = [
			'segment' => $segment,
			'setBy'   => $actor,
			'setAt'   => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		$current = $this->currentRow();
		$this->objectService->saveObject(
			object: $data,
			register: self::LEARNIQ_REGISTER,
			schema: self::SETTINGS_SCHEMA,
			uuid: ($current['uuid'] ?? null),
			_rbac: false
		);
	}//end setSegment()

	/**
	 * The row currentSegment() answers from: the most recently updated row
	 * with a known segment, or null.
	 *
	 * @return array{segment: string, uuid: string|null}|null The row's segment and uuid.
	 */
	private function currentRow(): ?array {
		try {
			$rows = $this->objectService->findAll(
				[
					'register' => self::LEARNIQ_REGISTER,
					'schema'   => self::SETTINGS_SCHEMA,
					'limit'    => self::MAX_ROWS,
				],
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'[SegmentService] LearniqSettings read failed ({message}); defaulting to "{default}".',
				['message' => $e->getMessage(), 'default' => self::DEFAULT_SEGMENT]
			);
			return null;
		}

		$current = null;
		$newest  = null;
		foreach ($rows as $row) {
			$data = $this->toRow(object: $row);
			$code = ($data['segment'] ?? null);
			if (is_string($code) === false || in_array($code, self::SEGMENTS, true) === false) {
				continue;
			}

			$updated = $this->updatedAt(row: $data);
			if ($newest === null || $updated > $newest) {
				$newest  = $updated;
				$current = [
					'segment' => $code,
					'uuid'    => $this->uuidOf(row: $data),
				];
			}
		}

		return $current;
	}//end currentRow()

	/**
	 * The uuid of a serialised row, or null when it carries none.
	 *
	 * @param array<string, mixed> $row The serialised row.
	 *
	 * @return string|null The uuid.
	 */
	private function uuidOf(array $row): ?string {
		foreach ([($row['@self']['id'] ?? null), ($row['id'] ?? null), ($row['uuid'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end uuidOf()

	/**
	 * Normalise a findAll() entry to a plain array.
	 *
	 * @param mixed $object An ObjectEntity-like object or an array.
	 *
	 * @return array<string, mixed> The row, or an empty array when unreadable.
	 */
	private function toRow(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$data = $object->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toRow()

	/**
	 * The row's last-update time as a Unix timestamp, 0 when unknown.
	 *
	 * Reads OpenRegister's own `@self.updated` metadata, which every write sets,
	 * rather than `setAt`, which only a client that remembers to write it fills.
	 *
	 * @param array<string, mixed> $row The serialised row.
	 *
	 * @return int The timestamp.
	 */
	private function updatedAt(array $row): int {
		$updated = ($row['@self']['updated'] ?? null);
		if (is_string($updated) === false || $updated === '') {
			return 0;
		}

		$time = strtotime($updated);
		if ($time === false) {
			return 0;
		}

		return $time;
	}//end updatedAt()
}//end class
