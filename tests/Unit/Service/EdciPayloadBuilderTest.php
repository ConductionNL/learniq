<?php

/**
 * Learniq EdciPayloadBuilder unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\EdciPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The builder against the pinned fixture, and the rule that nothing is
 * invented.
 */
class EdciPayloadBuilderTest extends TestCase {

	/**
	 * The fixture credential inputs.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>, 4: list<array<string, mixed>>}
	 */
	private function inputs(): array {
		return [
			['id' => '11111111-1111-4111-8111-111111111111', 'kind' => 'microcredential', 'issuedAt' => '2026-06-30T12:00:00+00:00', 'expiresAt' => null, 'issuerDid' => 'did:web:learniq:t1:abc', 'tenant_id' => 't1'],
			['id' => 'c-1', 'code' => 'MDB', 'name' => 'Minor duurzame bedrijfsvoering', 'ectsCredits' => 15, 'educationalLevels' => ['hbo']],
			['givenName' => 'Priya', 'familyName' => 'Ganpat', 'dateOfBirth' => '2004-01-01', 'bsn' => '123456782'],
			['name' => 'Hogeschool Voorbeeld', 'identifier' => '12345678', 'identifierScheme' => 'KvK'],
			[['id' => 'k-1', 'title' => 'Een duurzaamheidsplan opstellen']],
		];
	}//end inputs()

	/**
	 * The built payload equals the pinned fixture.
	 *
	 * @return void
	 */
	public function testThePayloadMatchesThePinnedFixture(): void {
		[$credential, $course, $learner, $issuer, $competences] = $this->inputs();
		$payload = (new EdciPayloadBuilder())->build(credential: $credential, course: $course, learner: $learner, issuer: $issuer, competences: $competences);

		$fixture = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/edci/microcredential.json'), true);
		self::assertSame($fixture, $payload);
		self::assertSame(EdciPayloadBuilder::ELM_CONTEXT, $payload['@context'][1]);
	}//end testThePayloadMatchesThePinnedFixture()

	/**
	 * No date of birth or national identifier ever reaches the payload.
	 *
	 * @return void
	 */
	public function testNoBirthDateOrNationalIdentifier(): void {
		[$credential, $course, $learner, $issuer] = $this->inputs();
		$json = (string)json_encode((new EdciPayloadBuilder())->build(credential: $credential, course: $course, learner: $learner, issuer: $issuer));

		self::assertStringNotContainsString('2004-01-01', $json);
		self::assertStringNotContainsString('123456782', $json);
	}//end testNoBirthDateOrNationalIdentifier()

	/**
	 * A course without ECTS, level or outcomes, and an issuer without an
	 * identifier, leave those elements out instead of inventing them.
	 *
	 * @return void
	 */
	public function testAnElementWithoutASourceIsLeftOut(): void {
		$credential = ['id' => 'x', 'kind' => 'certificate', 'issuedAt' => '2026-09-01T00:00:00+00:00', 'issuerDid' => 'did:web:x'];
		$payload = (new EdciPayloadBuilder())->build(
			credential: $credential,
			course: ['id' => 'c-2', 'name' => 'BHV basisopleiding', 'educationalLevels' => ['professional-training']],
			learner: null,
			issuer: ['name' => 'Opleider BV']
		);

		$specification = $payload['credentialSubject']['hasClaim'][0]['specifiedBy'];
		self::assertArrayNotHasKey('creditPoint', $specification);
		self::assertArrayNotHasKey('eqfLevel', $specification);
		self::assertArrayNotHasKey('learningOutcome', $specification);
		self::assertArrayNotHasKey('registration', $payload['issuer']);
		self::assertArrayNotHasKey('validUntil', $payload);
		self::assertArrayNotHasKey('givenName', $payload['credentialSubject']);
	}//end testAnElementWithoutASourceIsLeftOut()
	/**
	 * A credential without a course claims no achievement; the rest of the
	 * payload is built whole, the BRIN registration included.
	 *
	 * @return void
	 */
	public function testACredentialWithoutACourseClaimsNothing(): void {
		$payload = (new EdciPayloadBuilder())->build(
			credential: ['id' => 'y', 'kind' => 'diploma', 'issuedAt' => '2026-09-01T00:00:00+00:00', 'expiresAt' => '2031-09-01T00:00:00+00:00', 'issuerDid' => 'did:web:y'],
			course: null,
			learner: ['givenName' => ' Noor ', 'familyName' => 'Yilmaz'],
			issuer: ['name' => 'Vaartveld College', 'identifier' => ' 12AB ', 'identifierScheme' => 'BRIN'],
			competences: [['id' => 'k1', 'title' => 'Plannen']]
		);

		self::assertSame(
			[
				'@context' => ['https://www.w3.org/2018/credentials/v1', EdciPayloadBuilder::ELM_CONTEXT],
				'id' => 'urn:uuid:y',
				'type' => ['VerifiableCredential', 'EuropeanDigitalCredential'],
				'credentialProfiles' => [['id' => 'http://data.europa.eu/snb/credential/e34929035b', 'type' => 'Concept', 'prefLabel' => ['en' => 'Generic'], 'notation' => 'elm-3.2']],
				'issuer' => [
					'id' => 'did:web:y',
					'type' => 'Organisation',
					'legalName' => ['en' => 'Vaartveld College'],
					'registration' => [
						'type' => 'LegalIdentifier',
						'notation' => '12AB',
						'schemeName' => 'BRIN',
						'spatial' => ['id' => 'http://publications.europa.eu/resource/authority/country/NLD', 'type' => 'Concept'],
					],
				],
				'issuanceDate' => '2026-09-01T00:00:00+00:00',
				'validFrom' => '2026-09-01T00:00:00+00:00',
				'validUntil' => '2031-09-01T00:00:00+00:00',
				'credentialSubject' => ['id' => 'urn:uuid:y#holder', 'type' => 'Person', 'givenName' => ['en' => 'Noor'], 'familyName' => ['en' => 'Yilmaz']],
			],
			$payload
		);
	}//end testACredentialWithoutACourseClaimsNothing()
}//end class
