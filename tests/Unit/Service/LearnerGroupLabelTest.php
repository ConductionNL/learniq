<?php

/**
 * A pupil's group line: derived, re-stamped after an enrolment change, and
 * queued only when the enrolment moved.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\BackgroundJob\LearnerGroupLabelRestampJob;
use OCA\Learniq\Listener\LearnerGroupLabelCascade;
use OCA\Learniq\Service\LearnerGroupLabel;
use OCA\Learniq\Service\LearnerGroupLabelRestamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ReadableCopies;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * "Groep 7 · Meester Daan" on Vera's profile.
 */
class LearnerGroupLabelTest extends TestCase {

	private const VERA = 'ee010008-0000-4000-8000-000000000415';

	private RegisterFaithfulStore $store;

	/**
	 * The store, with Vera, her old and her current enrolment, and two groups.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'learner-profile' => [
				['id' => self::VERA, 'givenName' => 'Vera', 'roles' => ['learner'], 'groupLabel' => 'Groep 6'],
				['id' => 'guardian', 'givenName' => 'Fatima', 'roles' => ['parent']],
			],
			'enrolment' => [
				['id' => 'e-old', 'learnerRef' => self::VERA, 'cohortId' => 'g6', 'cohortName' => 'Groep 6', 'lifecycle' => 'completed', 'inschrijvingDate' => '2025-08-18'],
				['id' => 'e-now', 'learnerRef' => self::VERA, 'cohortId' => 'g7', 'cohortName' => 'Groep 7', 'lifecycle' => 'active', 'inschrijvingDate' => '2026-08-17'],
			],
			'cohort' => [
				['id' => 'g6', 'name' => 'Groep 6', 'teacherIds' => ['po-leerkracht-05']],
				['id' => 'g7', 'name' => 'Groep 7', 'teacherIds' => ['po-leerkracht-09']],
			],
		];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);
		return $objectService;
	}//end objects()

	/**
	 * A user manager that knows Meester Daan; everyone else reads as their uid.
	 *
	 * @return IUserManager
	 */
	private function users(): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): string => ($uid === 'po-leerkracht-09' ? 'Meester Daan' : $uid));
		return $users;
	}//end users()

	/**
	 * The line names the active group and its teacher; a guardian and a profile without id get none.
	 *
	 * @return void
	 */
	public function testTheLineNamesTheActiveGroupAndItsTeacher(): void {
		$label = new LearnerGroupLabel(objectService: $this->objects(), users: $this->users());

		self::assertSame('Groep 7 · Meester Daan', $label->derive(['id' => self::VERA, 'roles' => ['learner']]));
		self::assertNull($label->derive(['id' => 'guardian', 'roles' => ['parent']]));
		self::assertNull($label->derive(['roles' => ['learner']]));

		// A teacher without a display name of their own is left out, never shown as a user id.
		$this->store->rows['cohort'][1]['teacherIds'] = ['po-leerkracht-10'];
		self::assertSame('Groep 7', $label->derive(['id' => self::VERA, 'roles' => ['learner']]));
	}//end testTheLineNamesTheActiveGroupAndItsTeacher()

	/**
	 * The readable-copy stamp carries the line for a learner profile.
	 *
	 * @return void
	 */
	public function testTheReadableCopiesCoverTheLearnerProfile(): void {
		$copies = new ReadableCopies(objectService: $this->objects(), users: $this->users());

		self::assertTrue($copies->covers('learner-profile'));
		self::assertSame(['groupLabel' => 'Groep 7 · Meester Daan', 'fullName' => null], $copies->derive('learner-profile', ['id' => self::VERA, 'roles' => ['learner']]));
	}//end testTheReadableCopiesCoverTheLearnerProfile()

	/**
	 * The re-stamp writes a moved line once, and nothing when it already holds.
	 *
	 * @return void
	 */
	public function testTheRestampWritesOnlyAMovedLine(): void {
		$restamp = new LearnerGroupLabelRestamp(objectService: $this->objects(), users: $this->users(), logger: new NullLogger());

		self::assertTrue($restamp->restamp(self::VERA));
		$vera = array_values(array_filter($this->store->rows['learner-profile'], static fn (array $r): bool => ($r['id'] ?? '') === self::VERA))[0];
		self::assertSame('Groep 7 · Meester Daan', $vera['groupLabel']);
		self::assertSame('Vera', $vera['givenName'], 'the rest of the profile is kept');
		self::assertFalse($restamp->restamp(self::VERA), 'a line that already holds is not written again');
	}//end testTheRestampWritesOnlyAMovedLine()

	/**
	 * A new enrolment or a move queues the pupil once; an unrelated edit queues nothing.
	 *
	 * @return void
	 */
	public function testTheCascadeQueuesOnlyWhenTheEnrolmentMoved(): void {
		$deferred = [];
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize = 50, ?string $dedupeKey = null) use (&$deferred): void {
				$deferred[] = [$jobClass, $entry, $dedupeKey];
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('enrolment');
		$cascade = new LearnerGroupLabelCascade(schemaResolver: $resolver, deferral: $deferral);

		$before = ['learnerRef' => self::VERA, 'cohortId' => 'g6', 'cohortName' => 'Groep 6', 'lifecycle' => 'active', 'volgnummer' => 3];
		$cascade->handle(new ObjectCreatedEvent(OrEntityFactory::make($before, 'enrolment')));
		$cascade->handle(new ObjectUpdatedEvent(OrEntityFactory::make(array_merge($before, ['volgnummer' => 4]), 'enrolment'), OrEntityFactory::make($before, 'enrolment')));
		$cascade->handle(new ObjectUpdatedEvent(OrEntityFactory::make(array_merge($before, ['cohortId' => 'g7']), 'enrolment'), OrEntityFactory::make($before, 'enrolment')));

		self::assertSame(
			[
				[LearnerGroupLabelRestampJob::class, ['learnerRef' => self::VERA], 'learner-group-label:' . self::VERA],
				[LearnerGroupLabelRestampJob::class, ['learnerRef' => self::VERA], 'learner-group-label:' . self::VERA],
			],
			$deferred
		);
	}//end testTheCascadeQueuesOnlyWhenTheEnrolmentMoved()
}//end class
