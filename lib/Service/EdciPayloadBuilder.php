<?php

/**
 * Learniq EDCI Payload Builder
 *
 * Maps a learniq Credential, its course, the learner and the issuing
 * organisation onto a European Digital Credential in the European Learning
 * Model (credentials-europass-edci-export), the form a learner keeps in their
 * Europass profile.
 *
 * Pinned version: the EDC application profile of ELM 3.2, JSON-LD context
 * `http://data.europa.eu/snb/model/context/edc-ap`, on the W3C verifiable
 * credentials data model v1. The fixture `tests/fixtures/edci/certificate.json`
 * pins the shape the unit test holds this builder to. When ELM moves, the
 * version, the context and the fixture move together.
 *
 * The builder never invents a value: an element without a source is left
 * out. Unlike the Open Badges payload (public verification URL, hashed
 * subject) this form names the holder, because Europass shows whose
 * credential it is; it is therefore served only to the learner and staff, and
 * it never carries a date of birth or a national identifier.
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
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Builds the ELM European Digital Credential for one learniq credential.
 *
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */
class EdciPayloadBuilder {

	/**
	 * The pinned ELM application profile context.
	 */
	public const ELM_CONTEXT = 'http://data.europa.eu/snb/model/context/edc-ap';

	/**
	 * The pinned ELM version, recorded in the payload's profile.
	 */
	public const ELM_VERSION = '3.2';

	/**
	 * Credential kinds that get a Europass form.
	 */
	public const KINDS = ['certificate', 'diploma', 'microcredential'];

	/**
	 * The ELM generic credential profile concept (EU vocabulary `credential`).
	 * Every kind uses it: the diploma supplement profile is out of scope.
	 */
	private const GENERIC_PROFILE = 'http://data.europa.eu/snb/credential/e34929035b';

	/**
	 * NL-LOM education levels that map onto an EQF level; others are left out.
	 */
	private const EQF = [
		'mbo-1' => 1,
		'mbo-2' => 2,
		'mbo-3' => 3,
		'mbo-4' => 4,
		'havo' => 4,
		'vwo' => 4,
		'hbo' => 6,
		'wo' => 7,
	];

	/**
	 * Build the unsigned payload.
	 *
	 * @param array<string, mixed>       $credential  The Credential (id, kind, issuedAt, expiresAt, issuerDid, tenant_id).
	 * @param array<string, mixed>|null  $course      The Course, or null for a credential without one.
	 * @param array<string, mixed>|null  $learner     The LearnerProfile (givenName, familyName), or null.
	 * @param array<string, mixed>       $issuer      `name`, optional `identifier` (KvK or BRIN) and `identifierScheme`.
	 * @param list<array<string, mixed>> $competences Competencies of the course (id, title), for learning outcomes.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#scenario-a-training-certificate-gets-its-europass-form
	 */
	public function build(array $credential, ?array $course, ?array $learner, array $issuer, array $competences = []): array {
		$credentialId = 'urn:uuid:' . (string)($credential['id'] ?? '');
		$issuerId = (string)($credential['issuerDid'] ?? '');

		$payload = [
			'@context' => ['https://www.w3.org/2018/credentials/v1', self::ELM_CONTEXT],
			'id' => $credentialId,
			'type' => ['VerifiableCredential', 'EuropeanDigitalCredential'],
			'credentialProfiles' => [$this->profile()],
			'issuer' => $this->organisation(issuerId: $issuerId, issuer: $issuer),
			'issuanceDate' => $credential['issuedAt'] ?? null,
			'validFrom' => $credential['issuedAt'] ?? null,
			'validUntil' => $credential['expiresAt'] ?? null,
			'credentialSubject' => $this->person(credentialId: $credentialId, learner: $learner),
		];

		$achievement = $this->achievement(
			credentialId: $credentialId,
			credential: $credential,
			course: $course,
			issuerId: $issuerId,
			competences: $competences
		);
		if ($achievement !== null) {
			$payload['credentialSubject']['hasClaim'] = [$achievement];
		}

		return $this->prune(value: $payload);
	}//end build()

	/**
	 * The credential profile.
	 *
	 * @return array<string, mixed>
	 */
	private function profile(): array {
		return ['id' => self::GENERIC_PROFILE, 'type' => 'Concept', 'prefLabel' => ['en' => 'Generic'], 'notation' => 'elm-' . self::ELM_VERSION];
	}//end profile()

	/**
	 * The issuing organisation.
	 *
	 * @param string               $issuerId The issuer DID.
	 * @param array<string, mixed> $issuer   Name and identifier.
	 *
	 * @return array<string, mixed>
	 */
	private function organisation(string $issuerId, array $issuer): array {
		$organisation = [
			'id' => $issuerId,
			'type' => 'Organisation',
			'legalName' => $this->text(value: $issuer['name'] ?? null),
		];

		$identifier = trim((string)($issuer['identifier'] ?? ''));
		if ($identifier !== '') {
			$organisation['registration'] = [
				'type' => 'LegalIdentifier',
				'notation' => $identifier,
				'schemeName' => (string)($issuer['identifierScheme'] ?? 'KvK'),
				'spatial' => ['id' => 'http://publications.europa.eu/resource/authority/country/NLD', 'type' => 'Concept'],
			];
		}

		return $organisation;
	}//end organisation()

	/**
	 * The holder: name only, no date of birth, no national identifier.
	 *
	 * @param string                    $credentialId The credential urn.
	 * @param array<string, mixed>|null $learner      The learner profile.
	 *
	 * @return array<string, mixed>
	 */
	private function person(string $credentialId, ?array $learner): array {
		return [
			'id' => $credentialId . '#holder',
			'type' => 'Person',
			'givenName' => $this->text(value: $learner['givenName'] ?? null),
			'familyName' => $this->text(value: $learner['familyName'] ?? null),
		];
	}//end person()

	/**
	 * The learning achievement the credential claims, or null without a course.
	 *
	 * @param string                     $credentialId The credential urn.
	 * @param array<string, mixed>       $credential   The credential.
	 * @param array<string, mixed>|null  $course       The course.
	 * @param string                     $issuerId     The issuer DID.
	 * @param list<array<string, mixed>> $competences  The course's competencies.
	 *
	 * @return array<string, mixed>|null
	 */
	private function achievement(string $credentialId, array $credential, ?array $course, string $issuerId, array $competences): ?array {
		if ($course === null) {
			return null;
		}

		$title = array_filter(
			['en' => $course['name'] ?? null, 'nl' => $course['name_nl'] ?? null],
			static fn (mixed $v): bool => is_string($v) && $v !== ''
		);

		$specification = [
			'id' => 'urn:learniq:course:' . (string)($course['id'] ?? ''),
			'type' => 'LearningAchievementSpecification',
			'title' => $title,
			'eqfLevel' => $this->eqf(levels: (array)($course['educationalLevels'] ?? [])),
			'creditPoint' => $this->credits(ects: $course['ectsCredits'] ?? null),
			'learningOutcome' => $this->outcomes(competences: $competences),
		];

		return [
			'id' => $credentialId . '#achievement',
			'type' => 'LearningAchievement',
			'title' => $title,
			'awardedBy' => [
				'id' => $credentialId . '#awarding',
				'type' => 'AwardingProcess',
				'awardingBody' => [$issuerId],
				'awardingDate' => $credential['issuedAt'] ?? null,
			],
			'specifiedBy' => $specification,
		];
	}//end achievement()

	/**
	 * The highest EQF level a course's education levels map onto, or null.
	 *
	 * @param array<int, mixed> $levels NL-LOM education levels.
	 *
	 * @return array<string, mixed>|null
	 */
	private function eqf(array $levels): ?array {
		$eqf = null;
		foreach ($levels as $level) {
			$mapped = self::EQF[(string)$level] ?? null;
			if ($mapped !== null && ($eqf === null || $mapped > $eqf)) {
				$eqf = $mapped;
			}
		}

		if ($eqf === null) {
			return null;
		}

		return ['id' => 'http://data.europa.eu/snb/eqf/' . $eqf, 'type' => 'Concept', 'notation' => (string)$eqf];
	}//end eqf()

	/**
	 * ECTS credit points, or null when the course has none.
	 *
	 * @param mixed $ects Course.ectsCredits.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function credits(mixed $ects): ?array {
		if (is_numeric($ects) === false || (float)$ects <= 0.0) {
			return null;
		}

		$framework = ['type' => 'Concept', 'notation' => 'ECTS', 'prefLabel' => ['en' => 'European Credit Transfer and Accumulation System']];

		return [['type' => 'CreditPoint', 'framework' => $framework, 'point' => (string)(0 + $ects)]];
	}//end credits()

	/**
	 * Learning outcomes from the course's competencies, or null.
	 *
	 * @param list<array<string, mixed>> $competences Competencies with id and title.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function outcomes(array $competences): ?array {
		$outcomes = [];
		foreach ($competences as $competence) {
			$title = trim((string)($competence['title'] ?? ($competence['name'] ?? '')));
			if ($title !== '') {
				$outcomes[] = ['id' => 'urn:learniq:competency:' . (string)($competence['id'] ?? ''), 'type' => 'LearningOutcome', 'title' => ['en' => $title]];
			}
		}

		if ($outcomes === []) {
			return null;
		}

		return $outcomes;
	}//end outcomes()

	/**
	 * A language map for a text, or null when empty.
	 *
	 * @param mixed $value The text.
	 *
	 * @return array<string, string>|null
	 */
	private function text(mixed $value): ?array {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return ['en' => trim($value)];
	}//end text()

	/**
	 * Remove null and empty values at every depth, so nothing is invented.
	 *
	 * @param array<mixed> $value The payload.
	 *
	 * @return array<mixed>
	 */
	private function prune(array $value): array {
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$item = $this->prune(value: $item);
				$value[$key] = $item;
			}

			if ($item === null || $item === [] || $item === '') {
				unset($value[$key]);
			}
		}

		return $value;
	}//end prune()
}//end class
