<?php

/**
 * Tests for ReadableCopyStamp and the register fragments it writes into.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\CohortNameCascade;
use OCA\Learniq\Listener\ReadableCopyStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ReadableCopies;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ReadableCopyStamp::handle().
 */
class ReadableCopyStampTest extends TestCase {

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	/**
	 * The fake OpenRegister store behind the real service.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * A user manager that knows one teacher's display name.
	 *
	 * @return IUserManager
	 */
	private function users(): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => ['po-leerkracht-09' => 'Meester Daan'][$uid] ?? null
		);

		return $users;
	}//end users()

	/**
	 * Build the listener over a real ReadableCopies and the fake store.
	 *
	 * @param string $slug What the schema resolver answers for the entity.
	 *
	 * @return ReadableCopyStamp
	 */
	private function makeStamp(string $slug): ReadableCopyStamp {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'course' => [['id' => 'course-rekenen', 'name' => 'Rekenen']],
			'cohort' => [['id' => 'cohort-6', 'name' => 'Groep 6']],
			'portfolio' => [['id' => 'portfolio-1', 'title' => 'Proeve meterkast', 'learnerRef' => 'profile-daan']],
			'learner-profile' => [['id' => 'profile-daan', 'givenName' => 'Daan', 'familyName' => 'Visser']],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		return new ReadableCopyStamp(
			schemaResolver: $schemaResolver,
			copies: new ReadableCopies(objectService: $objectService, users: $this->users()),
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * A grade gets the name of its course.
	 *
	 * @return void
	 */
	public function testAGradeGetsItsCourseName(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['courseId' => 'course-rekenen', 'value' => '8,0'], 'grade-entry'));
		$this->makeStamp(slug: 'grade-entry')->handle($event);

		self::assertSame(['courseName' => 'Rekenen'], $event->getModifiedData());
		self::assertFalse($event->isPropagationStopped());
	}//end testAGradeGetsItsCourseName()

	/**
	 * A course name a client sends is replaced by the course's own name.
	 *
	 * @return void
	 */
	public function testAClientCannotSetTheCourseName(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['courseId' => 'course-rekenen', 'courseName' => 'Gym'], 'grade-entry'));
		$this->makeStamp(slug: 'grade-entry')->handle($event);

		self::assertSame('Rekenen', $event->getModifiedData()['courseName']);
	}//end testAClientCannotSetTheCourseName()

	/**
	 * An enrolment gets the name of its group.
	 *
	 * @return void
	 */
	public function testAnEnrolmentGetsItsGroupName(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['cohortId' => 'cohort-6', 'learnerRef' => 'profile-vera'], 'enrolment'));
		$this->makeStamp(slug: 'enrolment')->handle($event);

		self::assertSame(['cohortName' => 'Groep 6'], $event->getModifiedData());
	}//end testAnEnrolmentGetsItsGroupName()

	/**
	 * A share gets the portfolio title and the learner's name, and a name a
	 * client sends is replaced.
	 *
	 * @return void
	 */
	public function testAShareNamesItsPortfolioAndLearner(): void {
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(['portfolioId' => 'portfolio-1', 'learnerName' => 'Iemand anders'], 'portfolio-share')
		);
		$this->makeStamp(slug: 'portfolio-share')->handle($event);

		self::assertSame(['portfolioTitle' => 'Proeve meterkast', 'learnerName' => 'Daan Visser'], $event->getModifiedData());
	}//end testAShareNamesItsPortfolioAndLearner()

	/**
	 * A teacher availability gets the teacher's display name, and a name a
	 * client sends is replaced; an unknown teacher stores none.
	 *
	 * @return void
	 */
	public function testAnAvailabilityNamesItsTeacher(): void {
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(['teacherId' => 'po-leerkracht-09', 'teacherName' => 'Iemand anders', 'blocks' => []], 'teacher-availability')
		);
		$this->makeStamp(slug: 'teacher-availability')->handle($event);
		self::assertSame(['teacherName' => 'Meester Daan'], $event->getModifiedData());

		$unknown = new ObjectCreatingEvent(OrEntityFactory::make(['teacherId' => 'nobody', 'blocks' => []], 'teacher-availability'));
		$this->makeStamp(slug: 'teacher-availability')->handle($unknown);
		self::assertSame(['teacherName' => null], $unknown->getModifiedData());
	}//end testAnAvailabilityNamesItsTeacher()

	/**
	 * A pointer to nothing stores no name, not the old one or a guess.
	 *
	 * @return void
	 */
	public function testAPointerToNothingStoresNoName(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['courseId' => 'course-gone', 'courseName' => 'Gym'], 'grade-entry'));
		$this->makeStamp(slug: 'grade-entry')->handle($event);

		self::assertNull($event->getModifiedData()['courseName']);
	}//end testAPointerToNothingStoresNoName()

	/**
	 * When OpenRegister cannot be read, an update keeps the stored name and
	 * the write goes through.
	 *
	 * @return void
	 */
	public function testAFailedReadKeepsTheStoredNameOnUpdate(): void {
		$old = ['cohortId' => 'cohort-6', 'cohortName' => 'Groep 6'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($old, ['cohortName' => 'Made up']), 'enrolment'),
			OrEntityFactory::make($old, 'enrolment')
		);
		$stamp = $this->makeStamp(slug: 'enrolment');
		$this->store->failReads = 'database gone';
		$stamp->handle($event);

		self::assertSame('Groep 6', $event->getModifiedData()['cohortName']);
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedReadKeepsTheStoredNameOnUpdate()

	/**
	 * When OpenRegister cannot be read on a create, the row is stored without
	 * names rather than refused.
	 *
	 * @return void
	 */
	public function testAFailedReadStoresNoNamesOnCreate(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['portfolioId' => 'portfolio-1', 'learnerName' => 'Iemand anders'], 'portfolio-share'));
		$stamp = $this->makeStamp(slug: 'portfolio-share');
		$this->store->failReads = 'database gone';
		$stamp->handle($event);

		self::assertSame(['portfolioTitle' => null, 'learnerName' => null], $event->getModifiedData());
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedReadStoresNoNamesOnCreate()

	/**
	 * Another schema's write is never touched.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['courseId' => 'course-rekenen'], 'final-grade'));
		$this->makeStamp(slug: 'final-grade')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * An event that is not a write, a stopped write and a write whose schema
	 * cannot be resolved are all left untouched, and nothing is read.
	 *
	 * @return void
	 */
	public function testGuardsLeaveTheEventAlone(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects(self::never())->method('findAll');
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('unknown schema'));
		$stamp = new ReadableCopyStamp(schemaResolver: $resolver, copies: new ReadableCopies(objectService: $objectService, users: $this->users()), logger: new NullLogger());

		$stamp->handle(new Event());

		$stopped = new ObjectCreatingEvent(OrEntityFactory::make(['courseId' => 'course-rekenen'], 'grade-entry'));
		$stopped->stopPropagation();
		$stamp->handle($stopped);
		self::assertSame([], $stopped->getModifiedData());

		$unknown = new ObjectCreatingEvent(OrEntityFactory::make(['courseId' => 'course-rekenen'], 'grade-entry'));
		$stamp->handle($unknown);
		self::assertSame([], $unknown->getModifiedData());
	}//end testGuardsLeaveTheEventAlone()

	/**
	 * The service answers nothing for a schema it does not cover, nulls for a
	 * share whose portfolio is gone, and reads plain array rows too. A blank
	 * name and a non-text name both store null, and a row that is neither an
	 * array nor an entity is skipped.
	 *
	 * @return void
	 */
	public function testTheServiceHandlesOddRows(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config): array {
				$schema = $config['filters']['schema'];
				$rows = [
					'course' => ['not-a-row', ['id' => 'course-blank', 'name' => '   ']],
					'portfolio' => [['id' => 'portfolio-1', 'title' => 'Proeve', 'learnerRef' => 'profile-odd']],
					'learner-profile' => [['id' => 'profile-odd', 'givenName' => 42, 'familyName' => 'Visser']],
				];
				return ($rows[$schema] ?? []);
			}
		);
		$copies = new ReadableCopies(objectService: $objectService, users: $this->users());

		self::assertFalse($copies->covers(slug: 'final-grade'));
		self::assertSame([], $copies->derive(slug: 'final-grade', row: ['courseId' => 'course-blank']));
		self::assertSame(['courseName' => null], $copies->derive(slug: 'grade-entry', row: ['courseId' => 'course-blank']));
		// A grade without a course, or with an empty pointer, names none.
		self::assertSame(['courseName' => null], $copies->derive(slug: 'grade-entry', row: []));
		self::assertSame(['cohortName' => null], $copies->derive(slug: 'enrolment', row: ['cohortId' => '']));
		self::assertSame(['portfolioTitle' => null, 'learnerName' => null], $copies->derive(slug: 'portfolio-share', row: ['portfolioId' => 'portfolio-gone']));
		self::assertSame(['portfolioTitle' => 'Proeve', 'learnerName' => 'Visser'], $copies->derive(slug: 'portfolio-share', row: ['portfolioId' => 'portfolio-1']));
		self::assertSame(['portfolioTitle', 'learnerName'], $copies->fields(slug: 'portfolio-share'));
	}//end testTheServiceHandlesOddRows()

	/**
	 * The stamp is wired for creates and updates, and the cascade for stored
	 * cohort updates.
	 *
	 * @return void
	 */
	public function testTheListenersAreRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . ReadableCopyStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . ReadableCopyStamp::class, $registered);
		self::assertContains(ObjectUpdatedEvent::class . ' => ' . CohortNameCascade::class, $registered);
	}//end testTheListenersAreRegistered()

	/**
	 * Rows carrying the stamped copies pass the shipped schema fragments, and
	 * a wrongly typed copy does not (control).
	 *
	 * @return void
	 */
	public function testStampedRowsPassTheRealSchemas(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$schemas = $register['components']['schemas'];
		$validator = new Validator();
		$rows = [
			'GradeEntry' => ['learnerId' => 'pupil-1', 'curriculumPlanId' => 'ee010005-0000-4000-8000-000000000001', 'componentId' => 'c-1', 'gradeScaleId' => 'ee010006-0000-4000-8000-000000000001', 'courseId' => 'ee010004-0000-4000-8000-000000000001', 'courseName' => 'Rekenen', 'value' => 8.0, 'weight' => 2, 'tenant_id' => self::TENANT],
			'Enrolment' => ['learnerId' => 'pupil-1', 'courseId' => 'ee010004-0000-4000-8000-000000000001', 'source' => 'admission', 'cohortId' => 'ee010003-0000-4000-8000-000000000007', 'cohortName' => 'Groep 6', 'tenant_id' => self::TENANT],
			'PortfolioShare' => ['portfolioId' => 'ee010009-0000-4000-8000-000000000001', 'portfolioTitle' => 'Proeve meterkast', 'learnerName' => 'Daan Visser', 'sharedWithKind' => 'external-assessor', 'sharedBy' => 'teacher-1', 'tenant_id' => self::TENANT],
			'TeacherAvailability' => ['conferenceRoundId' => 'ee010020-0000-4000-8000-000000000001', 'teacherId' => 'po-leerkracht-09', 'teacherName' => 'Meester Daan', 'blocks' => [['startsAt' => '2026-10-15T16:00:00+00:00', 'endsAt' => '2026-10-15T18:00:00+00:00']], 'tenant_id' => self::TENANT, 'lifecycle' => 'submitted'],
		];

		foreach ($rows as $schema => $row) {
			$json = (string)json_encode($this->validatable(schema: $schemas[$schema]));
			$result = $validator->validate(json_decode((string)json_encode($row)), $json);
			self::assertTrue($result->isValid(), $schema . ': ' . json_encode($result->error()?->message()));

			$nulls = $row;
			foreach (['courseName', 'cohortName', 'portfolioTitle', 'learnerName', 'teacherName'] as $copy) {
				if (array_key_exists($copy, $nulls) === true) {
					$nulls[$copy] = null;
				}
			}

			self::assertTrue($validator->validate(json_decode((string)json_encode($nulls)), $json)->isValid(), $schema . ': a copy may be null');
		}

		$bad = array_merge($rows['Enrolment'], ['cohortName' => ['Groep 6']]);
		$json = (string)json_encode($this->validatable(schema: $schemas['Enrolment']));
		self::assertFalse($validator->validate(json_decode((string)json_encode($bad)), $json)->isValid(), 'control: a group name is text');
	}//end testStampedRowsPassTheRealSchemas()

	/**
	 * The schema without OpenRegister's own keys, which a JSON Schema validator
	 * cannot resolve, with `nullable` written the JSON Schema way.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private function validatable(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'authorization' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version', 'readOnly', 'appendOnly'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = $this->validatable(schema: $value);
			}
		}

		if (($clean['nullable'] ?? false) === true && is_string($clean['type'] ?? null) === true) {
			$clean['type'] = [$clean['type'], 'null'];
			if (isset($clean['enum']) === true) {
				$clean['enum'][] = null;
			}
		}

		unset($clean['nullable']);
		return $clean;
	}//end validatable()
}//end class
