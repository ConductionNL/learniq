<?php

/**
 * The DataCorrectionRequest schema, the GradeEntry link to it, the guards
 * the transitions name, the listeners the app registers, and the payloads
 * the app writes, checked against the shipped register.
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Lifecycle\Action\StampTransitionActorAction;
use OCA\Learniq\Lifecycle\DataCorrectionDecisionGuard;
use OCA\Learniq\Lifecycle\ReportPeriodLockGuard;
use OCA\Learniq\Listener\CorrectionAppliedHandler;
use OCA\Learniq\Listener\DataCorrectionRequestStamp;
use OCA\Learniq\Tests\Support\OrReadVerdict;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * DataCorrectionRequest in the shipped register.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */
class DataCorrectionRequestRegisterTest extends TestCase {

	use OrReadVerdict;

	/**
	 * The groups that decide on a correction: the old lone override groups.
	 */
	private const APPROVERS = ['admin', 'team-leads', 'administration-managers'];

	/**
	 * A shipped schema by slug.
	 *
	 * @param string $slug The schema slug.
	 * @param string $file The register file.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(string $slug, string $file = 'learniq_register.json'): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $file), true);
		$schemas  = array_column($register['components']['schemas'], null, 'slug');
		self::assertArrayHasKey($slug, $schemas);

		return $schemas[$slug];
	}//end schema()

	/**
	 * Approve and reject are for the approver groups, run the decision guard
	 * and stamp the decider; apply runs the guard and names no group, because
	 * the app applies a request as the system after the republish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-the-requester-cannot-approve
	 */
	public function testTheDecisionIsForASecondPersonInTheApproverGroups(): void {
		$lifecycle = self::schema(slug: 'data-correction-request')['x-openregister-lifecycle'];
		self::assertSame('requested', $lifecycle['initial']);

		foreach (['approve' => 'approved', 'reject' => 'rejected'] as $action => $to) {
			$transition = $lifecycle['transitions'][$action];
			self::assertSame(['requested', $to], [$transition['from'], $transition['to']], $action);
			self::assertSame(DataCorrectionDecisionGuard::class, $transition['requires'], $action);
			self::assertSame(self::APPROVERS, $transition['authorization'], $action);
			self::assertSame(
				[['action' => StampTransitionActorAction::class, 'actionParameters' => ['actorField' => 'decidedBy', 'timeField' => 'decidedAt']]],
				$transition['actions'],
				$action
			);
		}

		$apply = $lifecycle['transitions']['apply'];
		self::assertSame(['approved', 'applied'], [$apply['from'], $apply['to']]);
		self::assertSame(DataCorrectionDecisionGuard::class, $apply['requires']);
		self::assertArrayNotHasKey('authorization', $apply);
	}//end testTheDecisionIsForASecondPersonInTheApproverGroups()

	/**
	 * Approvers read every request, a requester reads their own, a teacher
	 * reads no one else's; staff who grade may ask, only approvers update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-an-auditor-reads-the-trail
	 */
	public function testWhoReadsAndWritesARequest(): void {
		$auth = self::shippedAuthorization(slug: 'data-correction-request');
		$row  = ['gradeEntryId' => 'entry-1', 'proposedValue' => 6.5, 'reason' => 'x', 'requestedBy' => 'teacher-a', 'lifecycle' => 'requested'];

		self::assertTrue($this->canRead(authorization: $auth, object: $row, userId: 'teacher-a', groups: ['instructors'], owner: null), 'the requester');
		self::assertTrue($this->canRead(authorization: $auth, object: $row, userId: 'principal-b', groups: ['team-leads'], owner: 'teacher-a'), 'a team lead');
		self::assertTrue($this->canRead(authorization: $auth, object: $row, userId: 'office-c', groups: ['administration-managers'], owner: 'teacher-a'), 'an administration manager');
		self::assertFalse($this->canRead(authorization: $auth, object: $row, userId: 'teacher-d', groups: ['instructors'], owner: 'teacher-a'), 'another teacher');
		self::assertFalse($this->canRead(authorization: $auth, object: $row, userId: 'jan', groups: ['learners'], owner: 'teacher-a'), 'a learner');

		self::assertSame(['instructors', 'team-leads', 'compliance-officers', 'hr'], $auth['create']);
		self::assertSame(['team-leads', 'administration-managers'], $auth['update']);
	}//end testWhoReadsAndWritesARequest()

	/**
	 * Approvers are told a request arrived.
	 *
	 * @return void
	 */
	public function testApproversAreToldARequestArrived(): void {
		$notice = self::schema(slug: 'data-correction-request')['x-openregister-notifications']['requested'];
		self::assertSame(['type' => 'created'], $notice['trigger']);
		self::assertSame([['kind' => 'groups', 'groups' => ['team-leads', 'administration-managers']]], $notice['recipients']);
	}//end testApproversAreToldARequestArrived()

	/**
	 * GradeEntry publish and republish still name ReportPeriodLockGuard, and
	 * a grade entry can name the correction that changed it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-principal-cannot-override-alone
	 */
	public function testGradeEntryPublishRunsTheLockGuardAndLinksTheCorrection(): void {
		$gradeEntry = self::schema(slug: 'grade-entry');
		foreach (['publish', 'republish'] as $action) {
			self::assertSame(ReportPeriodLockGuard::class, $gradeEntry['x-openregister-lifecycle']['transitions'][$action]['requires']);
		}

		self::assertSame('data-correction-request', strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', (string)$gradeEntry['properties']['correctionRequestId']['$ref'])));
		self::assertTrue($gradeEntry['properties']['correctionRequestId']['readOnly']);
	}//end testGradeEntryPublishRunsTheLockGuardAndLinksTheCorrection()

	/**
	 * The request the form posts, the row the stamp stores, the approved row
	 * and the applied row pass the shipped schema, and so does the grade entry
	 * with its link; a request without a reason does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-an-auditor-reads-the-trail
	 */
	public function testTheWrittenPayloadsPassTheRealSchema(): void {
		$validator = new Validator();
		$schema    = (string)json_encode(self::validatable(schema: self::schema(slug: 'data-correction-request')));

		$posted   = ['gradeEntryId' => '0b7c5a3e-0000-4000-8000-000000000001', 'proposedValue' => 6.5, 'reason' => 'A page of the exam was not counted.'];
		$stored   = array_merge($posted, ['requestedBy' => 'teacher-a', 'currentValue' => 5.5, 'lifecycle' => 'requested', 'decisionNote' => null, 'decidedBy' => null, 'decidedAt' => null, 'appliedBy' => null, 'appliedAt' => null]);
		$approved = array_merge($stored, ['lifecycle' => 'approved', 'decisionNote' => 'Checked the paper.', 'decidedBy' => 'principal-b', 'decidedAt' => '2026-10-01T09:00:00+00:00']);
		$applied  = array_merge($approved, ['lifecycle' => 'applied', 'appliedBy' => 'teacher-a', 'appliedAt' => '2026-10-01T10:00:00+00:00']);
		foreach (['posted' => $posted, 'stored' => $stored, 'approved' => $approved, 'applied' => $applied] as $label => $payload) {
			$result = $validator->validate(json_decode((string)json_encode($payload)), $schema);
			self::assertTrue($result->isValid(), $label . ': ' . json_encode($result->error()?->message()));
		}

		$noReason = $posted;
		unset($noReason['reason']);
		self::assertFalse($validator->validate(json_decode((string)json_encode($noReason)), $schema)->isValid(), 'control: a request needs a reason');

		$entrySchema = self::validatable(schema: self::schema(slug: 'grade-entry'));
		$entrySchema['properties'] = array_intersect_key($entrySchema['properties'], ['correctionRequestId' => true]);
		unset($entrySchema['required'], $entrySchema['allOf']);
		$linked = $validator->validate((object)['correctionRequestId' => '0b7c5a3e-0000-4000-8000-000000000002'], (string)json_encode($entrySchema));
		self::assertTrue($linked->isValid(), 'grade entry link: ' . json_encode($linked->error()?->message()));
	}//end testTheWrittenPayloadsPassTheRealSchema()

	/**
	 * The demo register ships a request that passes the same schema.
	 *
	 * @return void
	 */
	public function testTheDemoRegisterShipsAValidRequest(): void {
		$validator = new Validator();
		$schema    = (string)json_encode(self::validatable(schema: self::schema(slug: 'data-correction-request')));
		$mock      = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_mock_register.json'), true);
		$rows      = [];
		foreach (($mock['components']['objects'] ?? []) as $row) {
			if (($row['@self']['schema'] ?? null) === 'data-correction-request') {
				$rows[] = $row;
			}
		}

		self::assertNotSame([], $rows, 'the demo register has a correction request');
		foreach ($rows as $row) {
			unset($row['@self']);
			$result = $validator->validate(json_decode((string)json_encode($row)), $schema);
			self::assertTrue($result->isValid(), json_encode($result->error()?->message()));
		}
	}//end testTheDemoRegisterShipsAValidRequest()

	/**
	 * The live wiring registers the stamp for create and update and the
	 * applied handler for transitions.
	 *
	 * @return void
	 */
	public function testTheListenersAreRegistered(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . DataCorrectionRequestStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . DataCorrectionRequestStamp::class, $registered);
		self::assertContains(ObjectTransitionedEvent::class . ' => ' . CorrectionAppliedHandler::class, $registered);
	}//end testTheListenersAreRegistered()

	/**
	 * The schema as OpenRegister validates a write: without its own keys, and
	 * with every optional property that has no enum widened to accept null.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function validatable(array $schema): array {
		$clean = self::withoutOrKeys(schema: $schema);
		foreach ($clean['properties'] as $name => $property) {
			if (in_array($name, ($clean['required'] ?? []), true) === false && isset($property['enum']) === false && is_string($property['type'] ?? null) === true) {
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
