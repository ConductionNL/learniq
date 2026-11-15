<?php

/**
 * Learniq ConcernReport register tests.
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
 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-a-learner-can-report-a-concern-to-the-confidential-counsellors
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The ConcernReport schema: who reads and writes it, its isolation, its
 * notification and its menu entries.
 *
 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-a-learner-can-report-a-concern-to-the-confidential-counsellors
 */
class ConcernReportRegisterTest extends TestCase {

	/**
	 * Schemas keyed by component name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas;

	/**
	 * Load the shipped register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemas = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true)['components']['schemas'];
	}//end setUp()

	/**
	 * Counsellors and the reporter read; anyone signed in creates; only
	 * counsellors change or delete; no other group appears.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-a-teacher-cannot-read-a-report
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-another-learner-cannot-read-a-report
	 */
	public function testOnlyCounsellorsAndTheReporterReadAReport(): void {
		$schema = $this->schemas['ConcernReport'];
		self::assertSame('concern-report', $schema['slug']);

		$auth = $schema['authorization'];
		self::assertSame(
			['confidential-counsellors', ['group' => 'authenticated', 'match' => ['reporterId' => '$userId']]],
			$auth['read']
		);
		self::assertSame(['authenticated'], $auth['create']);
		self::assertSame(['confidential-counsellors'], $auth['update']);
		self::assertSame(['confidential-counsellors'], $auth['delete']);

		$json = (string)json_encode($auth);
		foreach (['instructors', 'administration-managers', 'coordinators', 'compliance-officers', 'team-leads', 'hr', 'learners', 'guardians', 'admin"'] as $group) {
			self::assertStringNotContainsString($group, $json, $group . ' must not reach a concern report');
		}
	}//end testOnlyCounsellorsAndTheReporterReadAReport()

	/**
	 * The report stands alone, is not searchable and is deleted for real;
	 * the reporter cannot be changed after it is filed. Since 0.2.0 it holds
	 * one optional reference out, `learnerId` to the pupil a member of staff
	 * reported about (Ruben, 7 October 2026), and nothing references it.
	 *
	 * @return void
	 */
	public function testTheReportIsStructurallyIsolated(): void {
		$schema = $this->schemas['ConcernReport'];
		$properties = $schema['properties'];
		self::assertSame('LearnerProfile', $properties['learnerId']['$ref']);
		self::assertTrue($properties['learnerId']['nullable']);
		self::assertNotContains('learnerId', $schema['required'] ?? [], 'a pupil or a guardian files without a pupil');
		self::assertArrayNotHasKey('inversedBy', $properties['learnerId'], 'the pupil must not lead back to the report');
		unset($properties['learnerId']);
		self::assertStringNotContainsString('$ref', (string)json_encode($properties), 'learnerId is the only reference out');
		self::assertFalse($schema['x-openregister']['searchable']);
		self::assertTrue($schema['x-openregister']['hardDelete']);
		self::assertTrue($schema['properties']['reporterId']['readOnly']);
		self::assertNotContains('reporterId', $schema['required'], 'OpenRegister checks required before the stamp runs');

		foreach ($this->schemas as $name => $other) {
			if ($name === 'ConcernReport') {
				continue;
			}

			self::assertStringNotContainsString('"ConcernReport"', (string)json_encode($other), $name . ' must not reference ConcernReport');
			self::assertStringNotContainsString('concern-report', (string)json_encode($other['properties'] ?? []), $name . ' must not reference concern-report');
		}
	}//end testTheReportIsStructurallyIsolated()

	/**
	 * The counsellors are told a report arrived, and the subject names no one
	 * and nothing from the report.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-the-notification-is-anonymous
	 */
	public function testTheNotificationIsAnonymous(): void {
		$notices = $this->schemas['ConcernReport']['x-openregister-notifications'];
		self::assertSame(['reportReceived'], array_keys($notices));

		$notice = $notices['reportReceived'];
		self::assertSame(['type' => 'created'], $notice['trigger']);
		self::assertSame(['nc-notification'], $notice['channels']);
		self::assertSame([['kind' => 'groups', 'groups' => ['confidential-counsellors']]], $notice['recipients']);
		self::assertSame('A new confidential report has arrived', $notice['subject']['en']);
		foreach ($notice['subject'] as $subject) {
			self::assertStringNotContainsString('{{', $subject, 'the subject carries no field of the report');
		}
	}//end testTheNotificationIsAnonymous()

	/**
	 * The payload a learner posts, and the stamped row the listener stores,
	 * pass the shipped schema the way OpenRegister validates a write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-a-learner-files-a-report-and-sees-it
	 */
	public function testTheWrittenPayloadsPassTheRealSchema(): void {
		$validator = new Validator();
		$schema = (string)json_encode($this->validatable(schema: $this->schemas['ConcernReport']));

		$posted = ['topic' => 'bullying', 'description' => 'They take my bag every break.', 'happenedOn' => '2026-09-28', 'wantsConversation' => true];
		$stored = array_merge($posted, ['status' => 'received', 'reporterId' => 'lrn-12', 'tenant_id' => '00000000-0000-4000-8000-000000000001']);
		foreach (['posted' => $posted, 'stored' => $stored] as $label => $payload) {
			$result = $validator->validate(json_decode((string)json_encode($payload)), $schema);
			self::assertTrue($result->isValid(), $label . ': ' . json_encode($result->error()?->message()));
		}

		$noTopic = $posted;
		unset($noTopic['topic']);
		self::assertFalse($validator->validate(json_decode((string)json_encode($noTopic)), $schema)->isValid(), 'control: a report needs a topic');

		$badTopic = array_merge($posted, ['topic' => 'gossip']);
		self::assertFalse($validator->validate(json_decode((string)json_encode($badTopic)), $schema)->isValid(), 'control: a topic outside the list is refused');
	}//end testTheWrittenPayloadsPassTheRealSchema()

	/**
	 * Every signed-in user gets "Report a concern"; only counsellors get the
	 * inbox, gated like the confidential notes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-a-mentor-sees-only-the-report-form
	 */
	public function testTheMenusAreGated(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.d/confidential-counsel.json'), true);
		$menus = array_column($fragment['menu'], null, 'id');

		self::assertSame(['user.isConfidentialCounsellor' => ['eq' => true]], $menus['ConcernReportsMenu']['visibleIf']);
		self::assertArrayNotHasKey('visibleIf', $menus['ReportConcernMenu']);

		$pages = array_column($fragment['pages'], null, 'id');
		foreach (['ReportConcern', 'ConcernReports', 'ConcernReportDetail'] as $page) {
			self::assertSame('concern-report', $pages[$page]['config']['schema'], $page);
		}
	}//end testTheMenusAreGated()

	/**
	 * The schema without OpenRegister's own keys, which a JSON Schema validator cannot resolve.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private function validatable(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'authorization' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = $this->validatable(schema: $value);
			}
		}

		return $clean;
	}//end validatable()
}//end class
