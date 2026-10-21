<?php

/**
 * Learniq SubjectChoiceConsentStamp unit tests.
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
 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\SubjectChoiceConsentStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SubjectChoiceConsentStamp::handle().
 */
class SubjectChoiceConsentStampTest extends TestCase {

	/**
	 * Build the stamp, signed in as $userId.
	 *
	 * @param string|null $userId The session user, null for no session.
	 * @param string      $slug   What the schema resolver answers.
	 *
	 * @return SubjectChoiceConsentStamp
	 */
	private function makeStamp(?string $userId, string $slug = 'subject-choice'): SubjectChoiceConsentStamp {
		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SubjectChoiceConsentStamp(schemaResolver: $schemaResolver, userSession: $session);
	}//end makeStamp()

	/**
	 * Consent given on create is recorded in the caller's name, whatever the
	 * client sent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
	 */
	public function testConsentIsRecordedInTheCallersName(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'lrn-1', 'guardianConsentGiven' => true, 'guardianConsentBy' => 'parent-2'], 'subject-choice'));
		$this->makeStamp(userId: 'parent-1')->handle($event);

		self::assertSame('parent-1', $event->getModifiedData()['guardianConsentBy']);
	}//end testConsentIsRecordedInTheCallersName()

	/**
	 * Without consent nobody is named.
	 *
	 * @return void
	 */
	public function testWithoutConsentNobodyIsNamed(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'lrn-1', 'guardianConsentGiven' => false, 'guardianConsentBy' => 'parent-2'], 'subject-choice'));
		$this->makeStamp(userId: 'parent-1')->handle($event);

		self::assertNull($event->getModifiedData()['guardianConsentBy']);
	}//end testWithoutConsentNobodyIsNamed()

	/**
	 * A later update by someone else keeps the guardian who consented.
	 *
	 * @return void
	 */
	public function testALaterUpdateKeepsWhoConsented(): void {
		$stored = ['learnerId' => 'lrn-1', 'guardianConsentGiven' => true, 'guardianConsentBy' => 'parent-1', 'lifecycle' => 'submitted'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['lifecycle' => 'approved', 'guardianConsentBy' => 'mentor-1']), 'subject-choice'),
			OrEntityFactory::make($stored, 'subject-choice')
		);
		$this->makeStamp(userId: 'mentor-1')->handle($event);

		self::assertSame('parent-1', $event->getModifiedData()['guardianConsentBy']);
	}//end testALaterUpdateKeepsWhoConsented()

	/**
	 * Consent given on an update is recorded in the caller's name.
	 *
	 * @return void
	 */
	public function testConsentGivenOnAnUpdateNamesTheCaller(): void {
		$stored = ['learnerId' => 'lrn-1', 'guardianConsentGiven' => false, 'guardianConsentBy' => null, 'lifecycle' => 'draft'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['guardianConsentGiven' => true]), 'subject-choice'),
			OrEntityFactory::make($stored, 'subject-choice')
		);
		$this->makeStamp(userId: 'parent-1')->handle($event);

		self::assertSame('parent-1', $event->getModifiedData()['guardianConsentBy']);
	}//end testConsentGivenOnAnUpdateNamesTheCaller()

	/**
	 * A write without a session keeps the stored value.
	 *
	 * @return void
	 */
	public function testAWriteWithoutASessionKeepsTheStoredValue(): void {
		$stored = ['learnerId' => 'lrn-1', 'guardianConsentGiven' => true, 'guardianConsentBy' => 'parent-1', 'lifecycle' => 'submitted'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['lifecycle' => 'validated']), 'subject-choice'),
			OrEntityFactory::make($stored, 'subject-choice')
		);
		$this->makeStamp(userId: null)->handle($event);

		self::assertSame('parent-1', $event->getModifiedData()['guardianConsentBy']);
	}//end testAWriteWithoutASessionKeepsTheStoredValue()

	/**
	 * Another schema's writes are left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['guardianConsentGiven' => true], 'enrolment'));
		$this->makeStamp(userId: 'parent-1', slug: 'enrolment')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The stamp is wired on both create and update.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . SubjectChoiceConsentStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . SubjectChoiceConsentStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
