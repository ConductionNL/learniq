<?php

/**
 * Unit tests for CredentialRenewalListener's skips: a learner without a
 * Nextcloud user, and a renewal Enrolment saved without an id.
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

use OCA\Learniq\Listener\CredentialRenewalListener;
use OCA\Learniq\Service\CredentialLearner;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Learniq\Listener\CredentialRenewalListener
 * @uses   \OCA\Learniq\Service\CredentialLearner
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 * @uses   \OCA\Learniq\Service\ListenerSlugContract
 */
class CredentialRenewalSkipsTest extends TestCase {

	private const PROFILE = '5a1d3c2b-7e6f-4a8b-9c0d-1e2f3a4b5c6d';

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var LearnerRefResolver&MockObject */
	private LearnerRefResolver $profiles;

	private CredentialRenewalListener $listener;

	/**
	 * Build the listener over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->profiles = $this->createMock(LearnerRefResolver::class);
		$this->listener = new CredentialRenewalListener(
			$this->objectService,
			$this->logger,
			new CredentialLearner(profiles: $this->profiles),
			TransitionScope::resolver()
		);
	}//end setUp()

	/**
	 * A Credential -> expire event.
	 *
	 * @param array<string, mixed> $credential The credential data.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function expired(array $credential): ObjectTransitionedEvent {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($credential);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('credential');
		$event->method('getAction')->willReturn('expire');
		$event->method('getObject')->willReturn($object);
		return $event;
	}//end expired()

	/**
	 * A learner profile without a Nextcloud user enrols nothing and says why.
	 *
	 * @return void
	 */
	public function testALearnerWithoutAUserIsNotEnrolled(): void {
		$this->profiles->expects(self::once())->method('byRef')->with(self::PROFILE)->willReturn(['id' => self::PROFILE]);
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('warning')->with(
			self::stringContains('has no Nextcloud user'),
			['id' => 'cred-1', 'learner' => self::PROFILE, 'course' => 'course-1']
		);

		$this->listener->handle($this->expired(['id' => 'cred-1', 'learnerId' => self::PROFILE, 'courseId' => 'course-1', 'tenant_id' => 't-1']));
	}//end testALearnerWithoutAUserIsNotEnrolled()

	/**
	 * A renewal Enrolment saved without an id is not linked back, and a plain learner id carries no learnerRef.
	 *
	 * @return void
	 */
	public function testAnEnrolmentWithoutAnIdIsNotLinked(): void {
		$saved = $this->createMock(ObjectEntity::class);
		$saved->method('jsonSerialize')->willReturn([]);
		$this->objectService->expects(self::once())->method('saveObject')->with(
			[
				'learnerId' => 'learner-1',
				'courseId' => 'course-1',
				'source' => 'credential-renewal',
				'mandatory' => true,
				'regulationSlug' => null,
				'tenant_id' => 't-1',
			],
			self::anything(),
			'learniq',
			'enrolment'
		)->willReturn($saved);
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('has no id; not linking'), ['id' => 'cred-2']);

		$this->listener->handle($this->expired(['uuid' => 'cred-2', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 't-1']));
	}//end testAnEnrolmentWithoutAnIdIsNotLinked()
}//end class
