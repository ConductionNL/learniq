<?php

/**
 * Scholiq Exchange Rejection Contract
 *
 * The shared, schema-level contract for `ExchangeRejection` rows, held in one
 * place so the first-pass mapper (`RejectionMappingHandler`) and the
 * resubmission-outcome resolver (`RejectionResubmissionResolver`) cannot drift
 * apart: a rejection written by one and re-read by the other must agree on
 * which typed `$ref` field carries the rejected record's id.
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
 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#requirement-resolve-a-jobs-rejected-records-to-their-scholiq-source-object
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Shared ExchangeRejection constants: sourceKind field map and the per-job row cap.
 */
final class ExchangeRejectionContract {

	/**
	 * Whitelisted scope.schema slugs supported as an ExchangeRejection
	 * sourceKind, mapped to the typed $ref id field that carries the resolved
	 * source object's id. The schema slug IS the sourceKind enum value for every
	 * entry — mirrors GradeEntry.sourceKind's "one nullable typed field per enum
	 * value" shape (design.md "sourceKind enum + per-kind typed $ref").
	 *
	 * @var array<string,string>
	 */
	public const SOURCE_KIND_FIELD_MAP = [
		'learner-profile' => 'learnerProfileId',
		'enrolment' => 'enrolmentId',
		'final-grade' => 'finalGradeId',
		'attendance-flag' => 'attendanceFlagId',
		'support-request' => 'supportRequestId',
	];

	/**
	 * Upper bound on existing ExchangeRejection rows queried per job for the
	 * idempotency check. A single job rejecting more than this many distinct
	 * records in one run is not expected; raise if ever hit in practice.
	 */
	public const MAX_REJECTIONS_PER_JOB = 5000;
}//end class
