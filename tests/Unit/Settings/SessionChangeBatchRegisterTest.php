<?php

/**
 * Register contract for SessionChangeBatch and Session.changeBatchId.
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
 * @spec openspec/specs/timetabling/spec.md#requirement-affected-people-get-one-message-per-batch
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The batch schema, its one message, and the lesson's pointer to it.
 */
class SessionChangeBatchRegisterTest extends TestCase {

	/**
	 * The register's schemas.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas;

	/**
	 * Load the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$config = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true);
		$this->schemas = $config['components']['schemas'];
	}//end setUp()

	/**
	 * The batch schema exists with its slug, version and required fields.
	 *
	 * @return void
	 */
	public function testBatchSchemaIsDeclared(): void {
		$batch = $this->schemas['SessionChangeBatch'];

		self::assertSame('session-change-batch', $batch['slug']);
		self::assertSame('0.1.0', $batch['version']);
		self::assertSame(['cancel', 'substitute', 'room'], $batch['properties']['kind']['enum']);
		self::assertSame('Session', $batch['properties']['sessionIds']['items']['$ref']);
		foreach (['kind', 'sessionIds', 'changeReasonKind', 'tenant_id'] as $field) {
			self::assertContains($field, $batch['required']);
		}
	}//end testBatchSchemaIsDeclared()

	/**
	 * The batch sends one message on create to the union of affected people.
	 *
	 * @return void
	 */
	public function testBatchSendsOneMessageToTheAffectedPeople(): void {
		$rule = $this->schemas['SessionChangeBatch']['x-openregister-notifications']['batchApplied'];

		self::assertSame('created', $rule['trigger']['type']);
		self::assertSame(
			[
				['kind' => 'field', 'field' => 'affectedLearnerIds'],
				['kind' => 'field', 'field' => 'affectedParentIds'],
			],
			$rule['recipients']
		);
		self::assertStringContainsString('{{lessonDates}}', $rule['subject']['nl']);
		self::assertStringContainsString('{{lessonDates}}', $rule['subject']['en']);
	}//end testBatchSendsOneMessageToTheAffectedPeople()

	/**
	 * Staff groups read and write batches; nobody else.
	 *
	 * @return void
	 */
	public function testOnlyStaffReadAndWriteBatches(): void {
		$auth = $this->schemas['SessionChangeBatch']['authorization'];

		foreach (['read', 'create', 'update'] as $verb) {
			self::assertNotContains('authenticated', $auth[$verb]);
			self::assertNotContains('learners', $auth[$verb]);
			self::assertContains('coordinators', $auth[$verb]);
		}
	}//end testOnlyStaffReadAndWriteBatches()

	/**
	 * A lesson points at its batch, and Session's version moved.
	 *
	 * @return void
	 */
	public function testSessionPointsAtItsBatch(): void {
		$session = $this->schemas['Session'];

		self::assertTrue(version_compare($session['version'], '0.2.0', '>='), 'Session version moved with changeBatchId.');
		self::assertSame('SessionChangeBatch', $session['properties']['changeBatchId']['$ref']);
		self::assertTrue($session['properties']['changeBatchId']['nullable']);
	}//end testSessionPointsAtItsBatch()
}//end class
