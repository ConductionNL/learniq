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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-server-resolves-one-current-segment
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Learniq\Kernel\Contracts\SegmentServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the current segment from the `LearniqSettings` singleton.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-server-resolves-one-current-segment
 */
class SegmentService implements SegmentServiceInterface {

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug of the settings singleton.
	 */
	private const SETTINGS_SCHEMA = 'learniqsettings';

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
	 * @param IUserManager    $userManager   Tells a real chooser from a generated demo name.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly IUserManager $userManager,
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
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-server-resolves-one-current-segment
	 */
	public function currentSegment(): string {
		$current = $this->currentRow();
		if ($current === null) {
			return self::DEFAULT_SEGMENT;
		}

		return $current['segment'];
	}//end currentSegment()

	/**
	 * The segment an admin actually chose, or null.
	 *
	 * 🔴 A CHOICE NAMES A PERSON. The newest valid row counts only when its
	 * `setBy` is an existing Nextcloud user. The setup wizard always writes the
	 * admin's uid; the generated demo register ships three `corporate` rows
	 * whose `setBy` is a fictional name, and loading demo data must not change
	 * which menus render (segment-runtime-bridge). A menu that hides for a
	 * chosen company (`visibleIf: {"workspace.chosenSegment": {"notIn":
	 * ["corporate"]}}`) therefore stays visible on every install that never
	 * chose (decision D26).
	 *
	 * @return string|null One of SEGMENTS, or null when nobody chose.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-page-tells-a-chosen-segment-apart-from-the-default
	 */
	public function chosenSegment(): ?string {
		return $this->workspace()['chosenSegment'];
	}//end chosenSegment()

	/**
	 * The segment and the chosen segment from one read, for the page's
	 * `runtime.workspace`.
	 *
	 * @return array{segment: string, chosenSegment: string|null} The two values.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-page-tells-a-chosen-segment-apart-from-the-default
	 */
	public function workspace(): array {
		$current = $this->currentRow();
		if ($current === null) {
			return ['segment' => self::DEFAULT_SEGMENT, 'chosenSegment' => null];
		}

		$chosen = null;
		if ($this->namesAUser(uid: $current['setBy']) === true) {
			$chosen = $current['segment'];
		}

		return ['segment' => $current['segment'], 'chosenSegment' => $chosen];
	}//end workspace()

	/**
	 * Whether a row's `setBy` is an existing Nextcloud user.
	 *
	 * @param string|null $uid The stored `setBy`.
	 *
	 * @return bool True for a known user id.
	 */
	private function namesAUser(?string $uid): bool {
		if ($uid === null || $uid === '') {
			return false;
		}

		try {
			return $this->userManager->userExists($uid);
		} catch (Throwable $e) {
			$this->logger->info(
				'[SegmentService] could not check setBy ({message}); treating the segment as not chosen.',
				['message' => $e->getMessage()]
			);
			return false;
		}
	}//end namesAUser()

	/**
	 * Whether a valid segment has been stored at all.
	 *
	 * The setup wizard's `segment` step is done once this is true, so an admin
	 * who already chose on the App settings page is not asked again.
	 *
	 * @return bool True when a LearniqSettings row carries a known segment.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
	 */
	public function hasSegment(): bool {
		return $this->currentRow() !== null;
	}//end hasSegment()

	/**
	 * The six cards the setup wizard's `segment` step offers.
	 *
	 * @return array<int, array{id: string, label: string, description: string, icon: string}> The cards, in SEGMENTS order.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
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
	 * @return array{segment: string, uuid: string|null, setBy: string|null}|null The row's segment, uuid and chooser.
	 */
	private function currentRow(): ?array {
		$rows = $this->settingsRows();
		if ($rows === null) {
			return null;
		}

		$current = null;
		// An update time is never negative, so the first row with a known segment is always taken.
		$newest = -1;
		foreach ($this->knownRows(rows: $rows) as $data) {
			$updated = $this->updatedAt(row: $data);
			if ($updated > $newest) {
				$newest  = $updated;
				$current = $this->segmentRow(row: $data);
			}
		}

		return $current;
	}//end currentRow()

	/**
	 * The LearniqSettings rows, or null (logged) when they cannot be read.
	 *
	 * @return array<int,mixed>|null
	 */
	private function settingsRows(): ?array {
		try {
			return $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::LEARNIQ_REGISTER,
						'schema'   => self::SETTINGS_SCHEMA,
					],
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
	}//end settingsRows()

	/**
	 * The serialised rows that name one of the known segments, in order.
	 *
	 * @param array<int,mixed> $rows The rows as read.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function knownRows(array $rows): array {
		$known = [];
		foreach ($rows as $row) {
			$data = $this->toRow(object: $row);
			if ($this->hasKnownSegment(row: $data) === true) {
				$known[] = $data;
			}
		}

		return $known;
	}//end knownRows()

	/**
	 * Whether a serialised row names one of the known segments.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return bool
	 */
	private function hasKnownSegment(array $row): bool {
		$code = ($row['segment'] ?? null);
		return is_string($code) === true && in_array($code, self::SEGMENTS, true) === true;
	}//end hasKnownSegment()

	/**
	 * The segment, uuid and chooser of a row with a known segment; a chooser that is not text is null.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return array{segment: string, uuid: string|null, setBy: string|null}
	 */
	private function segmentRow(array $row): array {
		$setBy = ($row['setBy'] ?? null);
		if (is_string($setBy) === false) {
			$setBy = null;
		}

		return [
			'segment' => (string)$row['segment'],
			'uuid'    => $this->uuidOf(row: $row),
			'setBy'   => $setBy,
		];
	}//end segmentRow()

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
