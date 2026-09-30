<?php

/**
 * ConferenceSignupPortalStamp test.
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
 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\ConferenceSignupPortalStamp;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A guardian's portal booking is checked against the child and the round,
 * and stamped so the scheduling generator considers it.
 */
class ConferenceSignupPortalStampTest extends TestCase {

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	/** @var array<string, array<string, mixed>> */
	private array $profiles = [];

	/** @var array<string, array<string, mixed>> */
	private array $objects = [];

	/**
	 * A family, a group and a round open for booking.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->profiles = [
			'child-1' => ['id' => 'child-1', 'ncUserId' => 'po-leerling-147', 'guardianRefs' => ['guardian-1']],
			'guardian-1' => ['id' => 'guardian-1', 'ncUserId' => 'po-ouder-009'],
		];
		$this->objects = [
			'round-1' => [
				'id' => 'round-1',
				'lifecycle' => 'booking-open',
				'invitedLearnerRefs' => ['child-1'],
				'teacherIds' => ['po-leerkracht-09', 'po-vakleerkracht-01'],
				'cohortIds' => ['cohort-7'],
				'tenant_id' => self::TENANT,
			],
			'cohort-7' => ['id' => 'cohort-7', 'learnerIds' => ['po-leerling-147'], 'teacherIds' => ['po-leerkracht-09']],
		];
	}//end setUp()

	/**
	 * A guardian's booking for their own child in an open round is stamped
	 * with the child, the guardian, the tenant and `submitted`, and asks for
	 * the child's own group teacher when the guardian names nobody.
	 *
	 * @return void
	 */
	public function testAGuardiansBookingIsStampedForTheScheduler(): void {
		$event = $this->portalCreate(['learnerRef' => 'child-1', 'guardianRef' => 'guardian-1', 'conferenceRoundId' => 'round-1']);

		$this->stamp()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		$this->assertSame('po-leerling-147', $data['learnerId']);
		$this->assertSame('po-ouder-009', $data['guardianId']);
		$this->assertSame(self::TENANT, $data['tenant_id']);
		$this->assertSame('submitted', $data['lifecycle']);
		$this->assertSame(['po-leerkracht-09'], $data['requestedTeacherIds']);
	}//end testAGuardiansBookingIsStampedForTheScheduler()

	/**
	 * A requested teacher the round does not offer is dropped.
	 *
	 * @return void
	 */
	public function testOnlyTeachersTheRoundOffersAreRequested(): void {
		$event = $this->portalCreate(['learnerRef' => 'child-1', 'guardianRef' => 'guardian-1', 'conferenceRoundId' => 'round-1', 'requestedTeacherIds' => ['po-vakleerkracht-01', 'someone-else']]);

		$this->stamp()->handle($event);

		$this->assertSame(['po-vakleerkracht-01'], $event->getModifiedData()['requestedTeacherIds']);
	}//end testOnlyTeachersTheRoundOffersAreRequested()

	/**
	 * Someone else's child, a closed round, or a round the child is not
	 * invited to is refused.
	 *
	 * @return void
	 */
	public function testABookingOutsideTheGuardiansChildrenOrOpenRoundsIsRefused(): void {
		$this->profiles['child-2'] = ['id' => 'child-2', 'ncUserId' => 'po-leerling-143', 'guardianRefs' => ['guardian-2']];
		$foreign = $this->portalCreate(['learnerRef' => 'child-2', 'guardianRef' => 'guardian-1', 'conferenceRoundId' => 'round-1']);
		$this->stamp()->handle($foreign);
		$this->assertTrue($foreign->isPropagationStopped());
		$this->assertSame('signup-guardian-unknown', $foreign->getErrors()['reason']);

		$this->objects['round-1']['lifecycle'] = 'booking-closed';
		$closed = $this->portalCreate(['learnerRef' => 'child-1', 'guardianRef' => 'guardian-1', 'conferenceRoundId' => 'round-1']);
		$this->stamp()->handle($closed);
		$this->assertSame('signup-round-closed', $closed->getErrors()['reason']);

		$this->objects['round-1']['lifecycle'] = 'booking-open';
		$this->objects['round-1']['invitedLearnerRefs'] = ['someone'];
		$uninvited = $this->portalCreate(['learnerRef' => 'child-1', 'guardianRef' => 'guardian-1', 'conferenceRoundId' => 'round-1']);
		$this->stamp()->handle($uninvited);
		$this->assertSame('signup-round-closed', $uninvited->getErrors()['reason']);
	}//end testABookingOutsideTheGuardiansChildrenOrOpenRoundsIsRefused()

	/**
	 * A signed-in write (the Nextcloud booking view) is left to the guard.
	 *
	 * @return void
	 */
	public function testASignedInWriteIsNotTouched(): void {
		$event = $this->portalCreate(['learnerRef' => 'child-1', 'guardianRef' => 'guardian-1', 'conferenceRoundId' => 'round-1']);

		$this->stamp(signedIn: true)->handle($event);

		$this->assertSame([], $event->getModifiedData());
		$this->assertFalse($event->isPropagationStopped());
	}//end testASignedInWriteIsNotTouched()

	/**
	 * The listener is wired for creates, asserted from the registrar.
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

		(new IntegrityListenerRegistrar())->register($context);

		$this->assertContains([ObjectCreatingEvent::class, ConferenceSignupPortalStamp::class], $wired);
	}//end testTheRegistrarWiresTheListener()

	/**
	 * A portal create event for a signup.
	 *
	 * @param array<string, mixed> $data The signup body.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function portalCreate(array $data): ObjectCreatingEvent {
		return new ObjectCreatingEvent(OrEntityFactory::make($data, 'conference-signup'));
	}//end portalCreate()

	/**
	 * The listener wired to fakes.
	 *
	 * @param bool $signedIn Whether a Nextcloud user is signed in.
	 *
	 * @return ConferenceSignupPortalStamp
	 */
	private function stamp(bool $signedIn=false): ConferenceSignupPortalStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('conference-signup');

		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('byRef')->willReturnCallback(fn (string $ref): ?array => ($this->profiles[$ref] ?? null));

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			fn (string $id) => isset($this->objects[$id]) === true ? OrEntityFactory::make($this->objects[$id], 'conference-round') : null
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new ConferenceSignupPortalStamp($resolver, $profiles, $objectService, $session, new NullLogger());
	}//end stamp()
}//end class
