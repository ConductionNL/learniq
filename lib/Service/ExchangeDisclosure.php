<?php

/**
 * Learniq Exchange Disclosure
 *
 * What may leave learniq for each integriq mapping: the learniq fields that
 * mapping reads, and the fields a statutory target cannot do without.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Per-mapping field lists (design D2).
 *
 * Taken from the 23 former DataMappingProfile seeds, now integriq mapping rows
 * under the same slugs: a `bsn-to-pseudonym` field became `eckId` and a
 * `cohort-to-brin` field became `schoolBrin`, which learniq resolves itself.
 * Import mappings are absent: nothing leaves on an import.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
 */
class ExchangeDisclosure {

	public const ROD_LEARNER_MAPPING = 'learniq-bron-rod-export-learner';
	public const ROD_SCHOOL_ADVICE_MAPPING = 'learniq-bron-rod-export-schooladvies';

	/**
	 * DUO's AanleverenAdviesVO field set (PvE ROD-PO 1.14.2, 7.9.1), and nothing else.
	 *
	 * @var array<int, string>
	 */
	public const ROD_SCHOOL_ADVICE_FIELDS = [
		'persoonsgebondenNummer',
		'persoonsgebondenNummerType',
		'adviesvolgnummer',
		'onderwijsaanbieder',
		'onderwijslocatie',
		'vestigingscode',
		'adviesjaar',
		'advies1',
		'advies1Datum',
		'advies2',
		'advies2Datum',
	];

	/**
	 * Mapping slug to the learniq fields it may read.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FIELDS = [
		self::ROD_LEARNER_MAPPING => [
			'eckId',
			'givenName',
			'familyName',
			'birthDate',
			'schoolId',
			'persoonsgebondenNummer',
			'persoonsgebondenNummerType',
		],
		self::ROD_SCHOOL_ADVICE_MAPPING => self::ROD_SCHOOL_ADVICE_FIELDS,
		'learniq-oso-export-dossier' => ['eckId', 'givenName', 'familyName', 'birthDate', 'schoolBrin'],
		'learniq-leerplicht-export-melding' => ['learnerId', 'windowStart', 'windowEnd', 'metricValue'],
		'learniq-swv-export-zorgvraag' => ['supportDomain', 'description', 'urgency'],
		'learniq-uwlr-export-pupil' => ['eckId', 'givenName', 'familyName', 'birthDate', 'schoolId'],
		'learniq-uwlr-export-group' => ['name', 'academicYear', 'period'],
		'learniq-uwlr-export-teacher' => ['eckId', 'givenName', 'familyName', 'department'],
		'learniq-edu-v-export-onderwijsdeelnemers' => ['eckId', 'givenName', 'familyName', 'birthDate'],
		'learniq-edu-v-export-onderwijsgroepen' => ['name', 'academicYear'],
		'learniq-edu-v-export-onderwijsmedewerkers' => ['eckId', 'givenName', 'familyName'],
		'learniq-basispoort-sync-learner' => ['eckId', 'givenName', 'familyName', 'schoolId'],
		'learniq-entree-content-sync-learner' => ['eckId', 'roles'],
	];

	/**
	 * Targets that never leave without a known field list (the builder's former
	 * MANDATORY_PROFILE_TARGETS): an unset list yields no export, never a wider one.
	 *
	 * @var array<int, string>
	 */
	private const STATUTORY_TARGETS = ['bron-rod', 'oso', 'swv'];

	/**
	 * Fields every record of a statutory target must carry.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const REQUIRED = [
		'bron-rod' => ['eckId', 'birthDate', 'schoolId'],
		'leerplicht' => ['learnerId', 'windowStart', 'windowEnd'],
		'oso' => ['eckId'],
	];

	/**
	 * Fields a mapping's every record must carry; wins over the target's list (design D3).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const REQUIRED_BY_MAPPING = [
		self::ROD_LEARNER_MAPPING => ['eckId', 'birthDate', 'schoolId', 'persoonsgebondenNummer', 'persoonsgebondenNummerType'],
		self::ROD_SCHOOL_ADVICE_MAPPING => [
			'persoonsgebondenNummer',
			'persoonsgebondenNummerType',
			'adviesvolgnummer',
			'onderwijsaanbieder',
			'onderwijslocatie',
			'vestigingscode',
			'adviesjaar',
			'advies1',
			'advies1Datum',
		],
	];

	/**
	 * Fields that never leave, whatever a list says. The personal number leaves
	 * only as `persoonsgebondenNummer`, composed for the two ROD mappings.
	 *
	 * @var array<int, string>
	 */
	public const NEVER = ['bsnEncrypted', 'bsnHash', 'email', 'personalNumber', 'personalNumberType'];

	/**
	 * The fields a mapping may read, or null when learniq has no list for it.
	 *
	 * @param string|null $mappingSlug The integriq mapping slug.
	 *
	 * @return array<int, string>|null The field names.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
	 */
	public function fieldsFor(?string $mappingSlug): ?array {
		if ($mappingSlug === null || isset(self::FIELDS[$mappingSlug]) === false) {
			return null;
		}

		return self::FIELDS[$mappingSlug];
	}//end fieldsFor()

	/**
	 * Whether a target may only leave with a known field list.
	 *
	 * @param string $target The exchange target.
	 *
	 * @return bool True for a statutory target.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
	 */
	public function isStatutory(string $target): bool {
		return in_array($target, self::STATUTORY_TARGETS, true);
	}//end isStatutory()

	/**
	 * The fields a job's every record must carry: the mapping's list, else the target's.
	 *
	 * @param string      $target      The exchange target.
	 * @param string|null $mappingSlug The job's integriq mapping.
	 *
	 * @return array<int, string> The field names, empty when none are required.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
	 * @spec openspec/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
	 */
	public function requiredFor(string $target, ?string $mappingSlug=null): array {
		if ($mappingSlug !== null && isset(self::REQUIRED_BY_MAPPING[$mappingSlug]) === true) {
			return self::REQUIRED_BY_MAPPING[$mappingSlug];
		}

		return (self::REQUIRED[$target] ?? []);
	}//end requiredFor()

	/**
	 * Whether a job may carry the persoonsgebonden nummer: a ROD target with one of the two ROD mappings.
	 *
	 * @param string      $target      The exchange target.
	 * @param string|null $mappingSlug The job's integriq mapping.
	 *
	 * @return bool True only for those two.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-personal-number-leaves-learniq-only-in-a-rod-message-and-is-never-logged
	 */
	public function carriesPersonalNumber(string $target, ?string $mappingSlug): bool {
		return $target === 'bron-rod'
			&& in_array($mappingSlug, [self::ROD_LEARNER_MAPPING, self::ROD_SCHOOL_ADVICE_MAPPING], true);
	}//end carriesPersonalNumber()
}//end class
