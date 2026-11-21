<?php

/**
 * Tests that the boot and coverage registrars hand their register and schema filters to OpenRegister.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\AppInfo
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

namespace OCA\Learniq\Tests\Unit\AppInfo;

use OCA\Learniq\Listener\Registrar\BootListenerRegistrar;
use OCA\Learniq\Listener\Registrar\CoverageListenerRegistrar;
use OCA\Learniq\Listener\AttendanceSummaryListener;
use OCA\Learniq\Listener\CurriculumCoverageRollupHandler;
use OCA\Learniq\Listener\SessionConflictListener;
use OCA\Learniq\Listener\ShillinqContributionSettledListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Each filtered listener reaches OpenRegister with its own registers and schemas.
 *
 * @covers \OCA\Learniq\Listener\Registrar\BootListenerRegistrar
 * @covers \OCA\Learniq\Listener\Registrar\CoverageListenerRegistrar
 */
class FilteredSubscriptionTest extends TestCase {

	/**
	 * Start every case without declarations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ObjectEventSubscription::reset();
	}//end setUp()

	/**
	 * The filters declared for one listener on one event.
	 *
	 * @param string $event    Event class name.
	 * @param string $listener Listener class name.
	 *
	 * @return array<int, array{0: mixed, 1: mixed}>
	 */
	private function filtersOf(string $event, string $listener): array {
		$found = [];
		foreach (ObjectEventSubscription::subscriptionsFor($event) as $row) {
			if ($row['listener'] === $listener) {
				$found[] = [$row['registers'], $row['schemas']];
			}
		}

		return $found;
	}//end filtersOf()

	/**
	 * The boot tables, the payment listener and the attendance listener keep their own filters.
	 *
	 * @return void
	 */
	public function testBootListenersDeclareTheirFilters(): void {
		(new BootListenerRegistrar())->register($this->createMock(IEventDispatcher::class), 'learniq');

		self::assertSame([[['shillinq'], ['PaymentRequest']]], $this->filtersOf(ObjectUpdatedEvent::class, ShillinqContributionSettledListener::class));
		self::assertSame([[['learniq'], ['session']]], $this->filtersOf(ObjectCreatedEvent::class, SessionConflictListener::class));
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			self::assertSame([[['learniq'], ['attendance-record']]], $this->filtersOf($event, AttendanceSummaryListener::class), $event);
		}
	}//end testBootListenersDeclareTheirFilters()

	/**
	 * The coverage handler is declared on create, update and delete of the coverage schemas.
	 *
	 * @return void
	 */
	public function testCoverageHandlerDeclaresItsSchemas(): void {
		(new CoverageListenerRegistrar())->register($this->createMock(IEventDispatcher::class), 'learniq');

		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			self::assertSame([[['learniq'], CoverageListenerRegistrar::SCHEMAS]], $this->filtersOf($event, CurriculumCoverageRollupHandler::class), $event);
		}
	}//end testCoverageHandlerDeclaresItsSchemas()
}//end class
