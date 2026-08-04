<?php

/**
 * Scholiq Attendance Window Aggregator
 *
 * The attendance half of report-card composition, extracted from
 * `ReportCardComposer` so each class carries one cohesive responsibility: this
 * one owns the two-step Session-then-AttendanceRecord walk (resolve every
 * Session in a set of cohorts whose `[startsAt, endsAt]` overlaps the report
 * window, then aggregate one learner's AttendanceRecords for exactly those
 * sessions), while `ReportCardComposer` keeps the composition orchestration and
 * the grade side.
 *
 * The window-overlap rule mirrors `TimetableController::overlapsWindow()`: a
 * session overlaps when it starts before the window end AND ends at or after
 * the window start; unparseable window bounds include the session rather than
 * silently dropping it. `attendancePercent` is
 * `(present + late + leftEarly) / total`, null when the window has zero
 * sessions for the learner — the formula documented on
 * `ReportCard.attendanceSummary.attendancePercent`.
 *
 * Consumed by:
 *   - ReportCardComposer (constructor injection)
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
 *
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-composing-a-period-creates-one-reportcard-per-cohort-learner
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Resolves a report window's Sessions and aggregates a learner's attendance over them.
 */
class AttendanceWindowAggregator
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const SESSION_SCHEMA           = 'session';
    private const ATTENDANCE_RECORD_SCHEMA = 'attendance-record';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OR object access service.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Resolve every Session UUID in `cohortIds[]` whose `[startsAt, endsAt]`
     * overlaps `[startDate, endDate]`, mirroring
     * `TimetableController::fetchSessions()`'s window-overlap style.
     *
     * @param array<int,string> $cohortIds ReportPeriod.cohortIds.
     * @param string            $startDate Window start (ISO 8601 date).
     * @param string            $endDate   Window end (ISO 8601 date).
     *
     * @return array<int,string> Session UUIDs within the window.
     */
    public function fetchWindowSessionIds(array $cohortIds, string $startDate, string $endDate): array
    {
        $fromTs = strtotime($startDate);
        $toTs   = strtotime($endDate);

        $sessionIds = [];

        foreach ($cohortIds as $cohortId) {
            $rows = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::SESSION_SCHEMA,
                    'filters'  => ['cohortId' => $cohortId],
                    'limit'    => 5000,
                ]
            );

            foreach ($rows as $row) {
                $session = $this->normalise(row: $row);

                if ($this->sessionOverlapsWindow(session: $session, fromTs: $fromTs, toTs: $toTs) === false) {
                    continue;
                }

                $sessionId = (string) ($session['id'] ?? ($session['uuid'] ?? ''));
                if ($sessionId !== '') {
                    $sessionIds[$sessionId] = true;
                }
            }
        }//end foreach

        return array_keys($sessionIds);

    }//end fetchWindowSessionIds()

    /**
     * Whether a Session overlaps a `[fromTs, toTs]` window — starts before
     * the window end AND ends after (or at) the window start. When
     * unparseable window bounds are given, the session is included rather
     * than silently dropped (mirrors `TimetableController::overlapsWindow()`).
     *
     * @param array<string,mixed> $session The Session data.
     * @param int|false           $fromTs  Window start as a unix timestamp.
     * @param int|false           $toTs    Window end as a unix timestamp.
     *
     * @return bool True when the session overlaps the window.
     */
    private function sessionOverlapsWindow(array $session, int|false $fromTs, int|false $toTs): bool
    {
        if ($fromTs === false || $toTs === false) {
            return true;
        }

        $startsAt = (string) ($session['startsAt'] ?? '');
        if ($startsAt === '') {
            return false;
        }

        $startTs = strtotime($startsAt);
        if ($startTs === false) {
            return false;
        }

        $endsAtRaw = (string) ($session['endsAt'] ?? '');
        $endTs     = $startTs;
        if ($endsAtRaw !== '') {
            $endTs = strtotime($endsAtRaw);
        }

        if ($endTs === false) {
            $endTs = $startTs;
        }

        return ($startTs <= $toTs && $endTs >= $fromTs);

    }//end sessionOverlapsWindow()

    /**
     * Aggregate a learner's `AttendanceRecord`s whose `sessionId` is in
     * `$sessionIds` into an `attendanceSummary` object, mirroring the formula
     * documented on `ReportCard.attendanceSummary.attendancePercent`:
     * `(present + late + leftEarly) / total`, null when the window has zero
     * sessions for this learner.
     *
     * @param string            $learnerId  NC user ID.
     * @param array<int,string> $sessionIds Session UUIDs within the ReportPeriod's window.
     *
     * @return array<string,mixed>
     */
    public function buildAttendanceSummary(string $learnerId, array $sessionIds): array
    {
        $summary = [
            'presentCount'         => 0,
            'absentUnexcusedCount' => 0,
            'absentExcusedCount'   => 0,
            'lateCount'            => 0,
            'leftEarlyCount'       => 0,
            'attendancePercent'    => null,
        ];

        if (empty($sessionIds) === true) {
            return $summary;
        }

        $sessionIdSet = array_flip($sessionIds);

        $records = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ATTENDANCE_RECORD_SCHEMA,
                'filters'  => ['learnerId' => $learnerId],
                'limit'    => 5000,
            ]
        );

        $total = 0;

        foreach ($records as $row) {
            $record    = $this->normalise(row: $row);
            $sessionId = (string) ($record['sessionId'] ?? '');
            if ($sessionId === '' || isset($sessionIdSet[$sessionId]) === false) {
                continue;
            }

            $total++;

            // An unrecognised status still counts toward the total but has no
            // counter of its own, which is what the null arm means.
            $counter = match ((string) ($record['status'] ?? '')) {
                'present' => 'presentCount',
                'absent-unexcused' => 'absentUnexcusedCount',
                'absent-excused' => 'absentExcusedCount',
                'late' => 'lateCount',
                'left-early' => 'leftEarlyCount',
                default => null,
            };

            if ($counter !== null) {
                $summary[$counter] = ((int) $summary[$counter] + 1);
            }
        }//end foreach

        if ($total > 0) {
            $attended = $summary['presentCount'] + $summary['lateCount'] + $summary['leftEarlyCount'];
            $summary['attendancePercent'] = round(($attended / $total) * 100, 2);
        }

        return $summary;

    }//end buildAttendanceSummary()

    /**
     * Normalise an ObjectService row/entity to a plain array.
     *
     * @param mixed $row Raw row from ObjectService::findAll()/find().
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        return $row->jsonSerialize();

    }//end normalise()
}//end class
