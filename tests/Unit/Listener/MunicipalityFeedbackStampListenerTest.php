<?php

/**
 * Learniq MunicipalityFeedbackStampListener unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Listener\MunicipalityFeedbackStampListener;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * DataExchangeJob.recordMunicipalityFeedback is a self-loop (succeeded to
 * succeeded). OpenRegister runs neither guards nor actions on a self-loop, so
 * the recordedBy/receivedAt stamp MunicipalityFeedbackGuard used to write into
 * the payload now lands through an ObjectTransitionedEvent listener, which
 * TransitionEngine dispatches after the save for every transition (learniq#983).
 */
class MunicipalityFeedbackStampListenerTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var list<array{object: array<string,mixed>, register: mixed, schema: mixed, uuid: mixed}>
	 */
	private array $saved = [];

	/**
	 * Reset the capture buffer before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
	}//end setUp()

	/**
	 * Build the listener over a resolver answering the given schema slug.
	 *
	 * @param string $schemaSlug The slug the resolver answers for the event's object.
	 *
	 * @return MunicipalityFeedbackStampListener
	 */
	private function makeListener(string $schemaSlug = 'data-exchange-job'): MunicipalityFeedbackStampListener {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, $uuid = null): ObjectEntity {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return OrEntityFactory::make($object, 'data-exchange-job');
			}
		);

		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schemaSlug);

		return new MunicipalityFeedbackStampListener($objectService, $resolver);
	}//end makeListener()

	/**
	 * A transitioned event for the saved job.
	 *
	 * @param array<string,mixed> $feedback The municipalityFeedback the caller sent.
	 * @param string              $action   The transition action.
	 * @param string|null         $userId   The acting user.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(array $feedback, string $action = 'recordMunicipalityFeedback', ?string $userId = 'actor-1'): ObjectTransitionedEvent {
		$entity = OrEntityFactory::make(
			[
				'target' => 'leerplicht',
				'lifecycle' => 'succeeded',
				'municipalityFeedback' => $feedback,
			],
			'7',
			'3',
			'job-1'
		);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getAction')->willReturn($action);
		$event->method('getFrom')->willReturn('succeeded');
		$event->method('getTo')->willReturn('succeeded');
		$event->method('getUserId')->willReturn($userId);

		return $event;
	}//end event()

	/**
	 * recordedBy is the acting user and receivedAt is stamped when missing, and
	 * both are saved onto the job with the caller's own feedback kept.
	 *
	 * @return void
	 */
	public function testRecorderAndReceivedAtAreSavedOntoTheJob(): void {
		$this->makeListener()->handle($this->event(['masRoute' => 'jeugdhulp', 'recordedBy' => 'someone-else']));

		self::assertCount(1, $this->saved);
		$saved = $this->saved[0];
		self::assertSame('job-1', $saved['uuid']);
		self::assertSame('data-exchange-job', $saved['schema']);

		$feedback = $saved['object']['municipalityFeedback'];
		self::assertSame('actor-1', $feedback['recordedBy']);
		self::assertSame('jeugdhulp', $feedback['masRoute']);
		self::assertNotFalse(DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $feedback['receivedAt']));
		self::assertSame('succeeded', $saved['object']['lifecycle']);
	}//end testRecorderAndReceivedAtAreSavedOntoTheJob()

	/**
	 * A caller-supplied receivedAt is kept.
	 *
	 * @return void
	 */
	public function testCallerSuppliedReceivedAtIsPreserved(): void {
		$this->makeListener()->handle($this->event(['receivedAt' => '2026-01-01T00:00:00+00:00']));

		self::assertSame('2026-01-01T00:00:00+00:00', $this->saved[0]['object']['municipalityFeedback']['receivedAt']);
	}//end testCallerSuppliedReceivedAtIsPreserved()

	/**
	 * Other transitions, other schemas and other events are left alone.
	 *
	 * @return void
	 */
	public function testIgnoresEverythingElse(): void {
		$this->makeListener()->handle($this->event([], 'markSucceeded'));
		$this->makeListener('exchange-rejection')->handle($this->event([]));
		$this->makeListener()->handle(new Event());

		self::assertSame([], $this->saved);
	}//end testIgnoresEverythingElse()

	/**
	 * Without an acting user the listener throws rather than save an unattributed record.
	 *
	 * @return void
	 */
	public function testNoActingUserThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->makeListener()->handle($this->event([], userId: null));
	}//end testNoActingUserThrows()
}//end class
