<?php

/**
 * A minor's own subject choice waits for a linked guardian's consent.
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#requirement-a-minor-s-own-subject-choice-waits-for-a-guardian-s-consent
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\SubjectChoiceValidator;
use OCA\Learniq\Listener\GuardianConsentHold;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ListenerSlugContract;
use OCA\Learniq\Support\ObjectRow;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Noor (15) submits her own choice; it waits. Her linked guardian consents;
 * then the choice is checked against the plan. Consent by anyone else, or no
 * consent, keeps it waiting; an adult's choice is checked at once.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#requirement-a-minor-s-own-subject-choice-waits-for-a-guardian-s-consent
 */
#[CoversClass(SubjectChoiceValidator::class)]
#[CoversClass(GuardianConsentHold::class)]
#[UsesClass(ObjectRow::class)]
#[UsesClass(ListenerSchemaResolver::class)]
#[UsesClass(ListenerSlugContract::class)]
class SubjectChoiceGuardianConsentTest extends TestCase {

	private const TENANT = '11111111-1111-4111-8111-111111111111';

	/**
	 * 2026-11-14 12:00 UTC.
	 */
	private const NOW = 1794657600;

	/**
	 * Every save the validator made.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The learner profiles findAll answers.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $profiles = [];

	/**
	 * Noor's profile: 15, with guardian `mam.noor`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->profiles = [['ncUserId' => 'noor', 'birthDate' => '2011-03-02', 'parentIds' => ['mam.noor'], 'tenant_id' => self::TENANT]];
	}//end setUp()

	/**
	 * The validator over a plan that asks for exactly two electives.
	 *
	 * @return SubjectChoiceValidator
	 */
	private function validator(): SubjectChoiceValidator {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static fn (int|string $id, ?ObjectFindOptions $options = null): ?ObjectEntity => ($options?->schema) === 'curriculum-plan'
				? OrEntityFactory::make(['id' => 'plan-1', 'electiveRules' => ['minElectives' => 2, 'maxElectives' => 2]], 'curriculum-plan')
				: null
		);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => ($config['filters']['schema'] ?? '') === 'learner-profile'
				? array_values(array_filter($this->profiles, static fn (array $p): bool => $p['ncUserId'] === ($config['filters']['ncUserId'] ?? null)))
				: []
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$this->saved[] = (array)$object;
				return OrEntityFactory::make((array)$object, (string)$schema);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new SubjectChoiceValidator($objectService, new NullLogger(), TransitionScope::resolver(), new GuardianConsentHold($objectService, $time));
	}//end validator()

	/**
	 * A choice for a learner.
	 *
	 * @param string               $learnerId The learner.
	 * @param array<string, mixed> $extra     Fields to set.
	 *
	 * @return array<string, mixed>
	 */
	private static function choice(string $learnerId, array $extra=[]): array {
		return array_merge(
			[
				'id' => 'choice-1',
				'learnerId' => $learnerId,
				'curriculumPlanId' => 'plan-1',
				'academicYear' => '2027-2028',
				'selectedElectiveCourseIds' => ['course-a', 'course-b'],
				'guardianConsentGiven' => false,
				'guardianConsentBy' => null,
				'lifecycle' => 'submitted',
				'tenant_id' => self::TENANT,
			],
			$extra
		);
	}//end choice()

	/**
	 * The submit transition of a choice.
	 *
	 * @param array<string, mixed> $choice The choice.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function submitted(array $choice): ObjectTransitionedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($choice);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('subject-choice');
		$event->method('getTo')->willReturn('submitted');

		return $event;
	}//end submitted()

	/**
	 * An update of a stored choice, as OpenRegister dispatches it.
	 *
	 * @param array<string, mixed> $new The choice after the write.
	 * @param array<string, mixed> $old The choice before it.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private static function updated(array $new, array $old): ObjectUpdatedEvent {
		$schema = TransitionScope::schemaId(slug: 'subject-choice');

		return new ObjectUpdatedEvent(
			OrEntityFactory::make($new, $schema, TransitionScope::LEARNIQ_REGISTER_ID),
			OrEntityFactory::make($old, $schema, TransitionScope::LEARNIQ_REGISTER_ID)
		);
	}//end updated()

	/**
	 * Noor, 15, submits her own choice: it waits for consent and is not validated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#scenario-a-15-year-old-submits-their-own-choice
	 */
	public function testAFifteenYearOldsOwnChoiceWaitsForConsent(): void {
		$this->validator()->handle($this->submitted(choice: self::choice(learnerId: 'noor')));

		$this->assertSame([], $this->saved);
	}//end testAFifteenYearOldsOwnChoiceWaitsForConsent()

	/**
	 * Her linked guardian consents: the choice is checked and validated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#scenario-the-guardian-consents
	 */
	public function testTheLinkedGuardiansConsentLetsTheChoiceBeChecked(): void {
		$old = self::choice(learnerId: 'noor');
		$new = self::choice(learnerId: 'noor', extra: ['guardianConsentGiven' => true, 'guardianConsentBy' => 'mam.noor']);

		$this->validator()->handle(self::updated(new: $new, old: $old));

		$this->assertCount(1, $this->saved);
		$this->assertSame('validated', $this->saved[0]['lifecycle']);
		$this->assertTrue($this->saved[0]['guardianConsentGiven']);
		$this->assertSame('mam.noor', $this->saved[0]['guardianConsentBy']);
	}//end testTheLinkedGuardiansConsentLetsTheChoiceBeChecked()

	/**
	 * After consent the plan's rules still apply: one elective is sent back.
	 *
	 * @return void
	 */
	public function testAfterConsentTooFewElectivesNeedRevision(): void {
		$old = self::choice(learnerId: 'noor', extra: ['selectedElectiveCourseIds' => ['course-a']]);
		$new = array_merge($old, ['guardianConsentGiven' => true, 'guardianConsentBy' => 'mam.noor']);

		$this->validator()->handle(self::updated(new: $new, old: $old));

		$this->assertSame('needs-revision', $this->saved[0]['lifecycle']);
		$this->assertNotSame([], $this->saved[0]['validationErrors']);
	}//end testAfterConsentTooFewElectivesNeedRevision()

	/**
	 * Consent by someone who is not a linked guardian, or none, keeps it waiting.
	 *
	 * @return void
	 */
	public function testConsentByAnyoneElseKeepsItWaiting(): void {
		$old = self::choice(learnerId: 'noor');
		$this->validator()->handle(self::updated(new: self::choice(learnerId: 'noor', extra: ['guardianConsentGiven' => true, 'guardianConsentBy' => 'noor']), old: $old));
		$this->validator()->handle(self::updated(new: self::choice(learnerId: 'noor', extra: ['guardianConsentGiven' => true, 'guardianConsentBy' => 'buurman']), old: $old));
		$this->validator()->handle(self::updated(new: self::choice(learnerId: 'noor', extra: ['academicYear' => '2028-2029']), old: $old));

		$this->assertSame([], $this->saved);
	}//end testConsentByAnyoneElseKeepsItWaiting()

	/**
	 * A minor whose guardian consented before submitting is checked at submit.
	 *
	 * @return void
	 */
	public function testAChoiceSubmittedWithTheGuardiansConsentIsCheckedAtOnce(): void {
		$this->validator()->handle($this->submitted(choice: self::choice(learnerId: 'noor', extra: ['guardianConsentGiven' => true, 'guardianConsentBy' => 'mam.noor'])));

		$this->assertSame('validated', $this->saved[0]['lifecycle']);
	}//end testAChoiceSubmittedWithTheGuardiansConsentIsCheckedAtOnce()

	/**
	 * An 18-year-old's own choice is checked at submit, and a later update does not check it again.
	 *
	 * @return void
	 */
	public function testAnAdultsOwnChoiceIsCheckedAtSubmitOnly(): void {
		$this->profiles[] = ['ncUserId' => 'sem', 'birthDate' => '2008-11-14', 'parentIds' => [], 'tenant_id' => self::TENANT];

		$this->validator()->handle($this->submitted(choice: self::choice(learnerId: 'sem')));
		$this->assertCount(1, $this->saved);
		$this->assertSame('validated', $this->saved[0]['lifecycle']);

		$this->validator()->handle(self::updated(new: self::choice(learnerId: 'sem', extra: ['guardianConsentGiven' => true, 'guardianConsentBy' => 'sem']), old: self::choice(learnerId: 'sem')));
		$this->assertCount(1, $this->saved);
	}//end testAnAdultsOwnChoiceIsCheckedAtSubmitOnly()

	/**
	 * The age reading: the day before the 18th birthday is a minor, the day itself is not;
	 * without a birth date ageYears decides; without either the learner is not held.
	 *
	 * @return void
	 */
	public function testTheAgeIsReadFromTheBirthDateElseAgeYears(): void {
		$today = new \DateTimeImmutable('2026-11-14');

		$this->assertTrue(GuardianConsentHold::isMinorProfile(profile: ['birthDate' => '2008-11-15'], today: $today));
		$this->assertFalse(GuardianConsentHold::isMinorProfile(profile: ['birthDate' => '2008-11-14'], today: $today));
		$this->assertTrue(GuardianConsentHold::isMinorProfile(profile: ['birthDate' => null, 'ageYears' => 16], today: $today));
		$this->assertFalse(GuardianConsentHold::isMinorProfile(profile: ['ageYears' => 18], today: $today));
		$this->assertFalse(GuardianConsentHold::isMinorProfile(profile: [], today: $today));
		$this->assertTrue(GuardianConsentHold::isMinorProfile(profile: ['birthDate' => '2030-01-01'], today: $today));
	}//end testTheAgeIsReadFromTheBirthDateElseAgeYears()
}//end class
