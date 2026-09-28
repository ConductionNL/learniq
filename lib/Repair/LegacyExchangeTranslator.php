<?php

/**
 * Learniq Legacy Exchange Translator
 *
 * Turns learniq's retired DataExchangeJob, ExchangeRejection and
 * DataMappingProfile rows into the requests integriq's exchange events take.
 * Pure: no store, no clock, no integriq.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
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
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

/**
 * The old rows in integriq's shapes, for MigrateDataExchangeToIntegriq.
 *
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
 */
class LegacyExchangeTranslator {

	/**
	 * The 23 seeded profile names and the integriq mapping slugs they became.
	 *
	 * @var array<string, string>
	 */
	public const SEEDED_PROFILES = [
		'BRON/ROD learner export' => 'learniq-bron-rod-export-learner',
		'OSO transfer dossier' => 'learniq-oso-export-dossier',
		'Leerplicht notification export' => 'learniq-leerplicht-export-melding',
		'SWV zorgvraag dossier' => 'learniq-swv-export-zorgvraag',
		'Zermelo timetable import' => 'learniq-timetable-import-zermelo',
		'Untis timetable import' => 'learniq-timetable-import-untis',
		'Xedule timetable import' => 'learniq-timetable-import-xedule',
		'TimeEdit timetable import' => 'learniq-timetable-import-timeedit',
		'LVS results import (Cito/IEP/Boom/Dia via UWLR)' => 'learniq-lvs-results-import-uwlr',
		'OSO overstapdossier import' => 'learniq-oso-import-dossier',
		'UWLR pupil export' => 'learniq-uwlr-export-pupil',
		'UWLR group export' => 'learniq-uwlr-export-group',
		'UWLR teacher export' => 'learniq-uwlr-export-teacher',
		'UWLR results import (generic data services)' => 'learniq-uwlr-import-results',
		'Edu-V Onderwijsdeelnemers data service export' => 'learniq-edu-v-export-onderwijsdeelnemers',
		'Edu-V Onderwijsgroepen data service export' => 'learniq-edu-v-export-onderwijsgroepen',
		'Edu-V Onderwijsmedewerkers data service export' => 'learniq-edu-v-export-onderwijsmedewerkers',
		'Basispoort SSO and pupil/group/staff export (PO)' => 'learniq-basispoort-sync-learner',
		'Entree content SSO hand-off (VO)' => 'learniq-entree-content-sync-learner',
		'ParnasSys migration import' => 'learniq-migration-import-parnassys',
		'ESIS migration import' => 'learniq-migration-import-esis',
		'Magister migration import' => 'learniq-migration-import-magister',
		'SOMtoday migration import' => 'learniq-migration-import-somtoday',
	];

	/**
	 * The history block integriq keeps on a migrated job.
	 *
	 * @param array<string, mixed>             $job        The old job.
	 * @param string                           $legacyId   Its id.
	 * @param array<int, array<string, mixed>> $rejections Every old rejection.
	 *
	 * @return array<string, mixed> The history.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
	 */
	public function historyOf(array $job, string $legacyId, array $rejections): array {
		$result = $job['result'] ?? null;
		$counts = null;
		if (is_array($result) === true) {
			$counts = [
				'recordsProcessed' => (int)($result['recordsProcessed'] ?? 0),
				'recordsAccepted' => (int)($result['recordsAccepted'] ?? 0),
				'recordsRejected' => (int)($result['recordsRejected'] ?? 0),
				'runId' => (string)($job['connectorRunId'] ?? ''),
				'artefactRef' => ($result['artefactRef'] ?? null),
			];
		}

		$own = [];
		foreach ($rejections as $rejection) {
			if ((string)($rejection['dataExchangeJobId'] ?? '') !== $legacyId) {
				continue;
			}

			$own[] = $this->rejectionOf(rejection: $rejection);
		}

		return [
			'legacyId' => $legacyId,
			'status' => (string)($job['lifecycle'] ?? ''),
			'requestedAt' => ($job['requestedAt'] ?? null),
			'startedAt' => ($job['startedAt'] ?? null),
			'finishedAt' => ($job['finishedAt'] ?? null),
			'result' => $counts,
			'errorMessage' => ($job['errorMessage'] ?? null),
			'rejections' => $own,
		];
	}//end historyOf()

	/**
	 * One old rejection in the shape integriq's migration reads.
	 *
	 * @param array<string, mixed> $rejection The old rejection.
	 *
	 * @return array<string, mixed> The rejection, without the target's text or the raw record.
	 */
	private function rejectionOf(array $rejection): array {
		$kind = (string)($rejection['sourceKind'] ?? '');
		$idField = [
			'learner-profile' => 'learnerProfileId',
			'enrolment' => 'enrolmentId',
			'final-grade' => 'finalGradeId',
			'attendance-flag' => 'attendanceFlagId',
			'support-request' => 'supportRequestId',
		][$kind] ?? '';

		return [
			'recordId' => (string)($rejection[$idField] ?? ''),
			'sourceKind' => $kind,
			'errorCode' => (string)($rejection['errorCode'] ?? ''),
			'offendingFields' => ($rejection['offendingFields'] ?? []),
			'status' => (string)($rejection['status'] ?? 'open'),
			'detectedAt' => ($rejection['detectedAt'] ?? null),
			'correctedBy' => ($rejection['correctedBy'] ?? null),
			'correctedAt' => ($rejection['correctedAt'] ?? null),
			'waivedBy' => ($rejection['waivedBy'] ?? null),
			'waivedAt' => ($rejection['waivedAt'] ?? null),
			'waiveReason' => ($rejection['waiveReason'] ?? null),
			'correctionDeadlineAt' => ($rejection['correctionDeadlineAt'] ?? null),
		];
	}//end rejectionOf()

	/**
	 * The scope of an old job, with the tenant and teldatum it carried.
	 *
	 * @param array<string, mixed> $job The old job.
	 *
	 * @return array<string, mixed> The scope.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
	 */
	public function scopeOf(array $job): array {
		$scope = $job['scope'] ?? [];
		if (is_array($scope) === false) {
			$scope = [];
		}

		$scope['tenantId'] = (string)($job['tenant_id'] ?? '');
		if (($job['requiresTeldatumCheck'] ?? false) === true && empty($job['teldatumCheckDate']) === false) {
			$scope['teldatumDate'] = (string)$job['teldatumCheckDate'];
		}

		return $scope;
	}//end scopeOf()

	/**
	 * The owner reference of an old job: its flag when it had one.
	 *
	 * @param array<string, mixed> $job      The old job.
	 * @param string               $legacyId Its id.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
	 */
	public function ownerRefOf(array $job, string $legacyId): string {
		$flag = (string)($job['originFlagId'] ?? '');
		if ($flag !== '') {
			return 'attendance-flag/' . $flag;
		}

		return 'data-exchange-job/' . $legacyId;
	}//end ownerRefOf()

	/**
	 * The integriq slug of a profile: the seed's, or a custom one from its name.
	 *
	 * @param array<string, mixed> $profile The profile.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
	 */
	public function slugOf(array $profile): string {
		$name = (string)($profile['name'] ?? '');
		if (isset(self::SEEDED_PROFILES[$name]) === true) {
			return self::SEEDED_PROFILES[$name];
		}

		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
		return 'learniq-custom-' . substr($slug, 0, 40);
	}//end slugOf()

	/**
	 * A profile's field mappings as integriq mapping rules.
	 *
	 * @param array<string, mixed> $profile The profile.
	 *
	 * @return array<string, string> Output key to source path.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
	 */
	public function rulesOf(array $profile): array {
		$import = (($profile['direction'] ?? 'export') === 'import');
		$rules = [];
		foreach (($profile['fieldMappings'] ?? []) as $row) {
			$learniqField = (string)($row['scholiqField'] ?? '');
			$targetField = (string)($row['targetField'] ?? '');
			if ($learniqField === '' || $targetField === '' || $learniqField === 'bsnEncrypted') {
				continue;
			}

			if ($import === true) {
				$rules[$learniqField] = $targetField;
				continue;
			}

			$rules[$targetField] = $learniqField;
		}

		return $rules;
	}//end rulesOf()
}//end class
