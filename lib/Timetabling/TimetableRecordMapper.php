<?php

/**
 * Scholiq Timetable Record Mapper
 *
 * The record-shaping half of a `timetable-import` DataExchangeJob, split out
 * of {@see \OCA\Scholiq\Timetabling\TimetableImportHandler} so that the
 * handler owns only the job lifecycle (OpenConnector call, upsert, state
 * transition) while this collaborator owns the pure, side-effect-free
 * translation of one inbound external record into a Session-shaped array.
 *
 * A DataMappingProfile's fieldMappings are read in REVERSE for
 * `direction: import`: `scholiqField` names the Scholiq-side (Session) field
 * and `targetField` names the external-side field, so each inbound record's
 * `targetField` value is resolved into the matching `scholiqField`. With no
 * usable profile the mapper falls back to a best-effort passthrough over the
 * common field names.
 *
 * This class performs NO I/O: it never reads or writes an OpenRegister object,
 * which is what makes the import handler's validate-before-dequeue posture
 * testable in isolation.
 *
 * @category Service
 * @package  OCA\Scholiq\Timetabling
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-timetable-import-delegates-the-wire-protocol-to-openconnector-via-dataexchangejob
 */

declare(strict_types=1);

namespace OCA\Scholiq\Timetabling;

/**
 * Maps one inbound external timetable record onto a Session-shaped array and
 * reports which required Session fields a mapped record still lacks.
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-timetable-import-delegates-the-wire-protocol-to-openconnector-via-dataexchangejob
 */
class TimetableRecordMapper
{

    /**
     * Session fields copied straight across when no usable DataMappingProfile
     * is available — a best-effort passthrough over the common field names.
     *
     * @var string[]
     */
    private const PASSTHROUGH_FIELDS = ['externalRef', 'cohortId', 'title', 'startsAt', 'endsAt', 'location'];

    /**
     * Fields every mapped Session record must carry (non-empty) to pass
     * validate-before-dequeue.
     *
     * @var string[]
     */
    private const REQUIRED_SESSION_FIELDS = ['cohortId', 'title', 'startsAt', 'endsAt', 'externalRef'];

    /**
     * The transform name that re-formats a mapped value as an ISO 8601 date.
     *
     * @var string
     */
    private const TRANSFORM_DATE_ISO8601 = 'date-iso8601';

    /**
     * Map one inbound external record into a Session-shaped array, applying
     * the profile's fieldMappings in reverse (targetField -> scholiqField).
     *
     * @param array<string,mixed>      $record   The raw external record.
     * @param array<string,mixed>|null $profile  The DataMappingProfile, or null for a best-effort passthrough.
     * @param string                   $tenantId Tenant to stamp onto the mapped record.
     *
     * @return array<string,mixed> The mapped, Session-shaped record.
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-re-importing-the-same-timetable-does-not-duplicate-sessions
     */
    public function map(array $record, ?array $profile, string $tenantId): array
    {
        $fieldMappings = $profile['fieldMappings'] ?? [];

        if (is_array($fieldMappings) === false || empty($fieldMappings) === true) {
            return array_merge(['tenant_id' => $tenantId], $this->passthrough(record: $record));
        }

        $applied = $this->applyMappings(record: $record, fieldMappings: $fieldMappings);

        return array_merge(['tenant_id' => $tenantId], $applied);

    }//end map()

    /**
     * List the required Session fields missing (absent or empty) from a mapped record.
     *
     * @param array<string,mixed> $record The mapped record.
     *
     * @return array<int,string> The missing field names.
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-timetable-import-job-delegates-to-openconnector-and-reports-its-result
     */
    public function missingRequiredFields(array $record): array
    {
        $missing = [];
        foreach (self::REQUIRED_SESSION_FIELDS as $field) {
            $value = $record[$field] ?? null;
            if (is_string($value) === false || $value === '') {
                $missing[] = $field;
            }
        }

        return $missing;

    }//end missingRequiredFields()

    /**
     * Best-effort passthrough used when the job carries no usable profile:
     * copy across every common Session field the record happens to set.
     *
     * @param array<string,mixed> $record The raw external record.
     *
     * @return array<string,mixed> The copied fields.
     */
    private function passthrough(array $record): array
    {
        $mapped = [];
        foreach (self::PASSTHROUGH_FIELDS as $field) {
            if (isset($record[$field]) === true) {
                $mapped[$field] = $record[$field];
            }
        }

        return $mapped;

    }//end passthrough()

    /**
     * Apply a profile's fieldMappings in reverse (targetField -> scholiqField).
     *
     * @param array<string,mixed> $record        The raw external record.
     * @param array<int,mixed>    $fieldMappings The profile's fieldMappings entries.
     *
     * @return array<string,mixed> The mapped fields.
     */
    private function applyMappings(array $record, array $fieldMappings): array
    {
        $mapped = [];

        foreach ($fieldMappings as $mapping) {
            $scholiqField = $mapping['scholiqField'] ?? '';
            $targetField  = $mapping['targetField'] ?? '';

            if ($scholiqField === '' || $targetField === '' || array_key_exists($targetField, $record) === false) {
                continue;
            }

            $mapped[$scholiqField] = $this->transformValue(
                value: $record[$targetField],
                transform: ($mapping['transform'] ?? null)
            );
        }//end foreach

        return $mapped;

    }//end applyMappings()

    /**
     * Apply a single fieldMapping transform to one value. An unknown transform,
     * a non-string value, an empty string, or an unparseable date all leave the
     * value untouched.
     *
     * @param mixed $value     The raw external value.
     * @param mixed $transform The transform declared on the fieldMapping, if any.
     *
     * @return mixed The transformed value.
     */
    private function transformValue(mixed $value, mixed $transform): mixed
    {
        if ($transform !== self::TRANSFORM_DATE_ISO8601 || is_string($value) === false || $value === '') {
            return $value;
        }

        $stamp = strtotime($value);
        if ($stamp === false) {
            return $value;
        }

        return date('c', $stamp);

    }//end transformValue()
}//end class
