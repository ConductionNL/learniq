<?php

/**
 * Learniq Opening Hours Settings
 *
 * When the school's buildings are open, per weekday, and whether rooms close on
 * study days: the "hours open" of the room use report
 * (timetabling-room-utilisation). A tenant setting in app config, not a domain
 * object. Default Monday to Friday 08:00 to 17:00, weekend closed, rooms open
 * on study days.
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
 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the opening hours per weekday.
 *
 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */
class OpeningHoursSettings {

	public const CONFIG_KEY = 'room_opening_hours';

	public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

	private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config store.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The default: weekdays 08:00 to 17:00, weekend closed.
	 *
	 * @return array{weekdays:array<string,array{opens:string,closes:string}|null>,closedOnStudyDays:bool}
	 *
	 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function defaults(): array {
		$weekdays = [];
		foreach (self::WEEKDAYS as $index => $day) {
			$weekdays[$day] = null;
			if ($index < 5) {
				$weekdays[$day] = ['opens' => '08:00', 'closes' => '17:00'];
			}
		}

		return ['weekdays' => $weekdays, 'closedOnStudyDays' => false];
	}//end defaults()

	/**
	 * The stored opening hours, or the default.
	 *
	 * @return array{weekdays:array<string,array{opens:string,closes:string}|null>,closedOnStudyDays:bool}
	 *
	 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function get(): array {
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($decoded) === false || $this->validate(value: $decoded) !== null) {
			return $this->defaults();
		}

		return $this->clean(value: $decoded);
	}//end get()

	/**
	 * Why a value cannot be stored, or null when it can.
	 *
	 * @param mixed $value The submitted opening hours.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function validate(mixed $value): ?string {
		if (is_array($value) === false || is_array($value['weekdays'] ?? null) === false) {
			return 'The opening hours need a value per weekday.';
		}

		foreach ($value['weekdays'] as $day => $hours) {
			if (in_array($day, self::WEEKDAYS, true) === false) {
				return 'Unknown weekday: ' . (string)$day . '.';
			}

			if ($hours === null) {
				continue;
			}

			$opens = (string)($hours['opens'] ?? '');
			$closes = (string)($hours['closes'] ?? '');
			if (preg_match(self::TIME, $opens) !== 1 || preg_match(self::TIME, $closes) !== 1 || $opens >= $closes) {
				return 'Opening hours read like 08:00 to 17:00, and closing comes after opening.';
			}
		}

		return null;
	}//end validate()

	/**
	 * Store the opening hours.
	 *
	 * @param array<string,mixed> $value Valid opening hours (see validate()).
	 *
	 * @return array{weekdays:array<string,array{opens:string,closes:string}|null>,closedOnStudyDays:bool} What was stored.
	 *
	 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function save(array $value): array {
		$clean = $this->clean(value: $value);
		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, (string)json_encode($clean));
		return $clean;
	}//end save()

	/**
	 * Every weekday present, in order, with only opens and closes.
	 *
	 * @param array<string,mixed> $value Valid opening hours.
	 *
	 * @return array{weekdays:array<string,array{opens:string,closes:string}|null>,closedOnStudyDays:bool}
	 */
	private function clean(array $value): array {
		$weekdays = [];
		foreach (self::WEEKDAYS as $day) {
			$hours = ($value['weekdays'][$day] ?? null);
			$weekdays[$day] = null;
			if (is_array($hours) === true) {
				$weekdays[$day] = ['opens' => (string)$hours['opens'], 'closes' => (string)$hours['closes']];
			}
		}

		return ['weekdays' => $weekdays, 'closedOnStudyDays' => (($value['closedOnStudyDays'] ?? false) === true)];
	}//end clean()
}//end class
