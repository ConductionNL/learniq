<?php

/**
 * Scholiq SessionConflictListener unit tests.
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Learniq\Listener\SessionConflictListener;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\TimetableConflictDetector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SessionConflictListener::handle().
 */
class SessionConflictListenerTest extends TestCase {

	/**
	 * @var TimetableConflictDetector&MockObject
	 */
	private TimetableConflictDetector&MockObject $detector;

	/**
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $schemaResolver;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->detector = $this->createMock(TimetableConflictDetector::class);
		$this->schemaResolver = $this->createMock(ListenerSchemaResolver::class);

	}//end setUp()

	/**
	 * Stub the resolver the way OpenRegister behaves in production: the entity
	 * carries numeric ids and the resolver turns them into slugs.
	 *
	 * @param string $schemaSlug The slug the resolver resolves the schema id to.
	 *
	 * @return void
	 */
	private function stubResolver(string $schemaSlug): void {
		$this->schemaResolver->method('registerSlug')->willReturn('learniq');
		$this->schemaResolver->method('schemaSlug')->willReturn($schemaSlug);

	}//end stubResolver()

	/**
	 * A created Session invokes the detector with its data.
	 *
	 * @return void
	 */
	public function testCreatedSessionInvokesDetector(): void {
		$objectEntity = OrEntityFactory::make(['id' => 'session-1'], '1280', '9');
		$this->stubResolver('session');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		$this->detector->expects(self::once())->method('scan')->with(
			self::callback(
				static fn (array $rows): bool => count($rows) === 1 && ($rows[0]['id'] ?? null) === 'session-1'
			)
		);

		(new SessionConflictListener($this->detector, $this->schemaResolver))->handle($event);

	}//end testCreatedSessionInvokesDetector()

	/**
	 * An updated Session invokes the detector with its data.
	 *
	 * @return void
	 */
	public function testUpdatedSessionInvokesDetector(): void {
		$objectEntity = OrEntityFactory::make(['id' => 'session-2'], '1280', '9');
		$this->stubResolver('session');

		$event = $this->createMock(ObjectUpdatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		$this->detector->expects(self::once())->method('scan')->with(
			self::callback(
				static fn (array $rows): bool => count($rows) === 1 && ($rows[0]['id'] ?? null) === 'session-2'
			)
		);

		(new SessionConflictListener($this->detector, $this->schemaResolver))->handle($event);

	}//end testUpdatedSessionInvokesDetector()

	/**
	 * A different schema is ignored.
	 *
	 * @return void
	 */
	public function testDifferentSchemaIsIgnored(): void {
		$objectEntity = OrEntityFactory::make(['id' => 'cohort-1'], '1281', '9');
		$this->stubResolver('cohort');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		$this->detector->expects(self::never())->method('scan');

		(new SessionConflictListener($this->detector, $this->schemaResolver))->handle($event);

	}//end testDifferentSchemaIsIgnored()
}//end class
