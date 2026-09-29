<?php

/**
 * Learniq catalogue sign-up unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Catalogue
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Catalogue;

use OCA\Learniq\Service\Catalogue\CatalogueReader;
use OCA\Learniq\Service\Catalogue\CatalogueSignUpService;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The real reader and sign-up service over a register-faithful store; the
 * prerequisite listener is played by a create that throws its veto.
 */
class CatalogueSignUpServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Course ids whose create the prerequisite check refuses.
	 *
	 * @var array<int, string>
	 */
	private array $vetoed = [];

	/**
	 * Users every write ran as.
	 *
	 * @var array<int, string>
	 */
	private array $ranAs = [];

	/**
	 * The service over a store with courses, a programme and a profile.
	 *
	 * @return CatalogueSignUpService
	 */
	private function service(): CatalogueSignUpService {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['course'] = [
			['id' => 'c-excel', 'name' => 'Excel voor gevorderden', 'lifecycle' => 'published', 'selfEnrolment' => 'open', 'author' => 'Go1', 'tenant_id' => 't1'],
			['id' => 'c-lead', 'name' => 'Leidinggeven aan hybride teams', 'lifecycle' => 'published', 'selfEnrolment' => 'on-request', 'tenant_id' => 't1'],
			['id' => 'c-closed', 'name' => 'Gesloten', 'lifecycle' => 'published', 'selfEnrolment' => 'closed'],
			['id' => 'c-draft', 'name' => 'Concept', 'lifecycle' => 'draft', 'selfEnrolment' => 'open'],
			['id' => 'c-pm2', 'name' => 'Projectmanagement 2', 'lifecycle' => 'published'],
		];
		$this->store->rows['programme'] = [
			['id' => 'p-pm', 'name' => 'Basis projectmanagement', 'lifecycle' => 'published', 'selfEnrolment' => 'open', 'courseIds' => ['c-excel', 'c-lead', 'c-pm2'], 'tenant_id' => 't1'],
		];
		$this->store->rows['learner-profile'] = [['id' => 'lp-1', 'ncUserId' => 'p.ganpat', 'managerId' => 'm.visser']];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				if (in_array(($object['courseId'] ?? ''), $this->vetoed, true) === true && $uuid === null) {
					throw new class('stopped') extends RuntimeException {
						/**
						 * The listener's errors.
						 *
						 * @return array<string, string>
						 */
						public function getErrors(): array {
							return ['message' => 'Complete the prerequisite course "Excel basis" first.'];
						}//end getErrors()
					};
				}

				return $this->store->save((string)$schema, $object, $uuid);
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ObjectEntity {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				throw new DoesNotExistException('gone');
			}
		);
		$objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation): mixed {
				$this->ranAs[] = $user->getUID();
				return $operation();
			}
		);

		return new CatalogueSignUpService(objects: $objects, reader: new CatalogueReader(objects: $objects));
	}//end service()

	/**
	 * The learner p.ganpat.
	 *
	 * @return PortalLearner
	 */
	private function learner(): PortalLearner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('p.ganpat');

		return new PortalLearner(profileRef: 'lp-1', ncUserId: 'p.ganpat', tenantId: 't1', user: $user);
	}//end learner()

	/**
	 * The enrolments stored.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function enrolments(): array {
		return ($this->store->rows['enrolment'] ?? []);
	}//end enrolments()

	/**
	 * An open course: one active self enrolment for the caller, created in
	 * that state, with the manager copied, written as the learner.
	 *
	 * @return void
	 */
	public function testAnOpenCourseEnrolsTheCallerActive(): void {
		$outcome = $this->service()->signUpCourse(learner: $this->learner(), courseId: 'c-excel');

		self::assertSame(200, $outcome->status);
		$enrolment = $this->enrolments()[0];
		self::assertSame(['p.ganpat', 'lp-1', 'c-excel', 'self', 'active', 'm.visser', false], [$enrolment['learnerId'], $enrolment['learnerRef'], $enrolment['courseId'], $enrolment['source'], $enrolment['lifecycle'], $enrolment['managerId'], $enrolment['mandatory']]);
		self::assertSame(['p.ganpat'], $this->ranAs);
	}//end testAnOpenCourseEnrolsTheCallerActive()

	/**
	 * A course on request waits pending; a second sign-up is refused.
	 *
	 * @return void
	 */
	public function testACourseOnRequestIsPendingAndOnlyOnce(): void {
		$service = $this->service();
		self::assertSame('pending', $service->signUpCourse(learner: $this->learner(), courseId: 'c-lead')->body['lifecycle']);
		self::assertSame('already-signed-up', $service->signUpCourse(learner: $this->learner(), courseId: 'c-lead')->reason);
		self::assertCount(1, $this->enrolments());
	}//end testACourseOnRequestIsPendingAndOnlyOnce()

	/**
	 * A closed, unpublished or unknown course is refused without a write.
	 *
	 * @return void
	 */
	public function testClosedAndUnpublishedCoursesAreRefused(): void {
		$service = $this->service();
		self::assertSame('closed', $service->signUpCourse(learner: $this->learner(), courseId: 'c-closed')->reason);
		self::assertSame('not-found', $service->signUpCourse(learner: $this->learner(), courseId: 'c-draft')->reason);
		self::assertSame('not-found', $service->signUpCourse(learner: $this->learner(), courseId: 'nope')->reason);
		self::assertSame([], $this->enrolments());
	}//end testClosedAndUnpublishedCoursesAreRefused()

	/**
	 * The prerequisite veto comes back in the listener's words, nothing stored.
	 *
	 * @return void
	 */
	public function testPrerequisiteVetoIsReturned(): void {
		$service = $this->service();
		$this->vetoed = ['c-excel'];
		$outcome = $service->signUpCourse(learner: $this->learner(), courseId: 'c-excel');

		self::assertSame(422, $outcome->status);
		self::assertStringContainsString('Excel basis', $outcome->body['message']);
		self::assertSame([], $this->enrolments());
	}//end testPrerequisiteVetoIsReturned()

	/**
	 * A programme makes one enrolment per course the learner is not on, each
	 * naming the programme; a vetoed course is reported, the rest created.
	 *
	 * @return void
	 */
	public function testAProgrammeEnrolsEveryCourseNamingTheProgramme(): void {
		$service = $this->service();
		$service->signUpCourse(learner: $this->learner(), courseId: 'c-excel');
		$this->vetoed = ['c-pm2'];

		$outcome = $service->signUpProgramme(learner: $this->learner(), programmeId: 'p-pm');

		self::assertSame(['c-lead'], array_column($outcome->body['created'], 'courseId'));
		self::assertSame('c-pm2', $outcome->body['refused'][0]['courseId']);
		$fromProgramme = array_values(array_filter($this->enrolments(), static fn (array $e): bool => ($e['programmeId'] ?? null) === 'p-pm'));
		self::assertCount(1, $fromProgramme);
		self::assertSame('active', $fromProgramme[0]['lifecycle']);
	}//end testAProgrammeEnrolsEveryCourseNamingTheProgramme()

	/**
	 * A learner withdraws their own self sign-up without progress; not one
	 * made by staff, one with progress, or someone else's.
	 *
	 * @return void
	 */
	public function testALearnerWithdrawsOnlyTheirOwnUntouchedSignUp(): void {
		$service = $this->service();
		$this->store->rows['enrolment'] = [
			['id' => 'e-self', 'learnerId' => 'p.ganpat', 'courseId' => 'c-excel', 'source' => 'self', 'lifecycle' => 'active', 'progressPercent' => 0],
			['id' => 'e-hr', 'learnerId' => 'p.ganpat', 'courseId' => 'c-lead', 'source' => 'hr', 'lifecycle' => 'active', 'progressPercent' => 0],
			['id' => 'e-busy', 'learnerId' => 'p.ganpat', 'courseId' => 'c-pm2', 'source' => 'self', 'lifecycle' => 'active', 'progressPercent' => 40],
			['id' => 'e-other', 'learnerId' => 'someone', 'courseId' => 'c-excel', 'source' => 'self', 'lifecycle' => 'active'],
		];

		self::assertSame('withdrawn', $service->withdraw(learner: $this->learner(), enrolmentId: 'e-self')->body['lifecycle']);
		self::assertSame('not-withdrawable', $service->withdraw(learner: $this->learner(), enrolmentId: 'e-hr')->reason);
		self::assertSame('not-withdrawable', $service->withdraw(learner: $this->learner(), enrolmentId: 'e-busy')->reason);
		self::assertSame('not-found', $service->withdraw(learner: $this->learner(), enrolmentId: 'e-other')->reason);

		$byId = array_column($this->enrolments(), null, 'id');
		self::assertSame('withdrawn', $byId['e-self']['lifecycle']);
		self::assertSame('active', $byId['e-hr']['lifecycle']);
	}//end testALearnerWithdrawsOnlyTheirOwnUntouchedSignUp()

	/**
	 * The catalogue shows only published open courses, with the provider and
	 * the learner's own enrolment, and filters on provider and search.
	 *
	 * @return void
	 */
	public function testTheCatalogueListsOpenPublishedEntriesWithTheProvider(): void {
		$this->service();
		$this->store->rows['enrolment'] = [['id' => 'e-1', 'learnerId' => 'p.ganpat', 'courseId' => 'c-lead', 'source' => 'self', 'lifecycle' => 'pending']];
		$reader = new CatalogueReader(objects: $this->createConfiguredStoreDouble());

		$all = $reader->entries(userId: 'p.ganpat');
		self::assertSame(['c-excel', 'c-lead'], array_column($all['courses'], 'id'));
		self::assertSame('Go1', $all['courses'][0]['provider']);
		self::assertSame('pending', $all['courses'][1]['enrolment']['lifecycle']);
		self::assertSame(['p-pm'], array_column($all['programmes'], 'id'));

		self::assertSame(['c-excel'], array_column($reader->entries(userId: 'p.ganpat', filters: ['author' => 'go1'])['courses'], 'id'));
		self::assertSame(['c-lead'], array_column($reader->entries(userId: 'p.ganpat', search: 'hybride')['courses'], 'id'));
	}//end testTheCatalogueListsOpenPublishedEntriesWithTheProvider()

	/**
	 * After a programme sign-up the programme card carries the enrolment, the
	 * way a course card does, so the catalogue stops offering Sign up.
	 *
	 * Reported live: the card said "Done." and still offered "Sign up",
	 * because programme cards never carried an `enrolment` at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-a-track
	 */
	public function testAProgrammeCardCarriesTheSignUpAfterSigningUp(): void {
		$service = $this->service();
		$reader  = new CatalogueReader(objects: $this->createConfiguredStoreDouble());
		self::assertNull($reader->entries(userId: 'p.ganpat')['programmes'][0]['enrolment']);

		$service->signUpProgramme(learner: $this->learner(), programmeId: 'p-pm');

		$card = $reader->entries(userId: 'p.ganpat')['programmes'][0];
		self::assertNotNull($card['enrolment']);
		// c-excel is open (active), c-lead on request: any active one makes the programme active.
		self::assertSame('active', $card['enrolment']['lifecycle']);
		self::assertSame('self', $card['enrolment']['source']);
		self::assertCount(3, $card['enrolment']['ids']);
		self::assertSame(0.0, $card['enrolment']['progressPercent']);
	}//end testAProgrammeCardCarriesTheSignUpAfterSigningUp()

	/**
	 * Only live enrolments naming the programme count; a withdrawn one or a
	 * course enrolment outside the programme leaves the card open, and a
	 * mixed or started set reports what blocks Withdraw.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-a-track
	 */
	public function testAProgrammeCardCountsOnlyLiveEnrolmentsNamingIt(): void {
		$this->service();
		$reader = new CatalogueReader(objects: $this->createConfiguredStoreDouble());

		$this->store->rows['enrolment'] = [
			['id' => 'e-w', 'learnerId' => 'p.ganpat', 'courseId' => 'c-excel', 'programmeId' => 'p-pm', 'source' => 'self', 'lifecycle' => 'withdrawn'],
			['id' => 'e-solo', 'learnerId' => 'p.ganpat', 'courseId' => 'c-lead', 'source' => 'self', 'lifecycle' => 'active'],
		];
		self::assertNull($reader->entries(userId: 'p.ganpat')['programmes'][0]['enrolment']);

		$this->store->rows['enrolment'] = [
			['id' => 'e-a', 'learnerId' => 'p.ganpat', 'courseId' => 'c-excel', 'programmeId' => 'p-pm', 'source' => 'self', 'lifecycle' => 'pending'],
			['id' => 'e-b', 'learnerId' => 'p.ganpat', 'courseId' => 'c-lead', 'programmeId' => 'p-pm', 'source' => 'hr', 'lifecycle' => 'pending', 'progressPercent' => 40],
		];
		$enrolment = $reader->entries(userId: 'p.ganpat')['programmes'][0]['enrolment'];
		self::assertSame('pending', $enrolment['lifecycle']);
		self::assertSame('mixed', $enrolment['source']);
		self::assertSame(40.0, $enrolment['progressPercent']);
		self::assertSame(['e-a', 'e-b'], $enrolment['ids']);
	}//end testAProgrammeCardCountsOnlyLiveEnrolmentsNamingIt()

	/**
	 * An ObjectService double reading the current store.
	 *
	 * @return ObjectService
	 */
	private function createConfiguredStoreDouble(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		return $objects;
	}//end createConfiguredStoreDouble()
}//end class
