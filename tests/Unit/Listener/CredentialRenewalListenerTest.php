<?php

/**
 * Learniq CredentialRenewalListener unit tests.
 *
 * Covers: Credential expire -> renewal Enrolment created (learnerId/courseId
 * copied, source: credential-renewal, mandatory: true), renewalEnrolmentId
 * linked back, missing-field skip, and ignoring unrelated events/transitions.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Listener\CredentialRenewalListener;
use OCA\Learniq\Service\CredentialLearner;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for CredentialRenewalListener::handle() on Credential -> expired.
 */
class CredentialRenewalListenerTest extends TestCase {

	/**
	 * The learner's LearnerProfile uuid, what Credential.learnerId holds.
	 */
	private const PROFILE = '5a1d3c2b-7e6f-4a8b-9c0d-1e2f3a4b5c6d';

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Reset capture buffers before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];

	}//end setUp()

	/**
	 * Build a listener with a stubbed ObjectService.
	 *
	 * @param array<string,mixed>|ObjectEntity $savedEnrolment What ObjectService::saveObject() returns for
	 *                                                          the enrolment save.
	 *
	 * @return CredentialRenewalListener
	 */
	private function makeListener(array|ObjectEntity $savedEnrolment): CredentialRenewalListener {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null) use ($savedEnrolment): ObjectEntity {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $data,
				];
				if ((string)$schema === 'enrolment') {
					if ($savedEnrolment instanceof ObjectEntity) {
						return $savedEnrolment;
					}
					return OrEntityFactory::make($savedEnrolment, 'enrolment');
				}
				return OrEntityFactory::make($data, (string)$schema, (string)$register);
			}
		);

		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('byRef')->willReturnCallback(
			static fn (string $learnerRef): ?array => $learnerRef === self::PROFILE ? ['id' => self::PROFILE, 'ncUserId' => 'learner-1'] : null
		);

		return new CredentialRenewalListener($objectService, new NullLogger(), new CredentialLearner(profiles: $profiles), \OCA\Learniq\Tests\Support\TransitionScope::resolver());

	}//end makeListener()

	/**
	 * Build a mocked ObjectTransitionedEvent for a Credential -> expired transition.
	 *
	 * @param array<string, mixed> $credentialData The Credential's jsonSerialize() payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(array $credentialData): ObjectTransitionedEvent {
		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($credentialData);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('credential');
		$event->method('getAction')->willReturn('expire');

		return $event;

	}//end makeEvent()

	/**
	 * An expired Credential creates a renewal Enrolment (source:
	 * credential-renewal, mandatory: true, learnerId/courseId/regulationSlug
	 * copied) and links renewalEnrolmentId back onto the Credential.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-auto-enrol-on-credential-expiry
	 */
	public function testExpiredCredentialCreatesAndLinksRenewalEnrolment(): void {
		$listener = $this->makeListener(savedEnrolment: ['id' => 'enrol-1']);

		$credential = [
			'id' => 'cred-1',
			'learnerId' => self::PROFILE,
			'learnerUserId' => 'learner-1',
			'courseId' => 'course-1',
			'regulationSlug' => 'avg-2018',
			'tenant_id' => 'tenant-a',
			'lifecycle' => 'expired',
		];

		$listener->handle($this->makeEvent($credential));

		$enrolmentSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'enrolment'));
		self::assertCount(1, $enrolmentSaves);
		self::assertSame('credential-renewal', $enrolmentSaves[0]['object']['source']);
		self::assertTrue($enrolmentSaves[0]['object']['mandatory']);
		self::assertSame('learner-1', $enrolmentSaves[0]['object']['learnerId'], 'Enrolment.learnerId is the user id, not the profile uuid.');
		self::assertSame(self::PROFILE, $enrolmentSaves[0]['object']['learnerRef']);
		self::assertSame('course-1', $enrolmentSaves[0]['object']['courseId']);
		self::assertSame('avg-2018', $enrolmentSaves[0]['object']['regulationSlug']);

		$credentialSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'credential'));
		self::assertCount(1, $credentialSaves);
		self::assertSame('enrol-1', $credentialSaves[0]['object']['renewalEnrolmentId']);

	}//end testExpiredCredentialCreatesAndLinksRenewalEnrolment()

	/**
	 * A row written before learnerUserId existed renews for the user its
	 * profile names.
	 *
	 * @return void
	 */
	public function testALegacyCredentialRenewsForTheProfilesUser(): void {
		$listener = $this->makeListener(savedEnrolment: ['id' => 'enrol-3']);

		$listener->handle($this->makeEvent(['id' => 'cred-3', 'learnerId' => self::PROFILE, 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']));

		$enrolmentSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'enrolment'));
		self::assertCount(1, $enrolmentSaves);
		self::assertSame('learner-1', $enrolmentSaves[0]['object']['learnerId']);
	}//end testALegacyCredentialRenewsForTheProfilesUser()

	/**
	 * A Credential missing learnerId/courseId/tenant_id is skipped — no
	 * Enrolment created.
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldsSkips(): void {
		$listener = $this->makeListener(savedEnrolment: ['id' => 'enrol-2']);

		$credential = ['id' => 'cred-2', 'lifecycle' => 'expired'];

		$listener->handle($this->makeEvent($credential));

		self::assertCount(0, $this->savedObjects);

	}//end testMissingRequiredFieldsSkips()

	/**
	 * A non-Credential event (wrong schema) is ignored.
	 *
	 * @return void
	 */
	public function testWrongSchemaIgnored(): void {
		$listener = $this->makeListener(savedEnrolment: ['id' => 'enrol-3']);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('attestation');
		$event->method('getAction')->willReturn('expire');

		$listener->handle($event);

		self::assertCount(0, $this->savedObjects);

	}//end testWrongSchemaIgnored()

	/**
	 * A transition action other than `expire` is ignored.
	 *
	 * @return void
	 */
	public function testWrongActionIgnored(): void {
		$listener = $this->makeListener(savedEnrolment: ['id' => 'enrol-4']);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('credential');
		$event->method('getAction')->willReturn('revoke');

		$listener->handle($event);

		self::assertCount(0, $this->savedObjects);

	}//end testWrongActionIgnored()

	/**
	 * A non-ObjectTransitionedEvent is ignored.
	 *
	 * @return void
	 */
	public function testNonMatchingEventTypeIgnored(): void {
		$listener = $this->makeListener(savedEnrolment: ['id' => 'enrol-5']);

		$listener->handle($this->createMock(Event::class));

		self::assertCount(0, $this->savedObjects);

	}//end testNonMatchingEventTypeIgnored()
}//end class
