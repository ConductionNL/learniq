<?php

/**
 * The shipped LeaveRequest schema: what a request carries, the guarded
 * decisions that stamp who decided and when, and who may read and decide.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/attendance/spec.md#requirement-a-leave-request-is-asked-in-advance-and-decided-by-the-head-of-the-school
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class LeaveRequestRegisterTest extends TestCase {

	/**
	 * The register as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
	}//end register()

	/**
	 * The LeaveRequest schema.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		return $this->register()['components']['schemas']['LeaveRequest'];
	}//end schema()

	/**
	 * The schema is in the register and carries every field the change names.
	 *
	 * @return void
	 */
	public function testARequestCarriesEveryFieldTheChangeNames(): void {
		$this->assertContains('leave-request', $this->register()['components']['registers']['learniq']['schemas']);
		$this->assertSame('leave-request', $this->schema()['slug']);
		$this->assertSame('0.1.1', $this->schema()['version']);
		$this->assertTrue(version_compare((string)$this->register()['info']['version'], '0.51.0', '>='));
		$this->assertSame(['learnerRef', 'dateFrom', 'dateTo', 'kind', 'reason'], $this->schema()['required']);

		$fields = ['learnerRef', 'requestedByRef', 'dateFrom', 'dateTo', 'kind', 'reason', 'attachmentRef', 'schoolDays', 'decideBy', 'decidedBy', 'decidedAt', 'decisionNote', 'lifecycle', 'teacherIds', 'tenant_id'];
		$this->assertSame($fields, array_keys($this->schema()['properties']));
		$this->assertSame(
			['wedding', 'funeral', 'religious-observance', 'family-circumstance', 'holiday-outside-school-holidays', 'other'],
			$this->schema()['properties']['kind']['enum']
		);
		$this->assertSame(['submitted', 'allowed', 'refused', 'forwarded', 'withdrawn'], $this->schema()['properties']['lifecycle']['enum']);
	}//end testARequestCarriesEveryFieldTheChangeNames()

	/**
	 * Allow, refuse and forward pass the decision guard and stamp who decided and when; withdraw does not.
	 *
	 * @return void
	 */
	public function testEveryDecisionIsGuardedAndStamped(): void {
		$lifecycle = $this->schema()['x-openregister-lifecycle'];
		$this->assertSame('submitted', $lifecycle['initial']);

		$stamp = [
			'action' => 'OCA\\Learniq\\Lifecycle\\Action\\StampTransitionActorAction',
			'actionParameters' => ['actorField' => 'decidedBy', 'timeField' => 'decidedAt'],
		];
		foreach (['allow' => 'allowed', 'refuse' => 'refused', 'forward' => 'forwarded'] as $name => $to) {
			$transition = $lifecycle['transitions'][$name];
			$this->assertSame(['submitted', $to], [$transition['from'], $transition['to']], $name);
			$this->assertSame('OCA\\Learniq\\Lifecycle\\LeaveDecisionGuard', $transition['requires'], $name);
			$this->assertSame([$stamp], $transition['actions'], $name);
		}

		$this->assertSame(['from' => 'submitted', 'to' => 'withdrawn', 'label' => 'Withdraw'], $lifecycle['transitions']['withdraw']);
	}//end testEveryDecisionIsGuardedAndStamped()

	/**
	 * A teacher reads the leave of their own pupils only; the deciders read and decide.
	 *
	 * @return void
	 */
	public function testWhoReadsAndWhoDecides(): void {
		$authorization = $this->schema()['authorization'];
		$scoped = ['group' => 'instructors', 'match' => ['teacherIds' => ['$contains' => '$userId']]];

		$this->assertSame([$scoped, 'coordinators', 'administration-managers', 'compliance-officers'], $authorization['read']);
		$this->assertSame(['coordinators', 'administration-managers'], $authorization['create']);
		$this->assertSame(['administration-managers', 'compliance-officers'], $authorization['update']);
	}//end testWhoReadsAndWhoDecides()
	/**
	 * The decider's dialog names each decision and asks the explanation, required on a refusal only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
	 */
	public function testTheDialogAsksTheExplanationAndRequiresItOnARefusal(): void {
		$transitions = $this->schema()['x-openregister-lifecycle']['transitions'];

		$this->assertSame('Allow', $transitions['allow']['label']);
		$this->assertSame('Refuse', $transitions['refuse']['label']);
		$this->assertSame('Forward to the attendance officer', $transitions['forward']['label']);

		$this->assertSame([['field' => 'decisionNote', 'required' => false]], $transitions['allow']['inputs']);
		$this->assertSame([['field' => 'decisionNote', 'required' => true]], $transitions['refuse']['inputs']);
		$this->assertSame([['field' => 'decisionNote', 'required' => false]], $transitions['forward']['inputs']);
	}//end testTheDialogAsksTheExplanationAndRequiresItOnARefusal()

	/**
	 * Every schema string has an English source key and a Dutch value in the catalogue.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
	 */
	public function testEverySchemaStringIsInTheCatalogue(): void {
		$root = __DIR__ . '/../../../l10n/';
		$en   = json_decode((string)file_get_contents($root . 'en.json'), true)['translations'];
		$nl   = json_decode((string)file_get_contents($root . 'nl.json'), true)['translations'];

		$strings = [$this->schema()['title']];
		foreach ($this->schema()['properties'] as $property) {
			$strings[] = $property['title'];
			$strings[] = $property['description'];
			foreach (($property['x-enum-labels'] ?? []) as $label) {
				$strings[] = $label;
			}
		}

		foreach ($strings as $string) {
			$this->assertSame($string, $en[$string] ?? null, $string);
			$this->assertNotSame('', (string)($nl[$string] ?? ''), $string);
		}

		$this->assertSame('Leave request', $this->schema()['title']);
		$this->assertSame('Verlofaanvraag', $nl['Leave request']);
		$this->assertSame('Doorgestuurd naar de leerplichtambtenaar', $nl[$this->schema()['properties']['lifecycle']['x-enum-labels']['forwarded']]);
	}//end testEverySchemaStringIsInTheCatalogue()
}//end class
