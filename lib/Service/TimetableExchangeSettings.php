<?php
/**
 * Learniq TimetableExchangeSettings.
 *
 * The two exchange settings an administrator keeps on the admin page: which
 * cohort each rostering system's group code means, and which integriq
 * receiver takes the school's SWV hand-offs.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Listener\SupportRequestSubmitHandler;
use OCP\IAppConfig;

/**
 * Reads and writes the group code to cohort maps and the SWV receiver.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
 */
class TimetableExchangeSettings {
	/**
	 * App-config key holding `{rosterSource: {groupCode: cohortId}}` as JSON.
	 *
	 * @var string
	 */
	public const GROUP_MAPS_KEY = 'timetable_group_maps';

	/**
	 * The rostering systems integriq delivers from: its Source row ids, as
	 * PlanninqTimetableImport posts them (`roster-zermelo`, ...).
	 *
	 * @var array<int, string>
	 */
	public const ROSTER_SOURCES = ['roster-zermelo', 'roster-untis-oneroster', 'roster-xedule', 'roster-timeedit'];

	/**
	 * A receiver id: lowercase words joined by hyphens, as integriq names them.
	 *
	 * @var string
	 */
	private const RECEIVER_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Learniq's app config.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every source's map, one entry per known source, empty when none is kept.
	 *
	 * @return array<string, array<string, string>> Source => group code => cohort id.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	public function groupMaps(): array {
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::GROUP_MAPS_KEY, ''), true);
		$maps    = [];
		foreach (self::ROSTER_SOURCES as $source) {
			$maps[$source] = $this->cleanMap(value: ($decoded[$source] ?? null));
		}

		return $maps;
	}//end groupMaps()

	/**
	 * One source's map.
	 *
	 * @param string $source The rostering system.
	 *
	 * @return array<string, string> Group code => cohort id.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-import-without-a-posted-map-uses-the-kept-map
	 */
	public function groupMapFor(string $source): array {
		return ($this->groupMaps()[$source] ?? []);
	}//end groupMapFor()

	/**
	 * The integriq receiver of the school's SWV hand-offs, '' when none is set.
	 *
	 * @return string The receiver id.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	public function swvReceiverId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, SupportRequestSubmitHandler::RECEIVER_CONFIG_KEY, '');
	}//end swvReceiverId()

	/**
	 * Why the posted settings cannot be saved, or null when they can.
	 *
	 * @param mixed  $groupMaps  The posted maps.
	 * @param string $receiverId The posted receiver id.
	 *
	 * @return string|null The reason, in English (the controller translates it).
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	public function validate(mixed $groupMaps, string $receiverId): ?string {
		if ($receiverId !== '' && preg_match(self::RECEIVER_PATTERN, $receiverId) !== 1) {
			return 'The SWV receiver is a lowercase name such as swv-kindkans.';
		}

		if (is_array($groupMaps) === false) {
			return 'The group maps must be a list per rostering system.';
		}

		foreach (array_keys($groupMaps) as $source) {
			if (in_array($source, self::ROSTER_SOURCES, true) === false) {
				return 'Unknown rostering system: ' . (string)$source . '.';
			}
		}

		return null;
	}//end validate()

	/**
	 * Store the maps and the receiver. Call validate() first.
	 *
	 * @param array<string, mixed> $groupMaps  Source => group code => cohort id.
	 * @param string               $receiverId The receiver id, '' to clear it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	public function save(array $groupMaps, string $receiverId): void {
		$clean = [];
		foreach (self::ROSTER_SOURCES as $source) {
			$map = $this->cleanMap(value: ($groupMaps[$source] ?? null));
			if ($map !== []) {
				$clean[$source] = $map;
			}
		}

		$this->appConfig->setValueString(Application::APP_ID, self::GROUP_MAPS_KEY, (string)json_encode($clean));
		$this->appConfig->setValueString(Application::APP_ID, SupportRequestSubmitHandler::RECEIVER_CONFIG_KEY, $receiverId);
	}//end save()

	/**
	 * A map with only non-empty string codes and cohort ids, codes trimmed.
	 *
	 * @param mixed $value The candidate map.
	 *
	 * @return array<string, string> The clean map.
	 */
	private function cleanMap(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$map = [];
		foreach ($value as $code => $cohortId) {
			$code = trim((string)$code);
			if ($code !== '' && is_string($cohortId) === true && trim($cohortId) !== '') {
				$map[$code] = trim($cohortId);
			}
		}

		return $map;
	}//end cleanMap()
}//end class
