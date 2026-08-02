<?php

/**
 * Feature gate for scholiq's corrected listener schema matching.
 *
 * Scholiq's OpenRegister listeners compared a schema **id** against a schema
 * **slug** literal, so their handler bodies had never run once. Correcting the
 * comparison is a small change, but it is not a behaviour-neutral one. The
 * listeners it wakes include:
 *
 *   - `EnrolmentPrerequisiteListener`, a **fail-closed** `ObjectCreatingEvent`
 *     validator that calls `stopPropagation()` and would start **rejecting
 *     Enrolment creations that succeed today**;
 *   - `AssessmentDrawResolver`, which writes back to `assessment-result` — the
 *     schema it listens on — with **no guard against re-drawing**, so a re-fire
 *     reshuffles a record its own docblock calls "written once, never
 *     recomputed";
 *   - `LearnerEngagementRollupHandler` and `CompetencyAttainmentRollupHandler`,
 *     which likewise write to the schema they listen on and fan out **N object
 *     creations per event**;
 *   - `SessionConflictListener`, which triggers an O(n²) window scan and
 *     **bulk-creates `timetable-conflict` rows** on every Session create *and*
 *     update;
 *   - `EngagementSignalHandler`, which writes an `engagement-score` plus one
 *     `engagement-risk-flag` per crossed threshold, over unbounded `findAll`
 *     reads.
 *
 * None of this has ever executed against real data, and there is no backfill —
 * so enabling it on an instance with existing data acts on that backlog the
 * first time a matching write occurs. That is a business decision, not a bug
 * fix.
 *
 * This gate exists so the correction can ship, be reviewed and be tested
 * without silently switching all of that on in one deploy. It mirrors
 * `openbuild.listener_slug_contract` (openbuild#82) and the default-off flag
 * openregister#2248 used for the sibling `ObjectTransitionedEvent` defect.
 *
 * Default: OFF. Enable per instance, only after reviewing each handler body:
 *   occ config:app:set scholiq listener_slug_contract --value=yes
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\Scholiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reports whether the corrected listener schema matching is enabled.
 */
class ListenerSlugContract
{

    /**
     * The config key holding the flag.
     *
     * @var string
     */
    private const CONFIG_KEY = 'listener_slug_contract';

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig Nextcloud app configuration.
     *
     * @return void
     */
    public function __construct(private readonly IAppConfig $appConfig)
    {
    }//end __construct()

    /**
     * Whether the corrected slug comparison should be honoured.
     *
     * @return bool True when the contract is enabled for this instance.
     */
    public function isEnabled(): bool
    {
        return $this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_KEY, false);

    }//end isEnabled()
}//end class
