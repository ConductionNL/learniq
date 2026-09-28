<?php

/**
 * Learniq Timetable Source Resolver
 *
 * Decides where learniq reads timetable sessions from: planninq when it is
 * installed and ships its query event (decision D10), learniq's own `Session`
 * schema otherwise. The app config `timetable_source` set to `learniq` forces
 * the local source, a switch that needs no deploy.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling\Source
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
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling\Source;

use OCP\IAppConfig;

/**
 * Picks the current timetable source.
 *
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
 */
class TimetableSourceResolver {

	public const CONFIG_KEY = 'timetable_source';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig                  $appConfig Holds the `timetable_source` switch.
	 * @param LocalSessionTimetableSource $local     Learniq's own Session schema.
	 * @param PlanninqTimetableSource     $planninq  Planninq's timetable.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LocalSessionTimetableSource $local,
		private readonly PlanninqTimetableSource $planninq,
	) {
	}//end __construct()

	/**
	 * The source every timetable reader uses now.
	 *
	 * @return TimetableSource
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
	 */
	public function current(): TimetableSource {
		if ($this->appConfig->getValueString('learniq', self::CONFIG_KEY, 'auto') === LocalSessionTimetableSource::NAME) {
			return $this->local;
		}

		if ($this->planninq->isAvailable() === true) {
			return $this->planninq;
		}

		return $this->local;
	}//end current()

	/**
	 * Whether planninq is the current source.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
	 */
	public function usesPlanninq(): bool {
		return $this->current()->name() === PlanninqTimetableSource::NAME;
	}//end usesPlanninq()

	/**
	 * The planninq source, for a caller that must read planninq specifically.
	 *
	 * @return PlanninqTimetableSource
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004
	 */
	public function planninq(): PlanninqTimetableSource {
		return $this->planninq;
	}//end planninq()
}//end class
