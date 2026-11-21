<?php

/**
 * Portaliq's staff messaging as the pupil's page reaches it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use OCA\Learniq\Service\Pupil\PortaliqStaffMessages;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\Pupil\PortaliqStaffMessages
 */
class PortaliqStaffMessagesTest extends TestCase {

	/**
	 * The app manager double.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * The container double.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
	}//end setUp()

	/**
	 * The adapter over a messaging service.
	 *
	 * @param object|null $messaging The service the container answers, null for none.
	 * @param bool        $installed Whether portaliq is installed.
	 *
	 * @return PortaliqStaffMessages
	 */
	private function adapter(?object $messaging, bool $installed=true): PortaliqStaffMessages {
		$this->appManager->method('isInstalled')->with('portaliq')->willReturn($installed);
		if ($messaging === null) {
			$this->container->method('get')->willThrowException(new RuntimeException('no such service'));
		} else {
			$this->container->method('get')->with(PortaliqStaffMessages::MESSAGING_CLASS)->willReturn($messaging);
		}

		return new PortaliqStaffMessages($this->appManager, $this->container, $this->createMock(LoggerInterface::class));
	}//end adapter()

	/**
	 * A messaging double that records its calls.
	 *
	 * @param array<int, array<string, mixed>>|null $messages What listMessages answers.
	 * @param bool                                  $posted   What postMessage answers.
	 *
	 * @return object
	 */
	private function messaging(?array $messages=[], bool $posted=true): object {
		return new class ($messages, $posted) {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			/**
			 * @param array<int, array<string, mixed>>|null $messages
			 * @param bool                                  $posted
			 */
			public function __construct(private readonly ?array $messages, private readonly bool $posted) {
			}

			/** @return array<int, mixed> */
			public function listThreads(string $subjectRef, bool $isStaff): array {
				$this->calls[] = ['listThreads', $subjectRef, $isStaff];
				return [['id' => 't1', 'recordRef' => 'vera'], 'not a row'];
			}

			/** @return array<int, mixed>|null */
			public function listMessages(string $threadId, string $subjectRef, bool $isStaff): ?array {
				$this->calls[] = ['listMessages', $threadId, $subjectRef, $isStaff];
				return $this->messages;
			}

			public function postMessage(string $threadId, string $senderRef, bool $senderIsStaff, string $body): bool {
				$this->calls[] = ['postMessage', $threadId, $senderRef, $senderIsStaff, $body];
				return $this->posted;
			}
		};
	}//end messaging()

	/**
	 * The calls reach portaliq as the staff side, with the staff member's ref.
	 *
	 * @return void
	 */
	public function testCallsReachPortaliqAsStaff(): void {
		$messaging = $this->messaging(messages: [['id' => 'm1'], 'junk']);
		$adapter = $this->adapter(messaging: $messaging);

		$this->assertSame([['id' => 't1', 'recordRef' => 'vera']], $adapter->threads(staffRef: 'daan'));
		$this->assertSame([['id' => 'm1']], $adapter->messages(threadId: 't1', staffRef: 'daan'));
		$this->assertTrue($adapter->post(threadId: 't1', staffRef: 'daan', body: 'Hallo'));
		$this->assertSame(
			[
				['listThreads', 'daan', true],
				['listMessages', 't1', 'daan', true],
				['postMessage', 't1', 'daan', true, 'Hallo'],
			],
			$messaging->calls
		);
	}//end testCallsReachPortaliqAsStaff()

	/**
	 * A thread the staff member takes no part in answers null; a refused post false.
	 *
	 * @return void
	 */
	public function testRefusalsPassThrough(): void {
		$adapter = $this->adapter(messaging: $this->messaging(messages: null, posted: false));

		$this->assertNull($adapter->messages(threadId: 't9', staffRef: 'daan'));
		$this->assertFalse($adapter->post(threadId: 't9', staffRef: 'daan', body: 'Hallo'));
	}//end testRefusalsPassThrough()

	/**
	 * Without portaliq nothing is reached.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqNothingAnswers(): void {
		$this->container->expects($this->never())->method('get');
		$adapter = $this->adapter(messaging: $this->messaging(), installed: false);

		$this->assertNull($adapter->threads(staffRef: 'daan'));
		$this->assertNull($adapter->messages(threadId: 't1', staffRef: 'daan'));
		$this->assertFalse($adapter->post(threadId: 't1', staffRef: 'daan', body: 'Hallo'));
	}//end testWithoutPortaliqNothingAnswers()

	/**
	 * An unresolvable or incomplete service answers nothing.
	 *
	 * @return void
	 */
	public function testAnUnusableServiceAnswersNothing(): void {
		$this->assertNull($this->adapter(messaging: null)->threads(staffRef: 'daan'));

		$this->setUp();
		$this->assertNull($this->adapter(messaging: new \stdClass())->threads(staffRef: 'daan'));
	}//end testAnUnusableServiceAnswersNothing()

	/**
	 * A failing service is logged and answers nothing.
	 *
	 * @return void
	 */
	public function testAFailingServiceAnswersNothing(): void {
		$failing = new class {
			/** @return array<int, mixed> */
			public function listThreads(string $subjectRef, bool $isStaff): array {
				throw new RuntimeException('store down');
			}

			/** @return array<int, mixed>|null */
			public function listMessages(string $threadId, string $subjectRef, bool $isStaff): ?array {
				throw new RuntimeException('store down');
			}

			public function postMessage(string $threadId, string $senderRef, bool $senderIsStaff, string $body): bool {
				throw new RuntimeException('store down');
			}
		};
		$adapter = $this->adapter(messaging: $failing);

		$this->assertNull($adapter->threads(staffRef: 'daan'));
		$this->assertNull($adapter->messages(threadId: 't1', staffRef: 'daan'));
		$this->assertFalse($adapter->post(threadId: 't1', staffRef: 'daan', body: 'Hallo'));
	}//end testAFailingServiceAnswersNothing()
}//end class
