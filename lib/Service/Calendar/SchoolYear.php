<?php

/**
 * Learniq School Year
 *
 * The school year as the school sets it: the day it starts (app config
 * `school_year_start`, `MM-DD`, default `08-01`) and, from that, the school
 * year a date falls in and its first and last day. A school year is written
 * `YYYY-YYYY` after the calendar years it spans.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Calendar
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
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Calendar;

use DateTimeImmutable;
use OCA\Learniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The school's school year.
 *
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
class SchoolYear {

	/**
	 * App config key of the first day of the school year, `MM-DD`.
	 */
	public const KEY = 'school_year_start';

	private const DEFAULT_START = '08-01';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig|null $appConfig The app config; null reads the default start.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * The first day of the school year, `MM-DD`; an unusable setting reads as `08-01`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
	 */
	public function start(): string {
		$value = trim((string)($this->appConfig?->getValueString(Application::APP_ID, self::KEY, self::DEFAULT_START) ?? self::DEFAULT_START));
		if (preg_match('/^(\d{2})-(\d{2})$/', $value, $match) !== 1 || checkdate((int)$match[1], (int)$match[2], 2001) === false) {
			return self::DEFAULT_START;
		}

		return $value;
	}//end start()

	/**
	 * The school year a date falls in, `YYYY-YYYY`.
	 *
	 * @param string $date A date, `Y-m-d`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
	 */
	public function yearOf(string $date): string {
		$year = (int)substr($date, 0, 4);
		if (substr($date, 5, 5) < $this->start()) {
			$year--;
		}

		return sprintf('%04d-%04d', $year, $year + 1);
	}//end yearOf()

	/**
	 * The first and last day of a school year.
	 *
	 * @param string $academicYear `YYYY-YYYY`; its first year is the one it starts in.
	 *
	 * @return array{0: string, 1: string} `Y-m-d` bounds.
	 *
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
	 */
	public function bounds(string $academicYear): array {
		$first = (int)substr($academicYear, 0, 4);
		$from = new DateTimeImmutable(sprintf('%04d-%s', $first, $this->start()));

		return [$from->format('Y-m-d'), $from->modify('+1 year')->modify('-1 day')->format('Y-m-d')];
	}//end bounds()

	/**
	 * The first and last day of the school year a date falls in.
	 *
	 * @param string $date A date, `Y-m-d`.
	 *
	 * @return array{0: string, 1: string} `Y-m-d` bounds.
	 *
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
	 */
	public function around(string $date): array {
		return $this->bounds(academicYear: $this->yearOf(date: $date));
	}//end around()
}//end class
