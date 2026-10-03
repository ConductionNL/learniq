<?php

/**
 * Learniq AttendanceSummaryListener unit tests.
 *
 * Every test hands the listener the REAL OpenRegister event class it is
 * registered for (the mirrors under tests/Stubs carry the real constructors
 * and accessors), so a wrong accessor fails here and not on a live write.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\BackgroundJob\AttendanceSummaryRecomputeJob;
use OCA\Learniq\Listener\AttendanceSummaryListener;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use PHPUnit\Framework\TestCase;

/**
 * A record write defers a recount of the learner's summary.
 */
class AttendanceSummaryListenerTest extends TestCase {

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	/** @var array<int, array{jobClass: string, entry: array<string, mixed>, dedupeKey: string|null}> */
	private array $deferred = [];

	/**
	 * The listener, resolving every entity to the given schema slug.
	 *
	 * @param string $schema   Schema slug.
	 * @param string $register Register slug.
	 *
	 * @return AttendanceSummaryListener
	 */
	private function listener(string $schema='attendance-record', string $register='learniq'): AttendanceSummaryListener {
		$this->deferred = [];
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize=100, ?string $dedupeKey=null): void {
				$this->deferred[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
			}
		);

		$resolver = $this->createMock(ListenerSchemaResolver::class);
		// guardSchemaSlug() answers '' for an object outside Learniq's register.
		$resolver->method('guardSchemaSlug')->willReturn($register === 'learniq' ? $schema : '');

		return new AttendanceSummaryListener(deferral: $deferral, schemaResolver: $resolver);
	}//end listener()

	/**
	 * A record entity.
	 *
	 * @param array<string, mixed> $extra Fields to change.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private static function entity(array $extra=[]): \OCA\OpenRegister\Db\ObjectEntity {
		return OrEntityFactory::make(
			array_merge(
				['sessionId' => 'session-1', 'learnerId' => 'pupil-1', 'learnerRef' => 'ref-1', 'status' => 'late', 'tenant_id' => self::TENANT],
				$extra
			),
			'attendance-record'
		);
	}//end entity()

	/**
	 * A created record defers one recount entry for its learner and lesson.
	 *
	 * @return void
	 */
	public function testACreatedRecordDefersARecount(): void {
		$this->listener()->handle(new ObjectCreatedEvent(self::entity()));

		self::assertCount(1, $this->deferred);
		self::assertSame(AttendanceSummaryRecomputeJob::class, $this->deferred[0]['jobClass']);
		self::assertSame(
			['learnerId' => 'pupil-1', 'sessionId' => 'session-1', 'tenantId' => self::TENANT, 'learnerRef' => 'ref-1'],
			$this->deferred[0]['entry']
		);
		self::assertSame('pupil-1|session-1', $this->deferred[0]['dedupeKey']);
	}//end testACreatedRecordDefersARecount()

	/**
	 * An updated record defers a recount; moved to another lesson or pupil, the old one too.
	 *
	 * @return void
	 */
	public function testAnUpdatedRecordAlsoRecountsWhatItMovedAwayFrom(): void {
		$this->listener()->handle(new ObjectUpdatedEvent(self::entity(['status' => 'absent-excused']), self::entity()));
		self::assertCount(1, $this->deferred);

		$this->listener()->handle(new ObjectUpdatedEvent(self::entity(['sessionId' => 'session-2']), self::entity()));
		self::assertSame(['pupil-1|session-2', 'pupil-1|session-1'], array_column($this->deferred, 'dedupeKey'));

		// An update event without the old object still recounts the new state.
		$this->listener()->handle(new ObjectUpdatedEvent(self::entity()));
		self::assertCount(1, $this->deferred);
	}//end testAnUpdatedRecordAlsoRecountsWhatItMovedAwayFrom()

	/**
	 * A deleted record defers a recount, so its absence stops counting.
	 *
	 * @return void
	 */
	public function testADeletedRecordDefersARecount(): void {
		$this->listener()->handle(new ObjectDeletedEvent(self::entity()));

		self::assertCount(1, $this->deferred);
		self::assertSame('pupil-1', $this->deferred[0]['entry']['learnerId']);
	}//end testADeletedRecordDefersARecount()

	/**
	 * The listener over the REAL resolver, whose mappers answer like OpenRegister's.
	 *
	 * @return AttendanceSummaryListener
	 */
	private function listenerOverTheRealResolver(): AttendanceSummaryListener {
		$this->deferred = [];
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize=100, ?string $dedupeKey=null): void {
				$this->deferred[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
			}
		);

		return new AttendanceSummaryListener(deferral: $deferral, schemaResolver: TransitionScope::resolver());
	}//end listenerOverTheRealResolver()

	/**
	 * A record entity as OpenRegister materialises it: numeric register and schema ids.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema slug the id stands for.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private static function entityWithIds(string $register=TransitionScope::LEARNIQ_REGISTER_ID, string $schema='attendance-record'): \OCA\OpenRegister\Db\ObjectEntity {
		return OrEntityFactory::make(
			['sessionId' => 'session-1', 'learnerId' => 'pupil-1', 'learnerRef' => 'ref-1', 'status' => 'absent-unexcused', 'tenant_id' => self::TENANT],
			TransitionScope::schemaId($schema),
			$register
		);
	}//end entityWithIds()

	/**
	 * A record carrying numeric ids (OpenRegister's default) still defers a recount,
	 * with the listener slug contract at its shipped default (off).
	 *
	 * @return void
	 */
	public function testARecordCarryingIdsDefersARecountWithTheContractOff(): void {
		$this->listenerOverTheRealResolver()->handle(new ObjectCreatedEvent(self::entityWithIds()));
		self::assertCount(1, $this->deferred);
		self::assertSame(AttendanceSummaryRecomputeJob::class, $this->deferred[0]['jobClass']);
		self::assertSame('pupil-1|session-1', $this->deferred[0]['dedupeKey']);

		$this->listenerOverTheRealResolver()->handle(new ObjectUpdatedEvent(self::entityWithIds(), self::entityWithIds()));
		self::assertCount(1, $this->deferred);

		$this->listenerOverTheRealResolver()->handle(new ObjectDeletedEvent(self::entityWithIds()));
		self::assertCount(1, $this->deferred);
	}//end testARecordCarryingIdsDefersARecountWithTheContractOff()

	/**
	 * By id, another schema or another app's register still does not match.
	 *
	 * @return void
	 */
	public function testIdsOfAnotherSchemaOrRegisterDoNotMatch(): void {
		$this->listenerOverTheRealResolver()->handle(new ObjectCreatedEvent(self::entityWithIds(schema: 'session')));
		self::assertSame([], $this->deferred);

		$this->listenerOverTheRealResolver()->handle(new ObjectCreatedEvent(self::entityWithIds(register: TransitionScope::OTHER_REGISTER_ID)));
		self::assertSame([], $this->deferred);
	}//end testIdsOfAnotherSchemaOrRegisterDoNotMatch()

	/**
	 * Writes to other schemas, other registers, other events or without a learner are ignored.
	 *
	 * @return void
	 */
	public function testIgnoresWhatIsNotAnAttendanceRecordWrite(): void {
		$this->listener(schema: 'session')->handle(new ObjectCreatedEvent(self::entity()));
		self::assertSame([], $this->deferred);

		$this->listener(register: 'shillinq')->handle(new ObjectCreatedEvent(self::entity()));
		self::assertSame([], $this->deferred);

		$this->listener()->handle(new ObjectTransitionedEvent(object: self::entity(), action: 'go', from: 'a', to: 'b', userId: null, register: 'learniq', schema: 'attendance-record'));
		self::assertSame([], $this->deferred);

		$this->listener()->handle(new ObjectCreatedEvent(self::entity(['learnerId' => ''])));
		self::assertSame([], $this->deferred);
	}//end testIgnoresWhatIsNotAnAttendanceRecordWrite()
}//end class
