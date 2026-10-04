<?php

/**
 * Learniq ExcuseRequest register test.
 *
 * OpenRegister validates a schema's `required` list before any listener runs,
 * so a field the portal cannot send must not be in it, or every portal absence
 * report is refused before ExcuseRequestOwnerStamp can fill it.
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
 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the ExcuseRequest required list fits what the portal sends.
 */
class ExcuseRequestRegisterTest extends TestCase {

	/**
	 * The ExcuseRequest schema as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return $register['components']['schemas']['ExcuseRequest'];
	}//end schema()

	/**
	 * The fields a portal create action lets the caller send, plus the field
	 * portaliq stamps from the subject.
	 *
	 * @param string $audience The portal audience.
	 *
	 * @return array<int, string>
	 */
	private function portalFields(string $audience): array {
		$manifest = (new PortalContributionProvider())->getContribution(
			subject: ['audience' => $audience, 'subjectRef' => '00000000-0000-4000-8000-000000000000', 'trustLevel' => 'high']
		);

		foreach ($manifest['actions'] as $action) {
			if ($action['id'] === 'createExcuseRequest') {
				return [...$action['fields'], $action['scopeField']];
			}
		}

		self::fail('No createExcuseRequest action for ' . $audience);
	}//end portalFields()

	/**
	 * Required holds only what every caller sends. `learnerRef` joined it on
	 * 3 October 2026 (absence-and-booking-fields-are-required): the pupil's
	 * portal stamps it, the guardian's form requires it and the staff form
	 * sends it.
	 *
	 * @return void
	 */
	public function testRequiredHoldsOnlyWhatEveryCallerSends(): void {
		self::assertSame(['dateFrom', 'dateTo', 'reason', 'reasonKind', 'learnerRef'], $this->schema()['required']);
	}//end testRequiredHoldsOnlyWhatEveryCallerSends()

	/**
	 * A pupil's and a guardian's portal report both carry every required field,
	 * so OpenRegister's validator lets them reach the owner stamp.
	 *
	 * @return void
	 */
	public function testEveryPortalReportCarriesTheRequiredFields(): void {
		foreach (['student', 'parent'] as $audience) {
			$missing = array_values(array_diff($this->schema()['required'], $this->portalFields(audience: $audience)));
			self::assertSame([], $missing, $audience);
		}
	}//end testEveryPortalReportCarriesTheRequiredFields()

	/**
	 * The owner fields the stamp fills are still declared, so the stamp writes
	 * properties OpenRegister stores.
	 *
	 * @return void
	 */
	public function testTheStampedFieldsAreDeclared(): void {
		foreach (['learnerId', 'learnerRef', 'submittedBy', 'submittedByRef', 'submittedAuthLevel', 'tenant_id'] as $field) {
			self::assertArrayHasKey($field, $this->schema()['properties'], $field);
		}

		self::assertSame(
			['none', 'basic', 'substantial', 'high'],
			$this->schema()['properties']['submittedAuthLevel']['enum']
		);
	}//end testTheStampedFieldsAreDeclared()
	/**
	 * Approving or rejecting a report records who decided and when. Found on
	 * a school's parent portal: the teacher approved a guardian's report,
	 * the guardian saw "approved" but no decision date, and the record did
	 * not say which teacher decided.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-decision-records-who-and-when/specs/attendance/spec.md
	 */
	public function testADecisionStampsTheDeciderAndTheTime(): void {
		$transitions = $this->schema()['x-openregister-lifecycle']['transitions'];

		foreach (['approve', 'reject'] as $name) {
			$this->assertContains(
				[
					'action' => 'OCA\\Learniq\\Lifecycle\\Action\\StampTransitionActorAction',
					'actionParameters' => ['actorField' => 'decidedBy', 'timeField' => 'decidedAt'],
				],
				($transitions[$name]['actions'] ?? []),
				$name
			);
		}
	}//end testADecisionStampsTheDeciderAndTheTime()

	/**
	 * A teacher reads and decides only the reports of pupils in a group they
	 * teach; coordinators and directors read every report in the school and
	 * coordinators decide them. Found on a clean primary-school install: a
	 * group teacher saw the absence reports of the whole school.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
	 */
	public function testATeacherReadsOnlyTheReportsOfTheirOwnGroups(): void {
		$authorization = $this->schema()['authorization'];
		$scoped = ['group' => 'instructors', 'match' => ['teacherIds' => ['$contains' => '$userId']]];

		self::assertSame([$scoped, 'coordinators', 'administration-managers', 'compliance-officers'], $authorization['read']);
		self::assertSame([$scoped, 'coordinators', 'compliance-officers'], $authorization['update']);
		self::assertSame(['instructors', 'coordinators', 'compliance-officers'], $authorization['create']);

		foreach (['read', 'update'] as $action) {
			self::assertNotContains('instructors', $authorization[$action], $action . ': no school-wide grant for teachers');
		}
	}//end testATeacherReadsOnlyTheReportsOfTheirOwnGroups()

	/**
	 * The field the rules match on is declared as a list of user ids, with a
	 * catalogue key for its label and its help text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
	 */
	public function testTheTeacherFieldIsDeclaredAndTranslated(): void {
		$field = $this->schema()['properties']['teacherIds'];
		self::assertSame('array', $field['type']);
		self::assertSame(['type' => 'string'], $field['items']);
		self::assertNotContains('teacherIds', $this->schema()['required'], 'stamped by the server, never sent by the portal');

		$nl = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];
		foreach ([$field['title'], $field['description']] as $text) {
			self::assertArrayHasKey($text, $nl);
			self::assertNotSame($text, $nl[$text]);
		}
	}//end testTheTeacherFieldIsDeclaredAndTranslated()
}//end class
