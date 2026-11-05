<?php

/**
 * The register keeps what the board dialogs send, and names the rows the
 * board lists show.
 *
 * The revoke dialog sends a revocation reason, the withdraw dialog a reason
 * for withdrawing and the availability dialog draws one row per evening of
 * the round. A transition stores only the inputs it declares, so each value
 * the dialog sends is a declared input. A list row menu reads "Acties voor
 * <name>", so the rows have a name built from their own fields.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-dialog-inputs-and-row-names/specs/board-dialogs/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Pins the transition inputs, the evenings shape and the row names.
 */
class BoardDialogInputsTest extends TestCase {

	/**
	 * The schemas of the shipped register.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$register = json_decode(
			json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/learniq_register.json'),
			associative: true
		);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * The inputs a transition declares.
	 *
	 * @param string $schema     The schema name.
	 * @param string $transition The transition.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function inputs(string $schema, string $transition): array {
		return (self::schemas()[$schema]['x-openregister-lifecycle']['transitions'][$transition]['inputs'] ?? []);
	}//end inputs()

	/**
	 * Revoking a certificate stores its reason, and needs one.
	 *
	 * @return void
	 */
	public function testRevokingACertificateStoresAReason(): void {
		$this->assertSame(
			expected: [['field' => 'revocationReason', 'required' => true]],
			actual: self::inputs(schema: 'Credential', transition: 'revoke')
		);
	}//end testRevokingACertificateStoresAReason()

	/**
	 * Withdrawing an enrolment stores the reason when one is given; a learner's
	 * own sign-off runs the same transition without one.
	 *
	 * @return void
	 */
	public function testWithdrawingAnEnrolmentStoresAnOptionalReason(): void {
		$enrolment = self::schemas()['Enrolment'];

		$this->assertSame(expected: 'string', actual: ($enrolment['properties']['withdrawReason']['type'] ?? null));
		$this->assertNotContains(needle: 'withdrawReason', haystack: ($enrolment['required'] ?? []));
		$this->assertSame(
			expected: [['field' => 'withdrawReason', 'required' => false]],
			actual: self::inputs(schema: 'Enrolment', transition: 'withdraw')
		);
	}//end testWithdrawingAnEnrolmentStoresAnOptionalReason()

	/**
	 * A conference round lists its evenings as a date with a start and end time.
	 *
	 * @return void
	 */
	public function testAConferenceRoundListsItsEvenings(): void {
		$evenings = (self::schemas()['ConferenceRound']['properties']['evenings'] ?? []);

		$this->assertSame(expected: 'array', actual: ($evenings['type'] ?? null));
		$this->assertSame(expected: ['date', 'from', 'until'], actual: ($evenings['items']['required'] ?? null));
		$this->assertSame(expected: 'date', actual: ($evenings['items']['properties']['date']['format'] ?? null));
		foreach (['from', 'until'] as $time) {
			$pattern = '/' . $evenings['items']['properties'][$time]['pattern'] . '/';
			$this->assertSame(expected: 1, actual: preg_match(pattern: $pattern, subject: '18:30'));
			$this->assertSame(expected: 0, actual: preg_match(pattern: $pattern, subject: '18.30'));
		}
	}//end testAConferenceRoundListsItsEvenings()

	/**
	 * The vo mentor round's evenings are the blocks its mentor is available in.
	 *
	 * @return void
	 */
	public function testTheVoMentorRoundCarriesItsEvenings(): void {
		$set = json_decode(
			json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/profiles/vo.json'),
			associative: true
		);
		$objects = $set['components']['objects'];
		$round = $objects['conference-round'][0];
		$availability = $objects['teacher-availability'][0];

		$this->assertSame(expected: $round['uuid'], actual: $availability['conferenceRoundId']);
		$blocks = array_map(
			callback: static fn (array $block): array => [
				'date' => substr(string: $block['startsAt'], offset: 0, length: 10),
				'from' => substr(string: $block['startsAt'], offset: 11, length: 5),
				'until' => substr(string: $block['endsAt'], offset: 11, length: 5),
			],
			array: $availability['blocks']
		);
		$this->assertSame(expected: $blocks, actual: ($round['evenings'] ?? null));
	}//end testTheVoMentorRoundCarriesItsEvenings()

	/**
	 * Rows that hold their own name fields carry an object name.
	 *
	 * @return void
	 */
	public function testListRowsCarryAName(): void {
		$schemas = self::schemas();

		$this->assertSame(
			expected: '{{ applicantGivenName }} {{ applicantFamilyName }}',
			actual: ($schemas['Application']['configuration']['objectNameField'] ?? null)
		);
		$this->assertSame(
			expected: '{{ partnerName }}',
			actual: ($schemas['ExchangePartnerApproval']['configuration']['objectNameField'] ?? null)
		);
		foreach (['Application' => ['applicantGivenName', 'applicantFamilyName'], 'ExchangePartnerApproval' => ['partnerName']] as $name => $fields) {
			foreach ($fields as $field) {
				$this->assertArrayHasKey(key: $field, array: $schemas[$name]['properties']);
			}
		}
	}//end testListRowsCarryAName()
}//end class
