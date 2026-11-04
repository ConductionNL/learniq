<?php

/**
 * Learniq ConferenceWaitlistPlacement unit tests.
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
 * @spec openspec/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\ConferenceWaitlistPlacement;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ConferenceWaitlistPlacement::handle().
 */
class ConferenceWaitlistPlacementTest extends TestCase {

	private const SIGNUP = 'f0000000-0000-4000-8000-000000000001';

	/**
	 * The signup saves, as written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the listener over a signup in $lifecycle asking for two teachers,
	 * and the given slots.
	 *
	 * @param string                           $lifecycle The signup's state.
	 * @param array<int, array<string, mixed>> $slots     The slots naming the signup.
	 *
	 * @return ConferenceWaitlistPlacement
	 */
	private function listener(string $lifecycle, array $slots): ConferenceWaitlistPlacement {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('conference-slot');

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(
			OrEntityFactory::make(
				['id' => self::SIGNUP, 'lifecycle' => $lifecycle, 'requestedTeacherIds' => ['t-1', 't-2'], 'learnerId' => 'lrn-1'],
				'conference-signup'
			)
		);
		$objects->method('findAll')->willReturn($slots);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;

				return OrEntityFactory::make($object, 'conference-signup');
			}
		);

		return new ConferenceWaitlistPlacement(schemaResolver: $resolver, objectService: $objects, logger: new NullLogger());
	}//end listener()

	/**
	 * The new slot event for the signup.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function slotCreated(): ObjectCreatedEvent {
		return new ObjectCreatedEvent(OrEntityFactory::make(['signupId' => self::SIGNUP, 'teacherId' => 't-2'], 'conference-slot'));
	}//end slotCreated()

	/**
	 * Once every requested teacher has a slot, the waitlisted signup is scheduled.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
	 */
	public function testAPlacedSignupLeavesTheWaitlist(): void {
		$this->listener(lifecycle: 'waitlisted', slots: [['teacherId' => 't-1', 'lifecycle' => 'proposed'], ['teacherId' => 't-2']])->handle($this->slotCreated());

		self::assertCount(1, $this->saved);
		self::assertSame('scheduled', $this->saved[0]['lifecycle']);
		self::assertSame('lrn-1', $this->saved[0]['learnerId']);
	}//end testAPlacedSignupLeavesTheWaitlist()

	/**
	 * A request still unmet, or met only by a cancelled slot, keeps it waitlisted.
	 *
	 * @return void
	 */
	public function testAnUnmetRequestKeepsTheSignupWaitlisted(): void {
		$this->listener(lifecycle: 'waitlisted', slots: [['teacherId' => 't-1', 'lifecycle' => 'cancelled'], ['teacherId' => 't-2']])->handle($this->slotCreated());

		self::assertSame([], $this->saved);
	}//end testAnUnmetRequestKeepsTheSignupWaitlisted()

	/**
	 * A signup that is not waitlisted is left alone.
	 *
	 * @return void
	 */
	public function testASignupNotOnTheWaitlistIsLeftAlone(): void {
		$this->listener(lifecycle: 'scheduled', slots: [['teacherId' => 't-1'], ['teacherId' => 't-2']])->handle($this->slotCreated());

		self::assertSame([], $this->saved);
	}//end testASignupNotOnTheWaitlistIsLeftAlone()

	/**
	 * The listener is wired on a created object.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatedEvent::class . ' => ' . ConferenceWaitlistPlacement::class, $registered);
	}//end testTheListenerIsRegistered()
}//end class
