<?php

/**
 * Learniq RegulationExemption register tests.
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
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Lifecycle\Action\StampTransitionActorAction;
use OCA\Learniq\Lifecycle\RegulationExemptionDecisionGuard;
use OCA\Learniq\Tests\Support\OrReadVerdict;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The RegulationExemption schema: who reads and writes it, its lifecycle, its
 * notification, and the payloads the app writes.
 *
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */
class RegulationExemptionRegisterTest extends TestCase {

	use OrReadVerdict;

	/**
	 * The shipped schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$schemas  = array_column($register['components']['schemas'], null, 'slug');
		self::assertArrayHasKey('regulation-exemption', $schemas);

		return $schemas['regulation-exemption'];
	}//end schema()

	/**
	 * Grant and reject run the decision guard and stamp the decider; the
	 * guard's inputs are declared; a grant starts from a request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-an-officer-grants-it-with-a-rationale
	 */
	public function testTheDecisionRunsTheGuardAndStampsTheDecider(): void {
		$lifecycle = self::schema()['x-openregister-lifecycle'];
		self::assertSame('requested', $lifecycle['initial']);

		foreach (['grant' => 'granted', 'reject' => 'rejected'] as $action => $to) {
			$transition = $lifecycle['transitions'][$action];
			self::assertSame('requested', $transition['from']);
			self::assertSame($to, $transition['to']);
			self::assertSame(RegulationExemptionDecisionGuard::class, $transition['requires']);
			self::assertSame(RegulationExemptionDecisionGuard::TRANSITION_INPUTS, array_column($transition['inputs'], 'field'));
			self::assertSame(
				[['action' => StampTransitionActorAction::class, 'actionParameters' => ['actorField' => 'decidedBy', 'timeField' => 'decidedAt']]],
				$transition['actions']
			);
		}
	}//end testTheDecisionRunsTheGuardAndStampsTheDecider()

	/**
	 * Officers and hr read every exemption, a requester reads their own, and
	 * no other team lead or the rest of the school reads any.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-a-manager-requests-an-exemption
	 */
	public function testOnlyOfficersHrAndTheRequesterReadAnExemption(): void {
		$auth = self::shippedAuthorization(slug: 'regulation-exemption');
		$row  = ['learnerId' => 'p-jan', 'regulationSlug' => 'working-at-height', 'reasonKind' => 'medical', 'reasonText' => 'x', 'requestedBy' => 'lead-1', 'lifecycle' => 'requested'];

		self::assertTrue($this->canRead(authorization: $auth, object: $row, userId: 'lead-1', groups: ['team-leads'], owner: null), 'the requester');
		self::assertTrue($this->canRead(authorization: $auth, object: $row, userId: 'officer-2', groups: ['compliance-officers'], owner: 'lead-1'), 'an officer');
		self::assertTrue($this->canRead(authorization: $auth, object: $row, userId: 'hr-3', groups: ['hr'], owner: 'lead-1'), 'hr');
		foreach (['another team lead' => ['lead-9', ['team-leads']], 'a teacher' => ['tch-3', ['instructors']], 'the learner' => ['jan', ['learners']]] as $who => [$userId, $groups]) {
			self::assertFalse($this->canRead(authorization: $auth, object: $row, userId: $userId, groups: $groups, owner: 'lead-1'), $who . ' reads an exemption');
		}

		self::assertSame(['authenticated'], $auth['create'], 'anyone may ask; RegulationExemptionRequestStamp scopes a non-officer to their direct reports');
		self::assertSame(['compliance-officers'], $auth['update']);
		self::assertSame(['compliance-officers'], $auth['delete']);
	}//end testOnlyOfficersHrAndTheRequesterReadAnExemption()

	/**
	 * Compliance officers are told a request arrived.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-a-manager-requests-an-exemption
	 */
	public function testOfficersAreToldARequestArrived(): void {
		$notice = self::schema()['x-openregister-notifications']['requested'];
		self::assertSame(['type' => 'created'], $notice['trigger']);
		self::assertSame([['kind' => 'groups', 'groups' => ['compliance-officers']]], $notice['recipients']);
	}//end testOfficersAreToldARequestArrived()

	/**
	 * The request the page posts, the row the stamp stores and the granted row
	 * the decision leaves pass the shipped schema; a request without a reason does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-an-officer-grants-it-with-a-rationale
	 */
	public function testTheWrittenPayloadsPassTheRealSchema(): void {
		$validator = new Validator();
		$schema    = (string)json_encode(self::validatable(schema: self::schema()));

		$posted  = ['learnerId' => 'p-jan', 'regulationSlug' => 'working-at-height', 'reasonKind' => 'medical', 'reasonText' => 'A doctor\'s note is on file.'];
		$stored  = array_merge($posted, ['requestedBy' => 'lead-1', 'lifecycle' => 'requested', 'decisionRationale' => null, 'policyReference' => null, 'decidedBy' => null, 'decidedAt' => null, 'validFrom' => null, 'validUntil' => null]);
		$granted = array_merge($stored, ['lifecycle' => 'granted', 'decisionRationale' => 'Not fit for work at height.', 'policyReference' => 'Safety policy 4.2', 'validUntil' => '2027-06-30', 'decidedBy' => 'officer-2', 'decidedAt' => '2026-10-01T09:00:00+00:00']);
		foreach (['posted' => $posted, 'stored' => $stored, 'granted' => $granted] as $label => $payload) {
			$result = $validator->validate(json_decode((string)json_encode($payload)), $schema);
			self::assertTrue($result->isValid(), $label . ': ' . json_encode($result->error()?->message()));
		}

		$noReason = $posted;
		unset($noReason['reasonKind']);
		self::assertFalse($validator->validate(json_decode((string)json_encode($noReason)), $schema)->isValid(), 'control: a request needs a reason');
	}//end testTheWrittenPayloadsPassTheRealSchema()

	/**
	 * The schema as OpenRegister validates a write: without its own keys, and
	 * with every optional property that has no enum widened to accept null
	 * (ValidateObject, "allow null values for non-required fields").
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function validatable(array $schema): array {
		$clean = self::withoutOrKeys(schema: $schema);
		foreach ($clean['properties'] as $name => $property) {
			if (in_array($name, $clean['required'], true) === false && isset($property['enum']) === false && is_string($property['type'] ?? null) === true) {
				$clean['properties'][$name]['type'] = [$property['type'], 'null'];
			}
		}

		return $clean;
	}//end validatable()

	/**
	 * The schema without OpenRegister's own keys.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutOrKeys(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'authorization' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = self::withoutOrKeys(schema: $value);
			}
		}

		return $clean;
	}//end withoutOrKeys()
}//end class
