<?php

/**
 * ElectiveSignUpRules with the real ObjectCreatingEvent and ObjectUpdatingEvent.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTimeImmutable;
use OCA\Learniq\Listener\ElectiveSignUpRules;
use OCA\Learniq\Service\ElectiveService;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Eligibility, window, capacity and one per lesson, for learners, staff and integrations.
 */
class ElectiveSignUpRulesTest extends TestCase {

	/**
	 * Existing sign-ups.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $signUps = [];

	/**
	 * The offer.
	 *
	 * @var array<string, mixed>
	 */
	private array $offer = [];

	/**
	 * The lesson's start: in ten days unless a test moves it.
	 *
	 * @var string
	 */
	private string $lessonStart = '';

	/**
	 * An open offer, two places, a window opening 7 days and closing 12 hours before.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->lessonStart = (new DateTimeImmutable('+3 days'))->format(DATE_ATOM);
		$this->offer = [
			'id' => 'offer-1', 'name' => 'Keuzewerktijd wiskunde', 'sessionIds' => ['lesson-1'], 'capacityPerLesson' => 2,
			'eligibleCohortIds' => ['cohort-h4'], 'windowMode' => 'relative', 'opensDaysBefore' => 7, 'closesHoursBefore' => 12,
			'lifecycle' => 'open', 'tenant_id' => 't1',
		];
		$this->signUps = [];
	}//end setUp()

	/**
	 * The listener, written by a user in these groups.
	 *
	 * @param string             $uid    The writer.
	 * @param array<int, string> $groups The writer's groups.
	 *
	 * @return ElectiveSignUpRules
	 */
	private function rules(string $uid, array $groups): ElectiveSignUpRules {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(fn ($id) => ($id === 'offer-1') ? OrEntityFactory::make($this->offer, 'elective-offer') : null);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				return match ($config['filters']['schema'] ?? '') {
					'session' => [['id' => 'lesson-1', 'title' => 'Donderdag', 'startsAt' => $this->lessonStart, 'endsAt' => $this->lessonStart]],
					'cohort' => [['id' => 'cohort-h4', 'learnerIds' => ['j.bakker', 't.smit', 'a.jansen']]],
					'elective-sign-up' => $this->signUps,
					default => [],
				};
			}
		);

		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('registerSlug')->willReturn('learniq');
		$resolver->method('schemaSlug')->willReturnCallback(static fn ($entity) => $entity->getSchema());

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new ElectiveSignUpRules(new ElectiveService($objects), $resolver, $session, $groupManager, new NullLogger());
	}//end rules()

	/**
	 * Create a sign-up through the real creating event.
	 *
	 * @param ElectiveSignUpRules  $rules  The listener.
	 * @param array<string, mixed> $signUp The sign-up.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(ElectiveSignUpRules $rules, array $signUp): ObjectCreatingEvent {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(array_merge(['offerId' => 'offer-1', 'sessionId' => 'lesson-1', 'status' => 'signed-up'], $signUp), 'elective-sign-up'));
		$rules->handle($event);

		return $event;
	}//end create()

	/**
	 * An eligible learner signs up inside the window, and it is stamped as theirs.
	 *
	 * @return void
	 */
	public function testALearnerSignsUpInsideTheWindow(): void {
		$event = $this->create($this->rules('j.bakker', ['learners']), ['learnerId' => 'j.bakker']);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['madeBy' => 'j.bakker', 'madeVia' => 'learner'], $event->getModifiedData());
	}//end testALearnerSignsUpInsideTheWindow()

	/**
	 * A learner cannot sign someone else up.
	 *
	 * @return void
	 */
	public function testALearnerSignsUpOnlyThemself(): void {
		$event = $this->create($this->rules('j.bakker', ['learners']), ['learnerId' => 't.smit']);

		self::assertSame(ElectiveSignUpRules::OWN_NAME, $event->getErrors()['message']);
	}//end testALearnerSignsUpOnlyThemself()

	/**
	 * A learner outside the eligible groups is refused.
	 *
	 * @return void
	 */
	public function testIneligibleLearnerIsRefused(): void {
		$event = $this->create($this->rules('v.other', ['learners']), ['learnerId' => 'v.other']);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(ElectiveSignUpRules::NOT_ELIGIBLE, $event->getErrors()['message']);
	}//end testIneligibleLearnerIsRefused()

	/**
	 * After the window closes a learner is refused, and a coordinator places.
	 *
	 * @return void
	 */
	public function testClosedWindowRefusesTheLearnerAndLetsStaffPlace(): void {
		$this->lessonStart = (new DateTimeImmutable('+10 hours'))->format(DATE_ATOM);

		$learner = $this->create($this->rules('j.bakker', ['learners']), ['learnerId' => 'j.bakker']);
		self::assertSame(ElectiveSignUpRules::CLOSED, $learner->getErrors()['message']);

		$placed = $this->create($this->rules('coordinator-1', ['coordinators']), ['learnerId' => 't.smit', 'status' => 'placed']);
		self::assertFalse($placed->isPropagationStopped());
		self::assertSame('coordinator', $placed->getModifiedData()['madeVia']);

		$learnerPlacing = $this->create($this->rules('j.bakker', ['learners']), ['learnerId' => 'j.bakker', 'status' => 'placed']);
		self::assertSame(ElectiveSignUpRules::ONLY_STAFF_PLACE, $learnerPlacing->getErrors()['message']);
	}//end testClosedWindowRefusesTheLearnerAndLetsStaffPlace()

	/**
	 * A full lesson refuses a coordinator too.
	 *
	 * @return void
	 */
	public function testCapacityHoldsForStaff(): void {
		$this->signUps = [
			['id' => 'su-1', 'offerId' => 'offer-1', 'sessionId' => 'lesson-1', 'learnerId' => 'j.bakker', 'status' => 'signed-up'],
			['id' => 'su-2', 'offerId' => 'offer-1', 'sessionId' => 'lesson-1', 'learnerId' => 't.smit', 'status' => 'placed'],
			['id' => 'su-3', 'offerId' => 'offer-1', 'sessionId' => 'lesson-1', 'learnerId' => 'x.gone', 'status' => 'withdrawn'],
		];

		$event = $this->create($this->rules('coordinator-1', ['coordinators']), ['learnerId' => 'a.jansen', 'status' => 'placed']);

		self::assertSame(ElectiveSignUpRules::FULL, $event->getErrors()['message']);
	}//end testCapacityHoldsForStaff()

	/**
	 * One sign-up per learner per lesson.
	 *
	 * @return void
	 */
	public function testNoSecondSignUpForTheSameLesson(): void {
		$this->signUps = [['id' => 'su-1', 'offerId' => 'offer-1', 'sessionId' => 'lesson-1', 'learnerId' => 'j.bakker', 'status' => 'signed-up']];

		$event = $this->create($this->rules('j.bakker', ['learners']), ['learnerId' => 'j.bakker']);

		self::assertSame(ElectiveSignUpRules::DOUBLE, $event->getErrors()['message']);
	}//end testNoSecondSignUpForTheSameLesson()

	/**
	 * Another system signs a learner up and is recorded as such; a full lesson refuses it.
	 *
	 * @return void
	 */
	public function testAnIntegrationSignsUpUnderTheSameRules(): void {
		$event = $this->create($this->rules('student-app', ['elective-integrations']), ['learnerId' => 'j.bakker']);
		self::assertSame('integration', $event->getModifiedData()['madeVia']);

		$this->offer['capacityPerLesson'] = 1;
		$this->signUps = [['id' => 'su-1', 'offerId' => 'offer-1', 'sessionId' => 'lesson-1', 'learnerId' => 't.smit', 'status' => 'signed-up']];
		$full = $this->create($this->rules('student-app', ['elective-integrations']), ['learnerId' => 'j.bakker']);
		self::assertSame(ElectiveSignUpRules::FULL, $full->getErrors()['message']);
	}//end testAnIntegrationSignsUpUnderTheSameRules()

	/**
	 * A lesson that is not the offer's, or a closed offer, is refused.
	 *
	 * @return void
	 */
	public function testLessonMustBeInAnOpenOffer(): void {
		$other = $this->create($this->rules('j.bakker', ['learners']), ['learnerId' => 'j.bakker', 'sessionId' => 'lesson-9']);
		self::assertSame(ElectiveSignUpRules::NOT_IN_OFFER, $other->getErrors()['message']);

		$this->offer['lifecycle'] = 'closed';
		$closed = $this->create($this->rules('j.bakker', ['learners']), ['learnerId' => 'j.bakker']);
		self::assertSame(ElectiveSignUpRules::OFFER_NOT_OPEN, $closed->getErrors()['message']);
	}//end testLessonMustBeInAnOpenOffer()

	/**
	 * Withdrawing through the real updating event: the learner inside the
	 * window, staff until the lesson starts, nobody after it started.
	 *
	 * @return void
	 */
	public function testWithdrawal(): void {
		$row = ['id' => 'su-1', 'offerId' => 'offer-1', 'sessionId' => 'lesson-1', 'learnerId' => 'j.bakker', 'status' => 'withdrawn'];
		$old = OrEntityFactory::make(array_merge($row, ['status' => 'signed-up']), 'elective-sign-up');

		$inside = new ObjectUpdatingEvent(OrEntityFactory::make($row, 'elective-sign-up'), $old);
		$this->rules('j.bakker', ['learners'])->handle($inside);
		self::assertFalse($inside->isPropagationStopped());

		$this->lessonStart = (new DateTimeImmutable('+2 hours'))->format(DATE_ATOM);
		$late = new ObjectUpdatingEvent(OrEntityFactory::make($row, 'elective-sign-up'), $old);
		$this->rules('j.bakker', ['learners'])->handle($late);
		self::assertSame(ElectiveSignUpRules::CLOSED, $late->getErrors()['message']);

		$staff = new ObjectUpdatingEvent(OrEntityFactory::make($row, 'elective-sign-up'), $old);
		$this->rules('coordinator-1', ['coordinators'])->handle($staff);
		self::assertFalse($staff->isPropagationStopped());

		$this->lessonStart = (new DateTimeImmutable('-1 hour'))->format(DATE_ATOM);
		$started = new ObjectUpdatingEvent(OrEntityFactory::make($row, 'elective-sign-up'), $old);
		$this->rules('coordinator-1', ['coordinators'])->handle($started);
		self::assertSame(ElectiveSignUpRules::STARTED, $started->getErrors()['message']);
	}//end testWithdrawal()

	/**
	 * Other schemas pass untouched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreIgnored(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'x'], 'enrolment'));
		$this->rules('j.bakker', ['learners'])->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testOtherSchemasAreIgnored()
}//end class
