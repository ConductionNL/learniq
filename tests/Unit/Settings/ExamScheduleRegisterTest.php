<?php

/**
 * Learniq exam schedule unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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

namespace OCA\Learniq\Tests\Unit\Settings;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The exam schedule schemas: who reads and writes, relations, and the notification.
 *
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-exam-periods-and-sittings
 */
class ExamScheduleRegisterTest extends TestCase {

	private const PLANNERS = ['instructors', 'hr', 'compliance-officers', 'team-leads'];

	/**
	 * Schemas keyed by component name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas;

	/**
	 * Load the shipped register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemas = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true)['components']['schemas'];
	}//end setUp()

	/**
	 * Every schema exists with its slug, and learners and guardians appear in no authorization entry.
	 *
	 * @return void
	 */
	public function testTheFourSchemasShipWithoutLearnerAccess(): void {
		$slugs = ['ExamPeriod' => 'exam-period', 'ExamSitting' => 'exam-sitting', 'InvigilatorAvailability' => 'invigilator-availability', 'InvigilatorAssignment' => 'invigilator-assignment'];
		foreach ($slugs as $name => $slug) {
			self::assertSame($slug, $this->schemas[$name]['slug']);
			$json = json_encode($this->schemas[$name]['authorization']);
			self::assertStringNotContainsString('"learners"', $json, $name);
			self::assertStringNotContainsString('"guardians"', $json, $name);
			self::assertSame(self::PLANNERS, $this->schemas[$name]['authorization']['create'], $name);
		}
	}//end testTheFourSchemasShipWithoutLearnerAccess()

	/**
	 * Relations use the one dialect and point at schemas that exist.
	 *
	 * @return void
	 */
	public function testRelationsResolve(): void {
		$sitting = $this->schemas['ExamSitting']['properties'];
		self::assertSame('ExamPeriod', $sitting['examPeriodId']['$ref']);
		self::assertSame('Assessment', $sitting['assessmentId']['$ref']);
		self::assertSame('Room', $sitting['roomIds']['items']['$ref']);
		self::assertSame('Cohort', $sitting['cohortIds']['items']['$ref']);
		self::assertSame('ExamSitting', $this->schemas['InvigilatorAssignment']['properties']['examSittingId']['$ref']);
		foreach (['ExamPeriod', 'Assessment', 'Room', 'Cohort', 'ExamSitting'] as $target) {
			self::assertArrayHasKey($target, $this->schemas);
		}
	}//end testRelationsResolve()

	/**
	 * The invigilator reads and answers their own request, and is told when asked.
	 *
	 * @return void
	 */
	public function testTheInvigilatorReadsTheirOwnRequestAndIsNotified(): void {
		$assignment = $this->schemas['InvigilatorAssignment'];
		$own = ['group' => 'authenticated', 'match' => ['invigilatorId' => '$userId']];
		self::assertContains($own, $assignment['authorization']['read']);
		self::assertContains($own, $assignment['authorization']['update']);

		$notice = $assignment['x-openregister-notifications']['invigilationRequested'];
		self::assertSame('created', $notice['trigger']['type']);
		self::assertSame([['kind' => 'field', 'field' => 'invigilatorId']], $notice['recipients']);
	}//end testTheInvigilatorReadsTheirOwnRequestAndIsNotified()

	/**
	 * The clash list is server-owned, and a sitting needs at least one room.
	 *
	 * @return void
	 */
	public function testClashesAreReadOnlyAndARoomIsRequired(): void {
		$sitting = $this->schemas['ExamSitting'];
		self::assertTrue($sitting['properties']['clashWarnings']['readOnly']);
		self::assertSame(1, $sitting['properties']['roomIds']['minItems']);
		self::assertContains('roomIds', $sitting['required']);
		self::assertContains('headcount', $sitting['required']);
	}//end testClashesAreReadOnlyAndARoomIsRequired()
	/**
	 * The payloads a planner and the listeners write pass the shipped schema, the way OpenRegister validates a write.
	 *
	 * @return void
	 */
	public function testTheWrittenPayloadsPassTheRealSchema(): void {
		$uuid = static fn (int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
		$payloads = [
			'ExamPeriod' => ['name' => 'Toetsweek 2', 'startsOn' => '2026-10-05', 'endsOn' => '2026-10-09', 'cohortIds' => [$uuid(1)], 'courseIds' => [], 'lifecycle' => 'planning', 'tenant_id' => $uuid(9)],
			'ExamSitting' => ['examPeriodId' => $uuid(2), 'assessmentId' => $uuid(3), 'cohortIds' => [$uuid(1)], 'startsAt' => '2026-10-06T09:00:00+00:00', 'endsAt' => '2026-10-06T10:30:00+00:00', 'roomIds' => [$uuid(4)], 'headcount' => 28, 'invigilatorsNeeded' => 1, 'clashWarnings' => ['Dutch 4B (2026-10-06T09:30:00+00:00 - 2026-10-06T10:20:00+00:00)'], 'lifecycle' => 'planned', 'tenant_id' => $uuid(9)],
			'InvigilatorAvailability' => ['invigilatorId' => 'inv-1', 'examPeriodId' => $uuid(2), 'availableFrom' => '2026-10-06T08:00:00+00:00', 'availableUntil' => '2026-10-06T12:00:00+00:00', 'tenant_id' => $uuid(9)],
			'InvigilatorAssignment' => ['examSittingId' => $uuid(5), 'invigilatorId' => 'inv-1', 'lifecycle' => 'pending', 'tenant_id' => $uuid(9)],
		];
		$validator = new Validator();

		foreach ($payloads as $name => $payload) {
			$schema = json_encode($this->validatable(schema: $this->schemas[$name]));
			$result = $validator->validate(json_decode((string)json_encode($payload)), $schema);
			self::assertTrue($result->isValid(), $name . ': ' . json_encode($result->error()?->message()));
		}

		$tooFewRooms = $payloads['ExamSitting'];
		$tooFewRooms['roomIds'] = [];
		self::assertFalse($validator->validate(json_decode((string)json_encode($tooFewRooms)), json_encode($this->validatable(schema: $this->schemas['ExamSitting'])))->isValid(), 'control: a sitting without a room is refused');
	}//end testTheWrittenPayloadsPassTheRealSchema()

	/**
	 * The schema without OpenRegister's own keys, which a JSON Schema validator cannot resolve.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private function validatable(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'authorization' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = $this->validatable(schema: $value);
			}
		}

		return $clean;
	}//end validatable()
}//end class
