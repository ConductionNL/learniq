<?php

/**
 * ExcuseRequest and ConferenceSignup require the pupil's learner profile.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/absence-and-booking-fields-are-required/specs/attendance/spec.md#requirement-an-absence-report-names-its-pupils-learner-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * The real register fragments accept a write that names the pupil and refuse
 * one that does not; every portal create and every seed row names the pupil.
 */
class RequiredLearnerRefRegisterTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	private const PROFILE = 'ee010008-0000-4000-8000-000000000415';

	/**
	 * An absence report as the guardian's portal action writes it.
	 *
	 * @return array<string, mixed>
	 */
	private static function excuse(): array {
		return [
			'learnerRef' => self::PROFILE,
			'submittedByRef' => 'ee010008-0000-4000-8000-000000000009',
			'dateFrom' => '2026-10-01',
			'dateTo' => '2026-10-01',
			'reason' => 'Koorts',
			'reasonKind' => 'illness',
			'tenant_id' => self::TENANT,
		];
	}//end excuse()

	/**
	 * A booking as the guardian's direct-booking action writes it.
	 *
	 * @return array<string, mixed>
	 */
	private static function signup(): array {
		return [
			'learnerRef' => self::PROFILE,
			'slotId' => 'ee010023-0000-4000-8000-000000000001',
			'guardianRef' => 'ee010008-0000-4000-8000-000000000009',
			'tenant_id' => self::TENANT,
		];
	}//end signup()

	/**
	 * Both schemas list learnerRef as required, and an absence report keeps
	 * its four earlier required fields.
	 *
	 * @return void
	 */
	public function testBothSchemasRequireTheLearnerProfile(): void {
		self::assertSame(['dateFrom', 'dateTo', 'reason', 'reasonKind', 'learnerRef'], self::shippedSchema(slug: 'excuse-request')['required']);
		self::assertSame(['learnerRef'], self::shippedSchema(slug: 'conference-signup')['required']);
	}//end testBothSchemasRequireTheLearnerProfile()

	/**
	 * A write that names the pupil is accepted; one without the pupil, or with
	 * an empty one, is refused.
	 *
	 * @return void
	 */
	public function testTheFragmentsAcceptThePupilAndRefuseItsAbsence(): void {
		foreach (['excuse-request' => self::excuse(), 'conference-signup' => self::signup()] as $slug => $payload) {
			self::assertNull(self::schemaError(slug: $slug, payload: $payload), $slug . ' with learnerRef');

			$without = $payload;
			unset($without['learnerRef']);
			self::assertNotNull(self::schemaError(slug: $slug, payload: $without), $slug . ' without learnerRef');

			self::assertNotNull(self::schemaError(slug: $slug, payload: array_merge($payload, ['learnerRef' => null])), $slug . ' with a null learnerRef');
		}
	}//end testTheFragmentsAcceptThePupilAndRefuseItsAbsence()

	/**
	 * Every portal create on these schemas carries the pupil before
	 * OpenRegister validates: portaliq stamps the scope field, or the form
	 * lists the field and requires it.
	 *
	 * @return void
	 */
	public function testEveryPortalCreateCarriesThePupil(): void {
		$checked = 0;
		foreach (['student', 'parent'] as $audience) {
			$manifest = (new PortalContributionProvider())->getContribution(['audience' => $audience]);
			foreach ($manifest['actions'] as $action) {
				if (($action['type'] ?? '') !== 'create' || in_array(($action['schema'] ?? ''), ['excuse-request', 'conference-signup'], true) === false) {
					continue;
				}

				$checked++;
				$stamped = ($action['scopeField'] ?? '') === 'learnerRef';
				$asked = in_array('learnerRef', $action['fields'], true) && ($action['fieldConfigs']['learnerRef']['required'] ?? false) === true;
				self::assertTrue($stamped || $asked, $audience . ' ' . $action['id'] . ' does not carry learnerRef');
			}
		}

		// The pupil's absence report, the guardian's absence report, booking and request.
		self::assertSame(4, $checked);
	}//end testEveryPortalCreateCarriesThePupil()

	/**
	 * Every shipped example-set row of these schemas names the pupil, so
	 * loading a set never trips the new requirement.
	 *
	 * @return void
	 */
	public function testEverySeedRowNamesThePupil(): void {
		$rows = 0;
		foreach (glob(__DIR__ . '/../../../lib/Settings/profiles/*.json') as $file) {
			$profile = json_decode((string)file_get_contents($file), true);
			foreach (['excuse-request', 'conference-signup'] as $schema) {
				foreach (($profile['x-openregister']['seedData']['objects'][$schema] ?? []) as $object) {
					$rows++;
					self::assertNotEmpty($object['learnerRef'] ?? null, basename($file) . ' ' . ($object['slug'] ?? '?'));
				}
			}
		}

		self::assertGreaterThan(80, $rows);
	}//end testEverySeedRowNamesThePupil()
}//end class
