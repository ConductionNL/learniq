<?php

/**
 * ConferenceInvitationSync round test: which writes sync a round, and the
 * log line when the sync fails.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\ConferenceInvitationSync;
use OCA\Learniq\Service\ConferenceInvitations;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins which round writes ConferenceInvitationSync syncs.
 */
class ConferenceInvitationSyncRoundTest extends TestCase {

	/**
	 * Every sync and log line, in order.
	 *
	 * @var \ArrayObject<int,mixed>
	 */
	private \ArrayObject $calls;

	/**
	 * Fresh record.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = new \ArrayObject();
	}//end setUp()

	/**
	 * Run an event through the listener.
	 *
	 * @param Event $event  The event.
	 * @param bool  $throws Whether the sync fails.
	 *
	 * @return void
	 */
	private function handle(Event $event, bool $throws=false): void {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturnCallback(static fn ($entity): string => (string)$entity->getSchema());

		$invitations = $this->createMock(ConferenceInvitations::class);
		$invitations->method('syncRound')->willReturnCallback(
			function (array $round) use ($throws): int {
				// The entity's own metadata is not what this test is about.
				unset($round['@self']);
				$this->calls[] = ['sync', $round];
				if ($throws === true) {
					throw new RuntimeException('store down');
				}

				return 1;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		(new ConferenceInvitationSync($resolver, $this->createMock(ObjectService::class), $invitations, $logger))->handle($event);
	}//end handle()

	/**
	 * A round row.
	 *
	 * @param array<string,mixed> $override Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private function round(array $override=[]): array {
		return array_merge(
			['id' => 'r1', 'name' => 'Autumn', 'lifecycle' => 'booking-open', 'invitedLearnerRefs' => ['c1'], 'bookingMode' => 'direct', 'tenant_id' => 't1', 'notes' => 'a'],
			$override
		);
	}//end round()

	/**
	 * A created round syncs; an update syncs only when a field the invitations
	 * depend on changed, with the new round.
	 *
	 * @return void
	 */
	public function testWhichRoundWritesSync(): void {
		$this->handle(new ObjectCreatedEvent(OrEntityFactory::make($this->round(), 'conference-round')));
		self::assertSame([['sync', $this->round()]], $this->calls->getArrayCopy());

		foreach (['invitedLearnerRefs' => ['c2'], 'lifecycle' => 'closed', 'name' => 'Spring', 'bookingClosesAt' => '2026-11-01', 'bookingMode' => 'planned', 'tenant_id' => 't2'] as $field => $value) {
			$this->setUp();
			$new = $this->round([$field => $value]);
			$this->handle(new ObjectUpdatedEvent(OrEntityFactory::make($new, 'conference-round'), OrEntityFactory::make($this->round(), 'conference-round')));
			self::assertSame([['sync', $new]], $this->calls->getArrayCopy(), $field);
		}

		$this->setUp();
		$this->handle(new ObjectUpdatedEvent(OrEntityFactory::make($this->round(['notes' => 'b']), 'conference-round'), OrEntityFactory::make($this->round(), 'conference-round')));
		self::assertSame([], $this->calls->getArrayCopy());
	}//end testWhichRoundWritesSync()

	/**
	 * Another schema or another event syncs nothing; a failed sync is logged.
	 *
	 * @return void
	 */
	public function testOtherWritesAndAFailedSync(): void {
		$this->handle(new ObjectCreatedEvent(OrEntityFactory::make($this->round(), 'conference-signup')));
		$this->handle(new Event());
		self::assertSame([], $this->calls->getArrayCopy());

		$this->handle(new ObjectCreatedEvent(OrEntityFactory::make($this->round(), 'conference-round')), throws: true);
		self::assertSame(
			[
				['sync', $this->round()],
				['error', '[ConferenceInvitationSync] Invitations of round {round} could not be written: {msg}', ['round' => 'r1', 'msg' => 'store down']],
			],
			$this->calls->getArrayCopy()
		);
	}//end testOtherWritesAndAFailedSync()
}//end class
