<?php

/**
 * Learniq exam schedule unit tests.
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
use OCA\Learniq\Listener\InvigilatorAssignmentCheck;
use OCA\Learniq\Service\ExamSittingOverview;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\ExamScheduleFixture;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An invigilator can only be asked for a sitting their availability covers.
 *
 * @spec openspec/specs/exam-schedule/spec.md#requirement-invigilator-assignment
 */
class InvigilatorAssignmentCheckTest extends TestCase {

	private ExamScheduleFixture $fx;

	/**
	 * A Tuesday 09:00 sitting; `inv-tue` is available Tuesday morning, `inv-wed` only Wednesday.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->fx = new ExamScheduleFixture();
		$this->fx->add('exam-sitting', ['id' => 'sit-1', 'examPeriodId' => 'per-1', 'startsAt' => '2026-10-06T09:00:00+00:00', 'endsAt' => '2026-10-06T10:30:00+00:00', 'lifecycle' => 'planned', 'tenant_id' => ExamScheduleFixture::TENANT]);
		$this->fx->add('invigilator-availability', ['id' => 'av-1', 'invigilatorId' => 'inv-tue', 'examPeriodId' => 'per-1', 'availableFrom' => '2026-10-06T08:00:00+00:00', 'availableUntil' => '2026-10-06T12:00:00+00:00', 'tenant_id' => ExamScheduleFixture::TENANT]);
		$this->fx->add('invigilator-availability', ['id' => 'av-2', 'invigilatorId' => 'inv-wed', 'examPeriodId' => 'per-1', 'availableFrom' => '2026-10-07T08:00:00+00:00', 'availableUntil' => '2026-10-07T12:00:00+00:00', 'tenant_id' => ExamScheduleFixture::TENANT]);
	}//end setUp()

	/**
	 * The listener under test.
	 *
	 * @param string $slug The slug the resolver reports.
	 *
	 * @return InvigilatorAssignmentCheck
	 */
	private function check(string $slug = 'invigilator-assignment'): InvigilatorAssignmentCheck {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		return new InvigilatorAssignmentCheck(
			schemaResolver: $resolver,
			overview: new ExamSittingOverview(objectService: $this->fx->wire($this->createMock(ObjectService::class))),
			logger: new NullLogger(),
		);
	}//end check()

	/**
	 * A create for this invigilator.
	 *
	 * @param string $invigilator The invigilator.
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(string $invigilator, array $extra = []): ObjectCreatingEvent {
		return new ObjectCreatingEvent(
			OrEntityFactory::make(array_merge(['examSittingId' => 'sit-1', 'invigilatorId' => $invigilator, 'tenant_id' => ExamScheduleFixture::TENANT], $extra), 'invigilator-assignment')
		);
	}//end create()

	/**
	 * An available invigilator is assigned and starts pending, whatever the client sent.
	 *
	 * @return void
	 */
	public function testAnAvailableInvigilatorIsAssignedPending(): void {
		$event = $this->create('inv-tue', ['lifecycle' => 'confirmed']);

		$this->check()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('pending', $event->getModifiedData()['lifecycle']);
	}//end testAnAvailableInvigilatorIsAssignedPending()

	/**
	 * Someone not available on Tuesday is refused.
	 *
	 * @return void
	 */
	public function testAnUnavailableInvigilatorIsRefused(): void {
		$event = $this->create('inv-wed');

		$this->check()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('invigilator-not-available', $event->getErrors()['reason']);
	}//end testAnUnavailableInvigilatorIsRefused()

	/**
	 * The same person cannot be asked twice for one sitting while a request stands; after a decline they can.
	 *
	 * @return void
	 */
	public function testASecondOpenRequestIsRefusedButADeclineFreesIt(): void {
		$this->fx->add('invigilator-assignment', ['id' => 'as-1', 'examSittingId' => 'sit-1', 'invigilatorId' => 'inv-tue', 'lifecycle' => 'pending', 'tenant_id' => ExamScheduleFixture::TENANT]);
		$event = $this->create('inv-tue');
		$this->check()->handle($event);
		self::assertSame('invigilator-already-assigned', $event->getErrors()['reason']);

		$this->fx->store->rows['invigilator-assignment'][0]['lifecycle'] = 'declined';
		$again = $this->create('inv-tue');
		$this->check()->handle($again);
		self::assertFalse($again->isPropagationStopped());
	}//end testASecondOpenRequestIsRefusedButADeclineFreesIt()

	/**
	 * A sitting that does not exist is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownSittingIsRefused(): void {
		$event = $this->create('inv-tue', ['examSittingId' => 'sit-nope']);

		$this->check()->handle($event);

		self::assertSame('invigilator-sitting-unknown', $event->getErrors()['reason']);
	}//end testAnUnknownSittingIsRefused()

	/**
	 * The check is wired on create.
	 *
	 * @return void
	 */
	public function testTheCheckIsRegisteredOnCreate(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . InvigilatorAssignmentCheck::class, $pairs);
	}//end testTheCheckIsRegisteredOnCreate()
}//end class
