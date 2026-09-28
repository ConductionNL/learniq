<?php

/**
 * The training institute example set keeps its promises.
 *
 * ExampleSetDescriptorContractTest proves the file loads (shape, uuids,
 * references, schema validity). This test proves the story is consistent: a
 * mark sits on a session of an edition the participant is enrolled in and is
 * made by the trainer who taught it, no trainer or room is in two places at
 * once, a certificate only follows an edition attended in full and a passed
 * knowledge test, the evaluations add up to the quality scores, the waiting
 * list and the intake agree with the enrolments, and the removal list covers
 * every object once.
 *
 * Rows are found by name, slug or uuid, and counts are floors.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use DateTimeImmutable;
use OCA\Learniq\Service\DemoDataService;
use OCA\Learniq\Service\QtiChoiceOrderResolver;
use OCA\Learniq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Content and consistency of lib/Settings/profiles/training.json.
 */
class TrainingExampleSetTest extends TestCase {

	/**
	 * The decoded set, per schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>|null
	 */
	private static ?array $objects = null;

	/**
	 * The set's objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function of(string $schema): array {
		if (self::$objects === null) {
			$set = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/training.json'), true);
			self::$objects = $set['x-openregister']['seedData']['objects'];
		}

		return (self::$objects[$schema] ?? []);
	}//end of()

	/**
	 * Index a list of objects by one field.
	 *
	 * @param array<int, array<string, mixed>> $rows  The objects.
	 * @param string                           $field The field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function by(array $rows, string $field): array {
		$index = [];
		foreach ($rows as $row) {
			$index[(string)$row[$field]] = $row;
		}

		return $index;
	}//end by()

	/**
	 * One object of a schema, found by its name.
	 *
	 * @param string $schema The schema slug.
	 * @param string $name   The value of the `name` field.
	 *
	 * @return array<string, mixed>
	 */
	private static function named(string $schema, string $name): array {
		$found = array_values(array_filter(self::of($schema), static fn (array $row): bool => ($row['name'] ?? null) === $name));
		self::assertNotEmpty($found, $schema . ' "' . $name . '" is in the set');

		return $found[0];
	}//end named()

	/**
	 * The participants: every profile whose only role is learner.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function participants(): array {
		return array_values(array_filter(self::of('learner-profile'), static fn (array $p): bool => $p['roles'] === ['learner']));
	}//end participants()

	/**
	 * The first session start of each cohort, cancelled sessions excluded.
	 *
	 * @return array<string, string> Cohort uuid => ISO start.
	 */
	private static function cohortStarts(): array {
		$starts = [];
		foreach (self::of('session') as $session) {
			if ($session['lifecycle'] === 'cancelled') {
				continue;
			}

			$cohort = $session['cohortId'];
			if (isset($starts[$cohort]) === false || $session['startsAt'] < $starts[$cohort]) {
				$starts[$cohort] = $session['startsAt'];
			}
		}

		return $starts;
	}//end cohortStarts()

	/**
	 * One institute with one location, a published catalogue with prices,
	 * about 150 adult participants from several client companies, each
	 * enrolled in an edition of a course, and trainers on the staff.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-training-institute-set-is-one-consistent-institute
	 */
	public function testTheInstituteHasItsPromisedShape(): void {
		self::assertCount(1, self::of('school'));
		self::assertMatchesRegularExpression('/^[0-9]{2}[A-Za-z0-9][0-9]$/', self::of('school')[0]['brin'], 'the code ends in a digit, which DUO never assigns');
		self::assertCount(1, self::of('vestiging'));
		self::assertGreaterThanOrEqual(5, count(self::of('room')));

		$courses   = self::by(self::of('course'), 'uuid');
		$published = array_filter($courses, static fn (array $c): bool => $c['lifecycle'] === 'published');
		self::assertGreaterThanOrEqual(12, count($published));
		$priced = 0;
		foreach (self::of('fee-item') as $fee) {
			if (($fee['linkedCourseId'] ?? null) !== null) {
				self::assertSame('published', $courses[$fee['linkedCourseId']]['lifecycle'], $fee['slug'] . ' prices a published course');
				$priced++;
			}
		}

		self::assertGreaterThanOrEqual(12, $priced);

		$participants = self::participants();
		self::assertGreaterThanOrEqual(130, count($participants));
		self::assertLessThanOrEqual(170, count($participants));
		$enrolments = [];
		foreach (self::of('enrolment') as $enrolment) {
			self::assertNotEmpty($enrolment['cohortId'], $enrolment['slug'] . ' sits in an edition');
			self::assertArrayHasKey($enrolment['courseId'], $courses);
			$enrolments[$enrolment['learnerId']] = true;
		}

		$companies = [];
		foreach ($participants as $participant) {
			self::assertArrayHasKey($participant['ncUserId'], $enrolments, $participant['ncUserId'] . ' is enrolled');
			self::assertSame([], $participant['guardianRefs'], 'participants are adults without guardians');
			self::assertSame([], $participant['parentIds']);
			self::assertArrayNotHasKey('bsnEncrypted', $participant);
			self::assertArrayNotHasKey('eckId', $participant);
			if ($participant['department'] !== 'Particulier') {
				$companies[explode('/', $participant['department'])[0]] = true;
			}
		}

		self::assertGreaterThanOrEqual(5, count($companies), 'participants come from several client companies');
		self::assertSame([], array_filter(self::of('learner-profile'), static fn (array $p): bool => in_array('parent', $p['roles'], true)));

		$trainers = array_filter(self::of('staff'), static fn (array $s): bool => in_array('teacher', $s['roles'], true));
		self::assertGreaterThanOrEqual(6, count($trainers));
	}//end testTheInstituteHasItsPromisedShape()

	/**
	 * Every mark sits on a held session of an edition the participant is
	 * enrolled in for that course, and is made by the trainer who taught it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-training-institute-set-is-one-consistent-institute
	 */
	public function testEveryMarkBelongsToAnEnrolledParticipantAndTheTrainerOnDuty(): void {
		$sessions = self::by(self::of('session'), 'uuid');
		$teacher  = [];
		foreach (self::of('subjectteacherassignment') as $assignment) {
			$teacher[$assignment['cohortId'] . '|' . $assignment['courseId']] = $assignment['teacherId'];
		}

		$enrolled = [];
		foreach (self::of('enrolment') as $enrolment) {
			$enrolled[$enrolment['learnerId'] . '|' . $enrolment['cohortId'] . '|' . $enrolment['courseId']] = true;
		}

		self::assertGreaterThan(1000, count(self::of('attendance-record')));
		foreach (self::of('attendance-record') as $mark) {
			$session = $sessions[$mark['sessionId']];
			self::assertSame('completed', $session['lifecycle'], $mark['slug'] . ' sits on a session that was held');
			self::assertSame($session['cohortId'], $mark['cohortId'], $mark['slug']);
			self::assertArrayHasKey($mark['learnerId'] . '|' . $session['cohortId'] . '|' . $session['courseId'], $enrolled, $mark['slug'] . ' is for an enrolled participant');
			$onDuty = ($session['substituteTeacherId'] ?? $teacher[$session['cohortId'] . '|' . $session['courseId']]);
			self::assertSame($onDuty, $mark['markedBy'], $mark['slug'] . ' is marked by the trainer on duty');
			if ($mark['status'] === 'absent-excused') {
				self::assertNotNull($mark['excuseRequestId'], $mark['slug'] . ' names the excuse it rests on');
			}
		}
	}//end testEveryMarkBelongsToAnEnrolledParticipantAndTheTrainerOnDuty()

	/**
	 * No trainer and no room is booked twice at the same time, every session
	 * falls on a weekday of the school year, and a cancelled session carries
	 * its reason and no marks.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-training-institute-set-is-one-consistent-institute
	 */
	public function testNoTrainerOrRoomIsInTwoPlacesAtOnce(): void {
		$teacher = [];
		foreach (self::of('subjectteacherassignment') as $assignment) {
			$teacher[$assignment['cohortId'] . '|' . $assignment['courseId']] = $assignment['teacherId'];
		}

		$booked    = [];
		$cancelled = [];
		foreach (self::of('session') as $session) {
			$start = new DateTimeImmutable($session['startsAt']);
			self::assertLessThan(6, (int)$start->format('N'), $session['title'] . ' is on a weekday');
			self::assertGreaterThanOrEqual('2025-08-18', $start->format('Y-m-d'));
			self::assertLessThanOrEqual('2026-07-10', $start->format('Y-m-d'));
			if ($session['lifecycle'] === 'cancelled') {
				self::assertNotEmpty($session['changeReasonKind'], $session['title'] . ' says why it was cancelled');
				$cancelled[$session['uuid']] = true;
				continue;
			}

			$trainer = ($session['substituteTeacherId'] ?? $teacher[$session['cohortId'] . '|' . $session['courseId']]);
			foreach (['trainer ' . $trainer, 'room ' . $session['roomId']] as $resource) {
				$key = $resource . '@' . $session['startsAt'];
				self::assertArrayNotHasKey($key, $booked, $session['title'] . ' double-books ' . $resource . ' with ' . ($booked[$key] ?? ''));
				$booked[$key] = $session['title'];
			}
		}

		self::assertNotEmpty($cancelled, 'the storm story cancels a session');
		foreach (self::of('attendance-record') as $mark) {
			self::assertArrayNotHasKey($mark['sessionId'], $cancelled, $mark['slug'] . ' is not on a cancelled session');
		}
	}//end testNoTrainerOrRoomIsInTwoPlacesAtOnce()

	/**
	 * A certificate follows only a completed enrolment; a regulated course is
	 * completed only when every session was attended; a tested course only
	 * after a passed attempt; withdrawn and failed enrolments get nothing; a
	 * completed regulated course carries a signed attestation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-certificates-evaluations-and-waiting-lists-agree-with-what-happened
	 */
	public function testCertificatesFollowAttendanceAndTests(): void {
		$enrolments = self::by(self::of('enrolment'), 'uuid');
		$courses    = self::by(self::of('course'), 'uuid');
		$awarded    = [];
		foreach (self::of('credential') as $credential) {
			if ($credential['source'] !== 'auto') {
				continue;
			}

			$enrolment = $enrolments[$credential['enrolmentId']];
			self::assertSame('completed', $enrolment['lifecycle'], $credential['slug'] . ' follows a completed enrolment');
			self::assertSame($enrolment['courseId'], $credential['courseId']);
			self::assertSame($enrolment['learnerRef'], $credential['learnerId']);
			$awarded[$enrolment['uuid']] = true;
		}

		$absent = [];
		foreach (self::of('attendance-record') as $mark) {
			if (str_starts_with($mark['status'], 'absent') === true) {
				$absent[$mark['learnerId'] . '|' . $mark['cohortId']] = true;
			}
		}

		$exams = [];
		foreach (self::of('exam') as $exam) {
			$exams[$exam['cohortId'] . '|' . $exam['courseId']] = $exam;
		}

		$scores = [];
		foreach (self::of('assessment-result') as $result) {
			$scores[$result['assessmentId'] . '|' . $result['learnerId']][] = array_sum(array_column($result['responses'], 'autoScore'));
		}

		$attested = [];
		foreach (self::of('attestation') as $attestation) {
			self::assertSame('signed', $attestation['lifecycle']);
			$attested[$attestation['learnerId'] . '|' . $attestation['courseId']] = true;
		}

		self::assertGreaterThan(300, count($awarded));
		foreach ($enrolments as $uuid => $enrolment) {
			$regulated = (($courses[$enrolment['courseId']]['regulationSlug'] ?? null) !== null);
			$exam      = ($exams[$enrolment['cohortId'] . '|' . $enrolment['courseId']] ?? null);
			$tried     = ($exam === null ? [] : ($scores[$exam['uuid'] . '|' . $enrolment['learnerId']] ?? []));
			if ($enrolment['lifecycle'] !== 'completed') {
				self::assertArrayNotHasKey($uuid, $awarded, $enrolment['slug'] . ' earned no certificate');
				self::assertNotEmpty($enrolment['reason'], $enrolment['slug'] . ' says why');
				if ($enrolment['lifecycle'] === 'failed') {
					self::assertNotEmpty($tried);
					self::assertLessThan($exam['passMark'], max($tried), $enrolment['slug'] . ' failed every attempt');
				}

				continue;
			}

			self::assertArrayHasKey($uuid, $awarded, $enrolment['slug'] . ' earned its certificate or badge');
			if ($regulated === true) {
				self::assertArrayNotHasKey($enrolment['learnerId'] . '|' . $enrolment['cohortId'], $absent, $enrolment['slug'] . ' attended every session');
				self::assertArrayHasKey($enrolment['learnerId'] . '|' . $enrolment['courseId'], $attested, $enrolment['slug'] . ' is attested');
			}

			if ($exam !== null) {
				self::assertGreaterThanOrEqual($exam['passMark'], max($tried), $enrolment['slug'] . ' passed the knowledge test');
				self::assertLessThanOrEqual($exam['maxAttempts'], count($tried));
			}
		}//end foreach
	}//end testCertificatesFollowAttendanceAndTests()

	/**
	 * A participant who missed an edition is rebooked into a later edition
	 * of the same course, and last year's migrated certificates expired
	 * before the renewal edition they led to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-certificates-evaluations-and-waiting-lists-agree-with-what-happened
	 */
	public function testRebookingsAndRenewalsCloseTheLoop(): void {
		$starts     = self::cohortStarts();
		$enrolments = self::by(self::of('enrolment'), 'uuid');
		$rebooked   = 0;
		foreach ($enrolments as $enrolment) {
			if ($enrolment['lifecycle'] !== 'withdrawn' || str_contains((string)$enrolment['reason'], 'omgeboekt') === false) {
				continue;
			}

			$later = array_filter(
				$enrolments,
				static fn (array $e): bool => $e['learnerId'] === $enrolment['learnerId'] && $e['courseId'] === $enrolment['courseId']
					&& $starts[$e['cohortId']] > $starts[$enrolment['cohortId']]
			);
			self::assertNotEmpty($later, $enrolment['slug'] . ' has its later edition');
			$rebooked++;
		}

		self::assertGreaterThan(0, $rebooked, 'the set shows at least one rebooking');

		$renewals = 0;
		foreach (self::of('credential') as $credential) {
			if ($credential['source'] !== 'migrated') {
				continue;
			}

			self::assertSame('expired', $credential['lifecycle']);
			$renewal = $enrolments[$credential['renewalEnrolmentId']];
			self::assertSame('credential-renewal', $renewal['source']);
			self::assertSame($credential['learnerId'], $renewal['learnerRef']);
			self::assertLessThan(new DateTimeImmutable($starts[$renewal['cohortId']]), new DateTimeImmutable($credential['expiresAt']), $credential['slug'] . ' expired before its renewal edition');
			$renewals++;
		}

		self::assertGreaterThanOrEqual(30, $renewals);
	}//end testRebookingsAndRenewalsCloseTheLoop()

	/**
	 * The leadership programme fills each start up to its capacity from
	 * converted applications, keeps a waiting list, and every conversion
	 * points at the participant and the enrolments it created.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-certificates-evaluations-and-waiting-lists-agree-with-what-happened
	 */
	public function testTheWaitingListAndTheIntakeAgreeWithTheEnrolments(): void {
		$profiles   = self::by(self::of('learner-profile'), 'uuid');
		$enrolments = self::by(self::of('enrolment'), 'uuid');
		$rounds     = self::by(self::of('admissions-round'), 'uuid');
		$placed     = [];
		$waiting    = 0;
		foreach (self::of('admission') as $application) {
			if ($application['lifecycle'] === 'waitlisted') {
				self::assertSame('waitlisted', $application['decisionType']);
				$waiting++;
			}

			if ($application['lifecycle'] !== 'converted') {
				continue;
			}

			$placed[$application['admissionsRoundId']] = (($placed[$application['admissionsRoundId']] ?? 0) + 1);
			$profile = $profiles[$application['convertedLearnerProfileId']];
			self::assertSame($profile['givenName'], $application['applicantGivenName']);
			self::assertNotEmpty($application['convertedEnrolmentIds']);
			foreach ($application['convertedEnrolmentIds'] as $uuid) {
				self::assertSame('admission', $enrolments[$uuid]['source']);
				self::assertSame($profile['uuid'], $enrolments[$uuid]['learnerRef']);
			}
		}

		self::assertGreaterThanOrEqual(3, $waiting, 'the programme keeps a waiting list');
		self::assertNotEmpty($placed);
		foreach ($placed as $round => $count) {
			self::assertLessThanOrEqual($rounds[$round]['capacity'], $count, $rounds[$round]['name'] . ' stays within capacity');
		}

		self::assertSame('open', self::named('admissions-round', 'Leergang Leidinggeven, start september 2026')['lifecycle']);
	}//end testTheWaitingListAndTheIntakeAgreeWithTheEnrolments()

	/**
	 * Responses match the invitations that say they responded, the quality
	 * scores count exactly those, and a course with an improvement action
	 * scores higher in the quarter the action targeted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-certificates-evaluations-and-waiting-lists-agree-with-what-happened
	 */
	public function testEvaluationsAddUpToTheQualityScores(): void {
		$responded = [];
		$invited   = [];
		foreach (self::of('evaluation-invitation') as $invitation) {
			$scope           = $invitation['courseId'] . '|' . $invitation['period'];
			$invited[$scope] = (($invited[$scope] ?? 0) + 1);
			if ($invitation['hasResponded'] === true) {
				$key             = $invitation['campaignId'] . '|' . $invitation['courseId'] . '|' . $invitation['cohortId'];
				$responded[$key] = (($responded[$key] ?? 0) + 1);
			}
		}

		$answered = [];
		$overall  = [];
		foreach (self::of('course-evaluation-response') as $response) {
			$key            = $response['campaignId'] . '|' . $response['courseId'] . '|' . $response['cohortId'];
			$answered[$key] = (($answered[$key] ?? 0) + 1);
			$overall[$response['courseId'] . '|' . $response['period']][] = $response['overallScore'];
			self::assertArrayNotHasKey('learnerId', $response, 'a response names nobody');
		}

		ksort($responded);
		ksort($answered);
		self::assertSame($responded, $answered);

		$quality = [];
		foreach (self::of('course-quality-score') as $score) {
			$scope = $score['courseId'] . '|' . $score['period'];
			self::assertSame($invited[$scope], $score['invitationCount'], $score['slug']);
			self::assertSame(count($overall[$scope] ?? []), $score['responseCount'], $score['slug']);
			if ($score['responseCount'] > 0) {
				self::assertEqualsWithDelta(array_sum($overall[$scope]) / count($overall[$scope]), $score['averageOverallScore'], 0.01, $score['slug']);
			}

			$quality[$scope] = $score;
		}

		$campaigns = self::by(self::of('evaluation-campaign'), 'uuid');
		self::assertGreaterThanOrEqual(2, count(self::of('improvement-action')));
		foreach (self::of('improvement-action') as $action) {
			$period = $campaigns[$action['campaignId']]['period'];
			self::assertArrayHasKey($action['courseId'] . '|' . $period, $quality, $action['slug'] . ' answers a measured score');
		}

		foreach (['Werken met spreadsheets, gevorderd', 'Heftruckchauffeur'] as $name) {
			$course = self::named('course', $name)['uuid'];
			self::assertGreaterThan(
				$quality[$course . '|Kwartaal 1']['averageOverallScore'],
				$quality[$course . '|Kwartaal 2']['averageOverallScore'],
				$name . ' scores higher after its improvement action'
			);
		}
	}//end testEvaluationsAddUpToTheQualityScores()

	/**
	 * The package imports and the export round trips report every resource,
	 * and their counts match their entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-certificates-evaluations-and-waiting-lists-agree-with-what-happened
	 */
	public function testPackageReportsAccountForEveryResource(): void {
		$courses   = self::by(self::of('course'), 'uuid');
		$lessons   = self::by(self::of('lesson'), 'uuid');
		$formats   = [];
		$roundTrip = 0;
		foreach (self::of('course-package-import-report') as $report) {
			$outcomes = array_count_values(array_column($report['entries'], 'outcome'));
			self::assertSame($report['resourcesTotal'], count($report['entries']), $report['slug']);
			self::assertSame($report['resourcesImported'], ($outcomes['imported'] ?? 0), $report['slug']);
			self::assertSame($report['resourcesDegraded'], ($outcomes['degraded'] ?? 0), $report['slug']);
			self::assertSame($report['resourcesDropped'], ($outcomes['dropped'] ?? 0), $report['slug']);
			self::assertSame(($report['resourcesDegraded'] + $report['resourcesDropped']) === 0 ? 'succeeded' : 'partial', $report['lifecycle']);
			foreach ($report['entries'] as $entry) {
				if ($entry['targetType'] === 'lesson') {
					self::assertSame($report['courseId'], $lessons[$entry['targetId']]['courseId'], $report['slug'] . ' points at lessons of its own course');
				}
			}

			$formats[$report['sourceFormat']] = true;
			if ($report['sourceFormat'] === 'scholiq-json') {
				self::assertSame('draft', $courses[$report['courseId']]['lifecycle'], 'an export read back in becomes next year\'s draft');
				$roundTrip++;
			}
		}

		self::assertArrayHasKey('common-cartridge-1.3', $formats);
		self::assertArrayHasKey('moodle-backup', $formats);
		self::assertGreaterThanOrEqual(1, $roundTrip);
	}//end testPackageReportsAccountForEveryResource()

	/**
	 * Loads and removes cleanly: the service offers the set with its true
	 * count, and its removal list is every object exactly once, children
	 * before parents.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-training-institute-set-loads-and-removes-cleanly
	 */
	public function testTheServiceOffersAndRemovesExactlyThisSet(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$demo = $this->createMock(DemoDataService::class);
		$demo->method('listChoices')->willReturn([]);
		$service = new SeedProfileService($appManager, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $demo, $this->createMock(\OCA\Learniq\Service\SharedCodeFilter::class), $this->createMock(\OCA\Learniq\Service\LoadedExampleSets::class));

		$offered = array_values(array_filter($service->listChoices(), static fn (array $c): bool => $c['id'] === 'training'))[0];
		self::assertSame('Training institute', $offered['label']);
		self::of('school');
		$all = [];
		foreach (array_keys((array)self::$objects) as $schema) {
			$all = array_merge($all, self::of($schema));
		}

		self::assertSame(count($all), $offered['objectCount']);

		$uuids = $service->uuidsFor('training');
		self::assertCount(count($all), array_unique($uuids));
		self::assertSame(end($all)['uuid'], $uuids[0], 'the last-loaded object is removed first');
		self::assertSame(self::of('school')[0]['uuid'], end($uuids), 'the institute is removed last');
	}//end testTheServiceOffersAndRemovesExactlyThisSet()

	/**
	 * The file is what the generator produces, so nobody edits the JSON by
	 * hand and the rules in scripts/example-sets/training.py stay the truth.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-training-institute-set-loads-and-removes-cleanly
	 */
	public function testTheFileIsWhatTheGeneratorProduces(): void {
		$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
		if ($python === '') {
			self::markTestSkipped('python3 is not available here; run python3 scripts/example-sets/training.py --check by hand.');
		}

		$script = dirname(__DIR__, 3) . '/scripts/example-sets/training.py';
		exec(escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --check 2>&1', $output, $exitCode);

		self::assertSame(0, $exitCode, implode("\n", $output));
	}//end testTheFileIsWhatTheGeneratorProduces()

	/**
	 * Every regulation code the set uses is a Regulation row in the set, or
	 * AVG, which the register seeds (D29). The rows are published and oblige
	 * no participant: the institute trains people for their employers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-regulation-rows/specs/example-sets/spec.md#scenario-every-training-regulation-reference-resolves
	 */
	public function testEveryRegulationReferenceResolves(): void {
		$rows    = self::of('regulation');
		$shipped = array_column($rows, 'slug');
		self::assertNotContains('AVG', $shipped, 'the register seeds AVG; a second row would duplicate it');

		$used = [];
		foreach ((array)self::$objects as $bucket) {
			foreach ($bucket as $row) {
				if (isset($row['regulationSlug']) === true) {
					$used[$row['regulationSlug']] = true;
				}
			}
		}

		self::assertGreaterThanOrEqual(6, count($used));
		foreach (array_keys($used) as $code) {
			self::assertTrue($code === 'AVG' || in_array($code, $shipped, true), $code . ' is used but has no Regulation row');
		}

		foreach ($rows as $row) {
			self::assertSame('published', $row['lifecycle'], $row['slug']);
			self::assertSame('role-specific', $row['audienceScope'], $row['slug']);
			self::assertSame([], $row['audienceRoles'], $row['slug'] . ' obliges no participant');
		}
	}//end testEveryRegulationReferenceResolves()

	/**
	 * Every item is QTI 2.1, the dialect the app reads: the app's own choice
	 * reader finds the three options of each item, the correct answer among
	 * them, and nothing in the QTI 3.0 namespace is left.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/training-set-qti-2-1/specs/example-sets/spec.md#requirement-the-training-set-writes-its-items-as-qti-21
	 */
	public function testEveryItemIsQti21TheAppCanRead(): void {
		$reader = new QtiChoiceOrderResolver();
		$items  = self::of('item');
		self::assertGreaterThanOrEqual(40, count($items));
		foreach ($items as $item) {
			self::assertStringContainsString('xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1"', $item['qtiBody'], $item['slug']);
			self::assertStringNotContainsString('imsqtiasi_v3p0', $item['qtiBody'], $item['slug']);

			$order = $reader->resolveOrder($item);
			self::assertIsArray($order, $item['slug'] . ' has choices the app can read');
			self::assertCount(3, $order, $item['slug']);
			self::assertContains($item['correctResponse']['value'], $order, $item['slug']);
		}
	}//end testEveryItemIsQti21TheAppCanRead()
}//end class
