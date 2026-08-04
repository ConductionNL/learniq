<?php

/**
 * Scholiq Data Exchange Transformer
 *
 * Applies a `DataMappingProfile.fieldMappings` entry's named `transform` to a
 * single field value:
 *   - bsn-to-pseudonym  → use LearnerProfile.eckId, NEVER bsnEncrypted.
 *   - date-iso8601      → ensure ISO 8601 date format.
 *   - cohort-to-brin    → look up the Cohort's school BRIN from Cohort.brinNumber.
 *   - null (passthrough)→ copy value unchanged.
 *
 * Extracted out of `DataExchangeRunHandler` together with
 * `DataExchangePayloadBuilder` so that listener stays an orchestrator of the
 * OpenConnector call, and so all three classes stay within this app's PHPMD
 * complexity budget.
 *
 * This is the ADR-031 "external-system bridge" exception: no wire protocol
 * lives here either — only the small in-PHP value transformer the mapping
 * profile declares.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Applies a mapping profile's named field transforms.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
 */
class DataExchangeTransformer
{
    private const SCHOLIQ_REGISTER = 'scholiq';
    private const COHORT_SCHEMA    = 'cohort';

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
     * Apply a named transform to a field value.
     *
     * @param mixed               $value     The raw field value.
     * @param string|null         $transform Transform name: bsn-to-pseudonym, date-iso8601,
     *                                       cohort-to-brin, or null for passthrough.
     * @param array<string,mixed> $object    The full source object (for context lookups).
     *
     * @return mixed The transformed value.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    public function applyTransform(mixed $value, ?string $transform, array $object): mixed
    {
        return match ($transform) {
            'bsn-to-pseudonym' => $this->transformBsnToPseudonym(object: $object),
            'date-iso8601' => $this->transformDateIso8601(value: $value),
            'cohort-to-brin' => $this->transformCohortToBrin(value: $value),
            default => $value,
        };

    }//end applyTransform()

    /**
     * Resolve the ECK iD pseudonym that stands in for a learner's BSN.
     *
     * BSN MUST NEVER leave Scholiq. #206: when `eckId` is absent this aborts the
     * whole job (fail-closed) rather than shipping a null pseudonym, because a
     * null value in the payload might make the receiving system fall back to an
     * unencrypted BSN field.
     *
     * @param array<string,mixed> $object The full source object.
     *
     * @return string The ECK iD pseudonym.
     *
     * @throws \RuntimeException When the object carries no eckId.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    private function transformBsnToPseudonym(array $object): string
    {
        $eckId = $object['eckId'] ?? null;
        if ($eckId === null || $eckId === '') {
            $objectId = $object['id'] ?? ($object['uuid'] ?? 'unknown');
            throw new RuntimeException(
                "bsn-to-pseudonym: object {$objectId} has no eckId — job aborted to prevent BSN leakage."
            );
        }

        return (string) $eckId;

    }//end transformBsnToPseudonym()

    /**
     * Normalise a value to an ISO-8601 calendar date.
     *
     * An unparseable value is passed through untouched rather than nulled, so a
     * mapping mistake stays visible in the payload instead of silently vanishing.
     *
     * @param mixed $value The raw field value.
     *
     * @return mixed `Y-m-d` string, the original value when unparseable, or null when empty.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    private function transformDateIso8601(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return $value;
        }

        return date('Y-m-d', $timestamp);

    }//end transformDateIso8601()

    /**
     * Look up the BRIN number of the Cohort a value refers to.
     *
     * @param mixed $value The cohort id.
     *
     * @return mixed The cohort's `brinNumber`, or null when the value is empty or unresolvable.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    private function transformCohortToBrin(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cohorts = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::COHORT_SCHEMA,
                'filters'  => ['id' => (string) $value],
                'limit'    => 1,
            ]
        );

        if (empty($cohorts) === true) {
            return null;
        }

        $cohort = $cohorts[0];
        if (is_array($cohorts[0]) === false) {
            $cohort = $cohorts[0]->jsonSerialize();
        }

        return $cohort['brinNumber'] ?? null;

    }//end transformCohortToBrin()
}//end class
