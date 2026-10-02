<?php

/**
 * ConferenceRoundBookingModeStamp test.
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
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\CollaborationListenerRegistrar;
use OCA\Learniq\Listener\ConferenceRoundBookingModeStamp;
use OCA\Learniq\Service\ConferenceBookingMode;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\SegmentService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A new round books directly in a primary school and by preference
 * elsewhere; an old round without a mode keeps the preference flow.
 */
class ConferenceRoundBookingModeStampTest extends TestCase {

	/**
	 * A primary school's new round books directly.
	 *
	 * @return void
	 */
	public function testAPrimarySchoolRoundBooksDirectly(): void {
		$event = $this->create([]);
		$this->stamp(segment: 'po')->handle($event);
		$this->assertSame('direct', $event->getModifiedData()['bookingMode']);
	}//end testAPrimarySchoolRoundBooksDirectly()

	/**
	 * A secondary school's new round books by preference.
	 *
	 * @return void
	 */
	public function testASecondarySchoolRoundBooksByPreference(): void {
		$event = $this->create([]);
		$this->stamp(segment: 'vo')->handle($event);
		$this->assertSame('preference', $event->getModifiedData()['bookingMode']);
	}//end testASecondarySchoolRoundBooksByPreference()

	/**
	 * A mode the creator chose is kept.
	 *
	 * @return void
	 */
	public function testAChosenModeIsKept(): void {
		$event = $this->create(['bookingMode' => 'preference']);
		$this->stamp(segment: 'po')->handle($event);
		$this->assertSame([], $event->getModifiedData());
	}//end testAChosenModeIsKept()

	/**
	 * A round stored before the mode existed reads as preference.
	 *
	 * @return void
	 */
	public function testAnOldRoundWithoutAModeKeepsThePreferenceFlow(): void {
		$this->assertSame('preference', (new ConferenceBookingMode())->modeOf(['name' => 'Oudergesprekken 2025']));
		$this->assertFalse((new ConferenceBookingMode())->isDirect(['bookingMode' => 'something-else']));
		$this->assertSame(1, (new ConferenceBookingMode())->maxBookingsPerChild([]));
	}//end testAnOldRoundWithoutAModeKeepsThePreferenceFlow()

	/**
	 * The listener is wired for creates.
	 *
	 * @return void
	 */
	public function testTheRegistrarWiresTheListener(): void {
		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = [$event, $listener];
			}
		);

		(new CollaborationListenerRegistrar())->register($context);

		$this->assertContains([ObjectCreatingEvent::class, ConferenceRoundBookingModeStamp::class], $wired);
	}//end testTheRegistrarWiresTheListener()

	/**
	 * A round create event.
	 *
	 * @param array<string, mixed> $data The round body.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(array $data): ObjectCreatingEvent {
		return new ObjectCreatingEvent(OrEntityFactory::make(array_merge(['name' => 'Oudergesprekken'], $data), 'conference-round'));
	}//end create()

	/**
	 * The listener on a given segment.
	 *
	 * @param string $segment The instance segment.
	 *
	 * @return ConferenceRoundBookingModeStamp
	 */
	private function stamp(string $segment): ConferenceRoundBookingModeStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('conference-round');
		$segments = $this->createMock(SegmentService::class);
		$segments->method('currentSegment')->willReturn($segment);

		return new ConferenceRoundBookingModeStamp($resolver, $segments, new NullLogger());
	}//end stamp()
}//end class
