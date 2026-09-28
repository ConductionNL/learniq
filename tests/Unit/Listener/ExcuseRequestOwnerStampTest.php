<?php

/**
 * Learniq ExcuseRequestOwnerStamp unit tests.
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
 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\ExcuseRequestOwnerStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the server-side owner stamp on ExcuseRequest writes.
 */
class ExcuseRequestOwnerStampTest extends TestCase {

	private const TENANT = '11111111-1111-4111-8111-111111111111';

	/**
	 * Active LearnerProfiles by uuid, as LearnerRefResolver::byRef() returns them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $profiles = [];

	/**
	 * LearnerProfile uuid by Nextcloud user id.
	 *
	 * @var array<string, string>
	 */
	private array $refsByUser = [];

	/**
	 * Build the stamp over in-memory profiles.
	 *
	 * @param string $schemaSlug The slug the resolver reports.
	 * @param bool $hasUser Whether a Nextcloud session is present.
	 * @param bool $lookupThrows Whether every lookup fails.
	 *
	 * @return ExcuseRequestOwnerStamp
	 */
	private function makeStamp(string $schemaSlug = 'excuse-request', bool $hasUser = false, bool $lookupThrows = false): ExcuseRequestOwnerStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schemaSlug);

		$lookup = $this->createMock(LearnerRefResolver::class);
		$lookup->method('byRef')->willReturnCallback(
			function (string $learnerRef) use ($lookupThrows): ?array {
				if ($lookupThrows === true) {
					throw new RuntimeException('database gone');
				}

				return ($this->profiles[$learnerRef] ?? null);
			}
		);
		// A portal write has no session: only the across-tenants lookup answers.
		$lookup->method('resolveAcrossTenants')->willReturnCallback(
			function (string $ncUserId) use ($lookupThrows): ?string {
				if ($lookupThrows === true) {
					throw new RuntimeException('database gone');
				}

				return ($this->refsByUser[$ncUserId] ?? null);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mentor-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($hasUser === true ? $user : null);

		return new ExcuseRequestOwnerStamp(
			schemaResolver: $resolver,
			profiles: $lookup,
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * Seed a pupil with one guardian who has an account and one who has none.
	 *
	 * @return void
	 */
	private function seedFamily(): void {
		$this->profiles['lp-1'] = [
			'id' => 'lp-1',
			'ncUserId' => 'pupil-1',
			'tenant_id' => self::TENANT,
			'lifecycle' => 'active',
			'guardianRefs' => ['gp-1', 'gp-2'],
		];
		$this->profiles['gp-1'] = ['id' => 'gp-1', 'ncUserId' => 'ouder-1', 'tenant_id' => self::TENANT, 'lifecycle' => 'active'];
		$this->refsByUser['pupil-1'] = 'lp-1';
		$this->refsByUser['pupil-2'] = 'lp-2';
	}//end seedFamily()

	/**
	 * The body portaliq writes for a portal absence report.
	 *
	 * @param array<string, mixed> $extra Fields beyond the whitelisted four.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function portalCreate(array $extra = []): ObjectCreatingEvent {
		return new ObjectCreatingEvent(
			OrEntityFactory::make(
				array_merge(
					[
						'dateFrom' => '2026-09-28',
						'dateTo' => '2026-09-28',
						'reason' => 'Koorts',
						'reasonKind' => 'illness',
					],
					$extra
				),
				'excuse-request'
			)
		);
	}//end portalCreate()

	/**
	 * A pupil's own report gets the pupil, the pupil as submitter, the
	 * school and the portal's assurance level from the profile.
	 *
	 * @return void
	 */
	public function testAPupilReportIsStampedFromTheProfile(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1']);

		$this->makeStamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame('pupil-1', $data['learnerId']);
		self::assertSame('lp-1', $data['learnerRef']);
		self::assertSame('pupil-1', $data['submittedBy']);
		self::assertSame('lp-1', $data['submittedByRef']);
		self::assertSame('basic', $data['submittedAuthLevel']);
		self::assertSame(self::TENANT, $data['tenant_id']);
	}//end testAPupilReportIsStampedFromTheProfile()

	/**
	 * A guardian's report names the child and the guardian, with the
	 * guardian's user id and the level the guardian action requires.
	 *
	 * @return void
	 */
	public function testAGuardianReportNamesTheChildAndTheGuardian(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'submittedByRef' => 'gp-1']);

		$this->makeStamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame('pupil-1', $data['learnerId']);
		self::assertSame('ouder-1', $data['submittedBy']);
		self::assertArrayNotHasKey('submittedByRef', $data);
		self::assertSame('substantial', $data['submittedAuthLevel']);
		self::assertSame(self::TENANT, $data['tenant_id']);
	}//end testAGuardianReportNamesTheChildAndTheGuardian()

	/**
	 * A guardian without a Nextcloud account is named by submittedByRef alone,
	 * and the report is let through.
	 *
	 * @return void
	 */
	public function testAGuardianWithoutAnAccountIsNamedByReference(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'submittedByRef' => 'gp-2']);

		$this->makeStamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertNull($event->getModifiedData()['submittedBy']);
	}//end testAGuardianWithoutAnAccountIsNamedByReference()

	/**
	 * A guardian cannot report an absence for a child that does not list them.
	 *
	 * @return void
	 */
	public function testAGuardianOfAnotherChildIsRefused(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'submittedByRef' => 'gp-9']);

		$this->makeStamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('excuse-guardian-unknown', $event->getErrors()['reason']);
		self::assertSame([], $event->getModifiedData());
	}//end testAGuardianOfAnotherChildIsRefused()

	/**
	 * A learnerRef naming no active profile refuses the report, so no excuse
	 * without a pupil is written.
	 *
	 * @return void
	 */
	public function testAReportForAnUnknownPupilIsRefused(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-9']);

		$this->makeStamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('excuse-learner-unknown', $event->getErrors()['reason']);
	}//end testAReportForAnUnknownPupilIsRefused()

	/**
	 * A lookup that fails refuses the portal report: fail closed.
	 *
	 * @return void
	 */
	public function testAFailedLookupRefusesThePortalReport(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1']);

		$this->makeStamp(lookupThrows: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('excuse-lookup-failed', $event->getErrors()['reason']);
	}//end testAFailedLookupRefusesThePortalReport()

	/**
	 * A signed-in caller never takes the portal path: a create with only a
	 * learnerRef and no pupil is refused, so nobody reports in another pupil's
	 * name by sending a uuid.
	 *
	 * @return void
	 */
	public function testASignedInCreateWithoutAPupilIsRefused(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'tenant_id' => self::TENANT]);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('excuse-owner-missing', $event->getErrors()['reason']);
	}//end testASignedInCreateWithoutAPupilIsRefused()

	/**
	 * A staff create keeps its fields, gets learnerRef from learnerId (a
	 * forged value is replaced) and the default assurance level.
	 *
	 * @return void
	 */
	public function testAStaffCreateDerivesTheReferenceAndTheLevel(): void {
		$this->seedFamily();
		$event = $this->portalCreate(
			extra: [
				'learnerId' => 'pupil-1',
				'learnerRef' => 'lp-2',
				'submittedBy' => 'mentor-1',
				'tenant_id' => self::TENANT,
			]
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame('lp-1', $data['learnerRef']);
		self::assertSame('basic', $data['submittedAuthLevel']);
		self::assertArrayNotHasKey('learnerId', $data);
	}//end testAStaffCreateDerivesTheReferenceAndTheLevel()

	/**
	 * A staff create keeps an assurance level it sends.
	 *
	 * @return void
	 */
	public function testAStaffCreateKeepsItsLevel(): void {
		$this->seedFamily();
		$event = $this->portalCreate(
			extra: [
				'learnerId' => 'pupil-1',
				'submittedBy' => 'mentor-1',
				'submittedAuthLevel' => 'high',
				'tenant_id' => self::TENANT,
			]
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertArrayNotHasKey('submittedAuthLevel', $event->getModifiedData());
	}//end testAStaffCreateKeepsItsLevel()

	/**
	 * Staff are held to the rule the schema's required list gave: no pupil, no
	 * submitter or no school refuses the write.
	 *
	 * @return void
	 */
	public function testAStaffCreateWithoutItsOwnerFieldsIsRefused(): void {
		$this->seedFamily();
		foreach (['learnerId', 'submittedBy', 'tenant_id'] as $missing) {
			$fields = ['learnerId' => 'pupil-1', 'submittedBy' => 'mentor-1', 'tenant_id' => self::TENANT];
			unset($fields[$missing]);
			$event = $this->portalCreate(extra: $fields);

			$this->makeStamp(hasUser: true)->handle($event);

			self::assertTrue($event->isPropagationStopped(), $missing);
			self::assertSame('excuse-owner-missing', $event->getErrors()['reason'], $missing);
		}
	}//end testAStaffCreateWithoutItsOwnerFieldsIsRefused()

	/**
	 * An update whose pupil did not change keeps its stored learnerRef when the
	 * lookup fails.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsTheStoredReferenceWhenTheLookupFails(): void {
		$stored = ['learnerId' => 'pupil-1', 'learnerRef' => 'lp-1', 'submittedBy' => 'mentor-1', 'submittedAuthLevel' => 'basic', 'tenant_id' => self::TENANT, 'lifecycle' => 'submitted'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['lifecycle' => 'approved']), 'excuse-request'),
			OrEntityFactory::make($stored, 'excuse-request')
		);

		$this->makeStamp(hasUser: true, lookupThrows: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
	}//end testAnUpdateKeepsTheStoredReferenceWhenTheLookupFails()

	/**
	 * Another schema's writes are never touched.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1']);

		$this->makeStamp(schemaSlug: 'submission')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * The stamp is wired on create and update, so the rule holds for both.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredOnCreateAndUpdate(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . ExcuseRequestOwnerStamp::class, $pairs);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . ExcuseRequestOwnerStamp::class, $pairs);
	}//end testTheStampIsRegisteredOnCreateAndUpdate()
}//end class
