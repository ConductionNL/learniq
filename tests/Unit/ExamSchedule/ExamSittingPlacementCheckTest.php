<?php

/**
 * Learniq ExamSittingPlacementCheck unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\ExamSchedule
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\ExamSchedule;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\ExamSittingPlacementCheck;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\ExamScheduleFixture;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Capacity refusal and clash recording for an ExamSitting write.
 *
 * @spec openspec/specs/exam-schedule/spec.md#requirement-exam-periods-and-sittings
 */
class ExamSittingPlacementCheckTest extends TestCase {

	private ExamScheduleFixture $fx;

	/**
	 * Two rooms, one cohort with a lesson on Tuesday morning.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->fx = new ExamScheduleFixture();
		$this->fx->add('room', ['id' => 'room-a1', 'name' => 'A1', 'capacity' => 30, 'kind' => 'classroom', 'tenant_id' => ExamScheduleFixture::TENANT]);
		$this->fx->add('room', ['id' => 'room-gym', 'name' => 'Gym', 'capacity' => 80, 'kind' => 'sports', 'tenant_id' => ExamScheduleFixture::TENANT]);
		$this->fx->add('session', ['id' => 'ses-1', 'title' => 'Dutch 4B', 'cohortId' => 'coh-4b', 'roomId' => 'room-b2', 'startsAt' => '2026-10-06T09:30:00+00:00', 'endsAt' => '2026-10-06T10:20:00+00:00', 'lifecycle' => 'scheduled', 'tenant_id' => ExamScheduleFixture::TENANT]);
		$this->fx->add('session', ['id' => 'ses-2', 'title' => 'Cancelled PE', 'cohortId' => 'coh-4b', 'roomId' => 'room-gym', 'startsAt' => '2026-10-06T09:00:00+00:00', 'endsAt' => '2026-10-06T10:00:00+00:00', 'lifecycle' => 'cancelled', 'tenant_id' => ExamScheduleFixture::TENANT]);
		$this->fx->add('exam', ['id' => 'asm-1', 'title' => 'Maths B', 'tenant_id' => ExamScheduleFixture::TENANT]);
	}//end setUp()

	/**
	 * The listener with the schema resolver answering the given slug.
	 *
	 * @param string $slug The slug the resolver reports.
	 *
	 * @return ExamSittingPlacementCheck
	 */
	private function check(string $slug = 'exam-sitting'): ExamSittingPlacementCheck {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		return new ExamSittingPlacementCheck(
			schemaResolver: $resolver,
			objectService: $this->fx->wire($this->createMock(ObjectService::class)),
			logger: new NullLogger(),
		);
	}//end check()

	/**
	 * A sitting payload.
	 *
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function sitting(array $extra = []): array {
		return array_merge(
			[
				'id' => 'sit-1',
				'examPeriodId' => 'per-1',
				'assessmentId' => 'asm-1',
				'cohortIds' => ['coh-5a'],
				'startsAt' => '2026-10-06T09:00:00+00:00',
				'endsAt' => '2026-10-06T10:30:00+00:00',
				'roomIds' => ['room-a1'],
				'headcount' => 28,
				'invigilatorsNeeded' => 1,
				'lifecycle' => 'planned',
				'tenant_id' => ExamScheduleFixture::TENANT,
			],
			$extra
		);
	}//end sitting()

	/**
	 * A clean placement is stored with no clashes.
	 *
	 * @return void
	 */
	public function testACleanPlacementPassesWithNoClashes(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->sitting(), 'exam-sitting'));

		$this->check()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData()['clashWarnings']);
	}//end testACleanPlacementPassesWithNoClashes()

	/**
	 * A room too small is refused with the capacity and the headcount.
	 *
	 * @return void
	 */
	public function testARoomTooSmallIsRefused(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->sitting(['headcount' => 60]), 'exam-sitting'));

		$this->check()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		self::assertSame('exam-room-too-small', $errors['reason']);
		self::assertSame(30, $errors['capacity']);
		self::assertSame(60, $errors['headcount']);
		self::assertStringContainsString('30', $errors['message']);
		self::assertStringContainsString('60', $errors['message']);
	}//end testARoomTooSmallIsRefused()

	/**
	 * Two rooms together may seat the whole group.
	 *
	 * @return void
	 */
	public function testRoomsAddUpTheirCapacity(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->sitting(['headcount' => 100, 'roomIds' => ['room-a1', 'room-gym']]), 'exam-sitting'));

		$this->check()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testRoomsAddUpTheirCapacity()

	/**
	 * A room the register does not know is refused, never counted as zero seats silently.
	 *
	 * @return void
	 */
	public function testAnUnknownRoomIsRefused(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->sitting(['roomIds' => ['room-nope']]), 'exam-sitting'));

		$this->check()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('exam-room-unknown', $event->getErrors()['reason']);
	}//end testAnUnknownRoomIsRefused()

	/**
	 * A cohort with another lesson in the slot gets a clash that names that lesson.
	 * The cancelled lesson in the same slot is not a clash.
	 *
	 * @return void
	 */
	public function testACohortClashNamesTheOtherSession(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->sitting(['cohortIds' => ['coh-4b']]), 'exam-sitting'));

		$this->check()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$clashes = $event->getModifiedData()['clashWarnings'];
		self::assertCount(1, $clashes);
		self::assertStringContainsString('Dutch 4B', $clashes[0]);
	}//end testACohortClashNamesTheOtherSession()

	/**
	 * Another exam in the same room in the same slot is a clash; the sitting itself is not.
	 *
	 * @return void
	 */
	public function testAnotherExamInTheSameRoomIsAClash(): void {
		$this->fx->add('exam-sitting', $this->sitting(['id' => 'sit-other', 'startsAt' => '2026-10-06T10:00:00+00:00', 'endsAt' => '2026-10-06T11:00:00+00:00', 'cohortIds' => ['coh-6c']]));
		$this->fx->add('exam-sitting', $this->sitting());
		$stored = $this->sitting();
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make($stored, 'exam-sitting'),
			OrEntityFactory::make($stored, 'exam-sitting')
		);

		$this->check()->handle($event);

		$clashes = $event->getModifiedData()['clashWarnings'];
		self::assertCount(1, $clashes);
		self::assertStringContainsString('Maths B', $clashes[0]);
	}//end testAnotherExamInTheSameRoomIsAClash()

	/**
	 * An end before the start is refused.
	 *
	 * @return void
	 */
	public function testAnEndBeforeTheStartIsRefused(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->sitting(['endsAt' => '2026-10-06T08:00:00+00:00']), 'exam-sitting'));

		$this->check()->handle($event);

		self::assertSame('exam-times-invalid', $event->getErrors()['reason']);
	}//end testAnEndBeforeTheStartIsRefused()

	/**
	 * A client cannot write its own clash list.
	 *
	 * @return void
	 */
	public function testAClientClashListIsReplaced(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->sitting(['clashWarnings' => ['made up']]), 'exam-sitting'));

		$this->check()->handle($event);

		self::assertSame([], $event->getModifiedData()['clashWarnings']);
	}//end testAClientClashListIsReplaced()

	/**
	 * Another schema's write is never touched.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($this->sitting(['headcount' => 999]), 'session'));

		$this->check('session')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * The check is wired on create and update.
	 *
	 * @return void
	 */
	public function testTheCheckIsRegisteredOnCreateAndUpdate(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . ExamSittingPlacementCheck::class, $pairs);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . ExamSittingPlacementCheck::class, $pairs);
	}//end testTheCheckIsRegisteredOnCreateAndUpdate()
}//end class
