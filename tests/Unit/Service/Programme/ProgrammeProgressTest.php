<?php

/**
 * Programme progress counts the mandatory parts only.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Programme
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
 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#requirement-progress-counts-mandatory-parts
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Programme;

use OCA\Learniq\Service\Programme\ProgrammeProgress;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProgrammeProgress.
 */
class ProgrammeProgressTest extends TestCase {

	/**
	 * The store behind the ObjectService double.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * A service over a store holding the programme Safety basics.
	 *
	 * @return ProgrammeProgress
	 */
	private function service(): ProgrammeProgress {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['programme'] = [
			['id' => 'p-safety', 'name' => 'Safety basics', 'courseIds' => ['c-intro', 'c-rules', 'c-tour'], 'mandatoryCourseIds' => ['c-intro', 'c-rules']],
			['id' => 'p-other', 'name' => 'Other track', 'courseIds' => ['c-x']],
		];
		$this->store->rows['course'] = [
			['id' => 'c-intro', 'name' => 'Introduction'],
			['id' => 'c-rules', 'name' => 'Site rules'],
			['id' => 'c-tour', 'name' => 'Site tour'],
		];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
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

		return new ProgrammeProgress(objects: $objects);
	}//end service()

	/**
	 * One enrolment row of the learner.
	 *
	 * @param string $id        Enrolment id.
	 * @param string $learner   Learner user id.
	 * @param string $courseId  Course id.
	 * @param bool   $mandatory The per-person flag.
	 * @param string $lifecycle Lifecycle state.
	 *
	 * @return array<string, mixed>
	 */
	private static function enrolment(string $id, string $learner, string $courseId, bool $mandatory, string $lifecycle): array {
		return ['id' => $id, 'learnerId' => $learner, 'courseId' => $courseId, 'programmeId' => 'p-safety', 'mandatory' => $mandatory, 'lifecycle' => $lifecycle];
	}//end enrolment()

	/**
	 * Both mandatory parts completed, the optional one not started: 100
	 * percent, complete, the optional course listed as optional.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#scenario-optional-parts-do-not-block-completion
	 */
	public function testOptionalPartsDoNotBlockCompletion(): void {
		$service = $this->service();
		$this->store->rows['enrolment'] = [
			self::enrolment('e1', 'jan', 'c-intro', true, 'completed'),
			self::enrolment('e2', 'jan', 'c-rules', true, 'completed'),
			self::enrolment('e3', 'jan', 'c-tour', false, 'active'),
		];

		$progress = $service->forLearner(userId: 'jan');

		self::assertCount(1, $progress);
		self::assertSame('p-safety', $progress[0]['programmeId']);
		self::assertSame('Safety basics', $progress[0]['name']);
		self::assertSame(2, $progress[0]['mandatoryTotal']);
		self::assertSame(2, $progress[0]['mandatoryCompleted']);
		self::assertSame(100, $progress[0]['percent']);
		self::assertTrue($progress[0]['complete']);
		self::assertSame(['Introduction', 'Site rules'], array_column($progress[0]['mandatory'], 'courseName'));
		self::assertSame(['c-tour'], array_column($progress[0]['optional'], 'courseId'));
		self::assertSame('Site tour', $progress[0]['optional'][0]['courseName']);
	}//end testOptionalPartsDoNotBlockCompletion()

	/**
	 * The truth is the person's enrolment, not the programme default: a part
	 * a manager made mandatory for this person counts for this person only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#scenario-a-manager-makes-a-part-mandatory-for-one-person
	 */
	public function testAPartMadeMandatoryForOnePersonCountsForThatPersonOnly(): void {
		$service = $this->service();
		$this->store->rows['enrolment'] = [
			self::enrolment('e1', 'jan', 'c-intro', true, 'completed'),
			self::enrolment('e2', 'jan', 'c-rules', true, 'completed'),
			self::enrolment('e3', 'jan', 'c-tour', true, 'active'),
			self::enrolment('e4', 'piet', 'c-intro', true, 'completed'),
			self::enrolment('e5', 'piet', 'c-rules', true, 'completed'),
			self::enrolment('e6', 'piet', 'c-tour', false, 'pending'),
		];

		$jan = $service->forLearner(userId: 'jan')[0];
		$piet = $service->forLearner(userId: 'piet')[0];

		self::assertSame([3, 2, 67, false], [$jan['mandatoryTotal'], $jan['mandatoryCompleted'], $jan['percent'], $jan['complete']]);
		self::assertSame([], $jan['optional']);
		self::assertSame([2, 2, 100, true], [$piet['mandatoryTotal'], $piet['mandatoryCompleted'], $piet['percent'], $piet['complete']]);
	}//end testAPartMadeMandatoryForOnePersonCountsForThatPersonOnly()

	/**
	 * A programme with no mandatory part at all (every existing programme,
	 * design D2) counts every part, so its progress is not 100 percent from
	 * the start; a withdrawn part counts nowhere.
	 *
	 * @return void
	 */
	public function testAProgrammeWithoutMandatoryPartsCountsEveryPart(): void {
		$service = $this->service();
		$this->store->rows['enrolment'] = [
			self::enrolment('e1', 'jan', 'c-intro', false, 'completed'),
			self::enrolment('e2', 'jan', 'c-rules', false, 'active'),
			self::enrolment('e3', 'jan', 'c-tour', false, 'withdrawn'),
		];

		$progress = $service->forLearner(userId: 'jan')[0];

		self::assertSame([2, 1, 50, false], [$progress['mandatoryTotal'], $progress['mandatoryCompleted'], $progress['percent'], $progress['complete']]);
		self::assertSame([], $progress['optional']);
	}//end testAProgrammeWithoutMandatoryPartsCountsEveryPart()

	/**
	 * Enrolments outside a programme, and other people's, are not read into
	 * the learner's programme list; no user id reads nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheLearnersProgrammeEnrolmentsCount(): void {
		$service = $this->service();
		$this->store->rows['enrolment'] = [
			['id' => 'e1', 'learnerId' => 'jan', 'courseId' => 'c-intro', 'mandatory' => true, 'lifecycle' => 'active'],
			self::enrolment('e2', 'piet', 'c-intro', true, 'active'),
		];

		self::assertSame([], $service->forLearner(userId: 'jan'));
		self::assertSame([], $service->forLearner(userId: ''));
	}//end testOnlyTheLearnersProgrammeEnrolmentsCount()

	/**
	 * A part whose course or programme was deleted still counts: the course
	 * shows by its id and the programme without a name; a part with no
	 * course id shows no name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#scenario-optional-parts-do-not-block-completion
	 */
	public function testMissingCoursesAndProgrammesStillCount(): void {
		$service = $this->service();

		$gone = self::enrolment('e-gone', 'anna', 'c-gone', true, 'completed');
		$gone['programmeId'] = 'p-gone';
		$this->store->rows['enrolment'] = [$gone, self::enrolment('e-empty', 'anna', '', true, 'active')];

		$summaries = $service->forLearner(userId: 'anna');
		$byId = array_column($summaries, null, 'programmeId');
		self::assertSame('', $byId['p-gone']['name']);
		self::assertSame('c-gone', $byId['p-gone']['mandatory'][0]['courseName']);
		self::assertSame(100, $byId['p-gone']['percent']);
		self::assertSame('', $byId['p-safety']['mandatory'][0]['courseName']);
		self::assertSame(0, $byId['p-safety']['percent']);
	}//end testMissingCoursesAndProgrammesStillCount()

	/**
	 * A result row that is neither an object nor an array is skipped, not
	 * read as a part.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#scenario-optional-parts-do-not-block-completion
	 */
	public function testARowThatIsNoObjectIsSkipped(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn(['not a row', self::enrolment('e1', 'jan', '', true, 'completed')]);
		$objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$progress = (new ProgrammeProgress(objects: $objects))->forLearner(userId: 'jan');

		self::assertCount(1, $progress);
		self::assertSame([1, 1, 100, true], [$progress[0]['mandatoryTotal'], $progress[0]['mandatoryCompleted'], $progress[0]['percent'], $progress[0]['complete']]);
	}//end testARowThatIsNoObjectIsSkipped()
}//end class
