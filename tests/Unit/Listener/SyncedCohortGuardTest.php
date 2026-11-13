<?php

/**
 * Unit tests for SyncedCohortGuard.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\SyncedCohortGuard;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A synced cohort's source fields are read only for people; learniq's own fields stay editable; the sync passes.
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-the-sources-fields-are-read-only-on-a-synced-cohort
 */
class SyncedCohortGuardTest extends TestCase {

	/**
	 * A synced cohort as stored.
	 */
	private const SYNCED = [
		'id'             => 'cohort-1',
		'name'           => '3HV-B',
		'academicYear'   => '2026-2027',
		'programmeYear'  => 3,
		'learnerIds'     => ['anna', 'bram'],
		'teacherIds'     => ['t.mentor'],
		'externalId'     => 'mag-3hvb-2026',
		'externalSource' => 'magister',
		'syncedAt'       => '2026-11-02T07:00:00+00:00',
		'syncChanges'    => 3,
		'notes'          => '',
	];

	/**
	 * Build the guard.
	 *
	 * @param string|null $uid            The signed-in user, or null for a system write.
	 * @param string      $slug           The schema the resolver reports.
	 * @param bool        $resolverThrows Whether the resolver cannot tell the schema.
	 *
	 * @return SyncedCohortGuard
	 */
	private function guard(?string $uid, string $slug = 'cohort', bool $resolverThrows = false): SyncedCohortGuard {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		if ($resolverThrows === true) {
			$resolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('unknown schema'));
		} else {
			$resolver->method('guardSchemaSlug')->willReturn($slug);
		}

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SyncedCohortGuard(schemaResolver: $resolver, userSession: $session, logger: new NullLogger());
	}//end guard()

	/**
	 * An update of the stored cohort to the given one.
	 *
	 * @param array<string, mixed> $changes The changed fields.
	 * @param array<string, mixed> $stored  The stored cohort.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(array $changes, array $stored = self::SYNCED): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(OrEntityFactory::make(array_merge($stored, $changes), 'cohort'), OrEntityFactory::make($stored, 'cohort'));
	}//end update()

	/**
	 * Renaming a synced class is refused with a message that the name comes from the student administration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#scenario-a-coordinator-renames-a-synced-class
	 */
	public function testACoordinatorRenamesASyncedClass(): void {
		$event = $this->update(['name' => '3HV-B (mentorgroep)']);

		$this->guard(uid: 'coordinator')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			[
				'reason'  => 'cohort-field-from-source',
				'field'   => 'name',
				'message' => 'The name of this group comes from the student administration (magister) and cannot be changed in learniq.',
			],
			$event->getErrors()
		);
	}//end testACoordinatorRenamesASyncedClass()

	/**
	 * Each source field is guarded.
	 *
	 * @return void
	 */
	public function testEverySourceFieldIsReadOnly(): void {
		$changes = [
			'academicYear'   => ['2027-2028', 'school year'],
			'programmeYear'  => [4, 'programme year'],
			'learnerIds'     => [['anna'], 'learners'],
			'teacherIds'     => [[], 'teachers'],
			'externalId'     => ['other', 'external id'],
			'externalSource' => ['', 'source'],
		];
		foreach ($changes as $field => [$value, $label]) {
			$event = $this->update([$field => $value]);
			$this->guard(uid: 'coordinator')->handle($event);

			self::assertTrue($event->isPropagationStopped(), $field);
			self::assertSame($field, $event->getErrors()['field']);
			self::assertStringStartsWith('The '.$label.' of this group', $event->getErrors()['message']);
		}
	}//end testEverySourceFieldIsReadOnly()

	/**
	 * A note, a template or a reordered member list is not a change of the source's fields; the sync stamps stay the server's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#scenario-a-teacher-adds-a-note
	 */
	public function testATeacherAddsANote(): void {
		$event = $this->update(['notes' => 'Ouderavond 12 november', 'reportCardTemplateId' => 'tpl-1', 'learnerIds' => ['bram', 'anna'], 'programmeYear' => '3', 'syncChanges' => 99, 'syncedAt' => 'never']);

		$this->guard(uid: 't.mentor')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$saved = $event->getNewObject()->getObject();
		self::assertSame('Ouderavond 12 november', $saved['notes']);
		self::assertSame('tpl-1', $saved['reportCardTemplateId']);
		self::assertSame(3, $saved['syncChanges']);
		self::assertSame('2026-11-02T07:00:00+00:00', $saved['syncedAt']);
	}//end testATeacherAddsANote()

	/**
	 * A save that leaves a source field out does not change it.
	 *
	 * @return void
	 */
	public function testAPartialSaveOfLearniqsFieldsPasses(): void {
		$event = new ObjectUpdatingEvent(OrEntityFactory::make(['notes' => 'x'], 'cohort'), OrEntityFactory::make(self::SYNCED, 'cohort'));

		$this->guard(uid: 't.mentor')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAPartialSaveOfLearniqsFieldsPasses()

	/**
	 * The sync itself, a cohort made in learniq, another schema or an unknown one, and a create, all pass.
	 *
	 * @return void
	 */
	public function testWhatTheGuardLeavesAlone(): void {
		$sync = $this->update(['name' => 'renamed by the source', 'learnerIds' => ['anna']]);
		$this->guard(uid: null)->handle($sync);
		self::assertFalse($sync->isPropagationStopped(), 'the sync runs as the system');

		$own = $this->update(['name' => 'Werkgroep'], ['id' => 'c2', 'name' => 'Groep 6']);
		$this->guard(uid: 'coordinator')->handle($own);
		self::assertFalse($own->isPropagationStopped(), 'a group made in learniq stays editable');

		$other = $this->update(['name' => 'x']);
		$this->guard(uid: 'coordinator', slug: 'course')->handle($other);
		self::assertFalse($other->isPropagationStopped());

		$unknown = $this->update(['name' => 'x']);
		$this->guard(uid: 'coordinator', resolverThrows: true)->handle($unknown);
		self::assertFalse($unknown->isPropagationStopped());

		$create = new ObjectCreatingEvent(OrEntityFactory::make(self::SYNCED, 'cohort'));
		$this->guard(uid: 'coordinator')->handle($create);
		self::assertFalse($create->isPropagationStopped());
	}//end testWhatTheGuardLeavesAlone()

	/**
	 * The guard listens on every update.
	 *
	 * @return void
	 */
	public function testTheGuardIsRegistered(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event.' => '.$listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectUpdatingEvent::class.' => '.SyncedCohortGuard::class, $pairs);
	}//end testTheGuardIsRegistered()
}//end class
