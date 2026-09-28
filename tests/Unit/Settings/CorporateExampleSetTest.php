<?php

/**
 * The company example set keeps its promises.
 *
 * ExampleSetDescriptorContractTest proves the file loads (shape, uuids,
 * references, schema validity). This test proves the story is consistent: a
 * certificate that expires during the year opens a renewal enrolment and the
 * renewal issues the next certificate after the old one lapsed, every mark
 * sits on a session of its own cohort, the skills gap the dashboard computes
 * is exactly what the development plans address, the leaderboard totals are
 * the sum of the point awards, a paid course is only open to whoever paid,
 * and the removal list covers every object once.
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
 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use DateTimeImmutable;
use OCA\Learniq\Service\DemoDataService;
use OCA\Learniq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Content and consistency of lib/Settings/profiles/corporate.json.
 */
class CorporateExampleSetTest extends TestCase {

	/**
	 * The last day of the 2025-2026 year every set tells.
	 */
	private const LAST_DAY = '2026-07-10';

	/**
	 * The first day of that year.
	 */
	private const FIRST_DAY = '2025-08-18';

	/**
	 * Public holidays and the Christmas closure the generator keeps free.
	 *
	 * @var string[]
	 */
	private const CLOSED = [
		'2025-12-25', '2025-12-26', '2026-01-01', '2026-01-02', '2026-04-06',
		'2026-04-27', '2026-05-14', '2026-05-15', '2026-05-25',
	];

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
			$set = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/corporate.json'), true);
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
	 * Group a list of objects by one field.
	 *
	 * @param array<int, array<string, mixed>> $rows  The objects.
	 * @param string                           $field The field.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function groupBy(array $rows, string $field): array {
		$groups = [];
		foreach ($rows as $row) {
			if (isset($row[$field]) === true) {
				$groups[(string)$row[$field]][] = $row;
			}
		}

		return $groups;
	}//end groupBy()

	/**
	 * The department cohorts: the cohorts that are not a training group.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function departments(): array {
		return array_values(array_filter(self::of('cohort'), static fn (array $c): bool => isset($c['courseId']) === false));
	}//end departments()

	/**
	 * One company, two sites, eight departments, about 200 employees, each in
	 * exactly one department, each with a manager, each enrolled in the two
	 * company-wide compliance courses; HR and compliance are on Staff.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-the-company-set-is-one-consistent-company
	 */
	public function testTheCompanyHasItsPromisedShape(): void {
		self::assertSame(['Voorbeeldbedrijf Esdoorn Techniek B.V.'], array_column(self::of('school'), 'name'));
		self::assertCount(2, self::of('vestiging'));
		self::assertSame(
			[
				'Directie en staf', 'Financiën en administratie', 'ICT', 'Verkoop en klantenservice',
				'Planning en werkvoorbereiding', 'Installatie en service', 'Magazijn en logistiek', 'Werkplaats',
			],
			array_column(self::departments(), 'name')
		);

		$profiles = self::of('learner-profile');
		self::assertGreaterThanOrEqual(190, count($profiles));
		self::assertLessThanOrEqual(210, count($profiles));

		$membership = array_count_values(array_merge(...array_column(self::departments(), 'learnerIds')));
		$byUser     = self::by($profiles, 'ncUserId');
		$courses    = self::by(self::of('course'), 'uuid');
		$enrolments = self::groupBy(self::of('enrolment'), 'learnerId');
		foreach ($profiles as $profile) {
			$user = $profile['ncUserId'];
			self::assertSame(1, ($membership[$user] ?? 0), $user . ' sits in exactly one department');
			self::assertArrayNotHasKey('bsnEncrypted', $profile);
			if ($user !== 'corporate-directeur-01') {
				self::assertArrayHasKey($profile['managerId'], $byUser, $user . ' has a manager in the set');
				self::assertContains('manager', $byUser[$profile['managerId']]['roles']);
			}

			$codes = array_map(static fn (array $e): string => $courses[$e['courseId']]['code'], $enrolments[$user]);
			self::assertContains('ESD-GED', $codes, $user . ' is enrolled in the code of conduct course');
			self::assertContains('ESD-IBV', $codes, $user . ' is enrolled in the security course');
		}

		$staff = self::by(self::of('staff'), 'ncUserId');
		self::assertContains('HR-adviseur', $staff['corporate-hr-01']['qualifications']);
		self::assertContains('compliance officer', $staff['corporate-compliance-01']['qualifications']);

		self::assertGreaterThan(900, count(self::of('credential')));
		self::assertGreaterThan(600, count(self::of('enrolment')));
		self::assertGreaterThan(1000, count(self::of('lesson-completion')));
		self::assertGreaterThan(250, count(self::of('attendance-record')));
		self::assertGreaterThanOrEqual(25, count(self::of('external-training-record')));
		self::assertCount(count($profiles), self::of('learning-plan'));
	}//end testTheCompanyHasItsPromisedShape()

	/**
	 * Nothing in the set can pass for real: unsigned credentials, reserved
	 * domains, postcodes the Netherlands never issues.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-the-company-set-is-one-consistent-company
	 */
	public function testNothingInTheSetPassesForReal(): void {
		foreach (self::of('credential') as $credential) {
			self::assertSame('voorbeeldgegevens-niet-ondertekend', $credential['signature'], $credential['slug']);
			self::assertStringEndsWith('.example', $credential['issuerDid'], $credential['slug']);
		}

		foreach (self::of('order') as $order) {
			self::assertStringEndsWith('.example', $order['payerEmail']);
		}

		foreach (self::of('vestiging') as $site) {
			self::assertStringStartsWith('0', $site['postalCode']);
		}

		self::assertMatchesRegularExpression('/^\d{2}[A-Z]\d$/', self::of('school')[0]['brin']);
	}//end testNothingInTheSetPassesForReal()

	/**
	 * Every mark sits on a session of its own cohort, the session falls on a
	 * working day of the year, no room is booked twice at once, and nobody is
	 * present in two sessions at once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-the-company-set-is-one-consistent-company
	 */
	public function testEveryMarkBelongsToASessionOfItsOwnCohort(): void {
		$sessions = self::by(self::of('session'), 'uuid');
		$cohorts  = self::by(self::of('cohort'), 'uuid');
		$present  = [];
		foreach (self::of('attendance-record') as $mark) {
			$session = $sessions[$mark['sessionId']];
			self::assertSame($session['cohortId'], $mark['cohortId'], $mark['slug']);
			self::assertContains($mark['learnerId'], $cohorts[$mark['cohortId']]['learnerIds'], $mark['slug']);
			if (in_array($mark['status'], ['present', 'late'], true) === true) {
				$present[$mark['learnerId']][] = $session;
			}
		}

		$rooms = [];
		foreach (self::of('session') as $session) {
			$day = substr($session['startsAt'], 0, 10);
			self::assertLessThan(6, (int)(new DateTimeImmutable($day))->format('N'), $session['title'] . ' is on a weekday');
			self::assertNotContains($day, self::CLOSED, $session['title'] . ' is not on a closed day');
			self::assertGreaterThanOrEqual(self::FIRST_DAY, $day);
			self::assertLessThanOrEqual(self::LAST_DAY, $day);
			$rooms[$session['roomId']][] = $session;
		}

		foreach (array_merge(array_values($rooms), array_values($present)) as $list) {
			usort($list, static fn (array $a, array $b): int => strcmp($a['startsAt'], $b['startsAt']));
			for ($i = 1; $i < count($list); $i++) {
				$before = new DateTimeImmutable($list[$i - 1]['endsAt']);
				$after  = new DateTimeImmutable($list[$i]['startsAt']);
				self::assertGreaterThanOrEqual($before, $after, $list[$i - 1]['title'] . ' overlaps ' . $list[$i]['title']);
			}
		}
	}//end testEveryMarkBelongsToASessionOfItsOwnCohort()

	/**
	 * A credential that expires during the year is expired and names a
	 * renewal enrolment in the course its own course renews into; a completed
	 * renewal issued exactly one new credential, after the old one lapsed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-certificates-expire-and-renew-the-way-the-listener-does-it
	 */
	public function testAnExpiryOpensARenewalThatIssuesTheNextCredential(): void {
		$enrolments   = self::by(self::of('enrolment'), 'uuid');
		$courses      = self::by(self::of('course'), 'uuid');
		$issuedFor    = self::groupBy(self::of('credential'), 'enrolmentId');
		$cohorts      = self::by(self::of('cohort'), 'uuid');
		$sessionsOf   = self::groupBy(self::of('session'), 'cohortId');
		$presentAt    = [];
		foreach (self::of('attendance-record') as $mark) {
			if ($mark['status'] === 'present') {
				$presentAt[$mark['sessionId']][] = $mark['learnerId'];
			}
		}

		$renewals     = 0;
		$openRenewals = 0;
		$classroom    = 0;
		foreach (self::of('credential') as $credential) {
			$expires = ($credential['expiresAt'] ?? null);
			$lapsed  = ($expires !== null && substr($expires, 0, 10) <= self::LAST_DAY);
			self::assertSame($lapsed === true ? 'expired' : 'issued', $credential['lifecycle'], $credential['slug']);
			if ($lapsed === false) {
				continue;
			}

			self::assertGreaterThanOrEqual(self::FIRST_DAY, substr($expires, 0, 10), $credential['slug'] . ' lapsed inside the year');
			$renewal = $enrolments[$credential['renewalEnrolmentId']];
			self::assertSame('credential-renewal', $renewal['source'], $credential['slug']);
			self::assertSame($credential['learnerId'], $renewal['learnerRef'], $credential['slug']);
			self::assertSame($courses[$credential['courseId']]['renewalCourseSlug'], $courses[$renewal['courseId']]['slug'], $credential['slug']);
			$renewals++;
			if ($renewal['lifecycle'] !== 'completed') {
				$openRenewals++;
				continue;
			}

			$next = ($issuedFor[$renewal['uuid']] ?? []);
			self::assertCount(1, $next, $credential['slug'] . ' was renewed once');
			self::assertGreaterThan(new DateTimeImmutable($expires), new DateTimeImmutable($next[0]['issuedAt']), $credential['slug']);
			if (isset($cohorts[$renewal['cohortId']]['courseId']) === true) {
				// A classroom renewal: the new certificate is issued when the
				// last session of the group the employee attended ends.
				$last = end($sessionsOf[$renewal['cohortId']]);
				self::assertEquals(new DateTimeImmutable($last['endsAt']), new DateTimeImmutable($next[0]['issuedAt']), $credential['slug']);
				self::assertContains($renewal['learnerId'], ($presentAt[$last['uuid']] ?? []), $credential['slug'] . ' attended the day');
				$classroom++;
			}
		}//end foreach

		self::assertGreaterThan(150, $renewals);
		self::assertGreaterThan(40, $classroom, 'BHV, VCA, NEN 3140 and forklift renewals ran in classroom groups');
		self::assertGreaterThan(0, $openRenewals, 'the year ends with renewals still open, as a real one does');
	}//end testAnExpiryOpensARenewalThatIssuesTheNextCredential()

	/**
	 * Everyone a certification applies to holds a current credential for it
	 * or is booked on the course: VCA for Operatie, NEN 3140 for the
	 * technicians, the forklift certificate for the warehouse.
	 *
	 * The scopes are read from the set's own Regulation rows (their
	 * `department` audiences), the way the Compliance overview reads them,
	 * so the rows and the data cannot drift apart.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-certificates-expire-and-renew-the-way-the-listener-does-it
	 * @spec openspec/changes/example-set-regulation-rows/specs/example-sets/spec.md#scenario-the-company-scopes-drive-the-certification-check
	 */
	public function testEveryoneACertificationAppliesToHoldsItOrIsBooked(): void {
		$credentials = self::groupBy(self::of('credential'), 'learnerId');
		$enrolments  = self::groupBy(self::of('enrolment'), 'learnerId');
		$scopes      = [];
		foreach (self::of('regulation') as $row) {
			if ($row['audienceScope'] === 'department') {
				$scopes[$row['slug']] = $row['audienceDepartments'];
			}
		}

		self::assertEqualsCanonicalizing(['VCA', 'NEN3140', 'HEFTRUCK'], array_keys($scopes));
		foreach (self::of('learner-profile') as $profile) {
			foreach ($scopes as $regulation => $departments) {
				$inScope = array_filter(
					$departments,
					static fn (string $d): bool => $profile['department'] === $d || str_starts_with($profile['department'], $d . '/')
				);
				if ($inScope === []) {
					continue;
				}

				$holds  = array_filter(
					($credentials[$profile['uuid']] ?? []),
					static fn (array $c): bool => ($c['regulationSlug'] ?? '') === $regulation && $c['lifecycle'] === 'issued'
				);
				$booked = array_filter(
					($enrolments[$profile['ncUserId']] ?? []),
					static fn (array $e): bool => ($e['regulationSlug'] ?? '') === $regulation && in_array($e['lifecycle'], ['pending', 'active'], true)
				);
				self::assertTrue($holds !== [] || $booked !== [], $profile['ncUserId'] . ' holds ' . $regulation . ' or is booked on it');
			}
		}
	}//end testEveryoneACertificationAppliesToHoldsItOrIsBooked()

	/**
	 * The skills gap, computed the way SkillsGapDashboard.vue computes it,
	 * shows the unfinished security course as a DIG-01 gap and nothing else
	 * for those learners, leaves some technicians with gaps and some without,
	 * and every gap has an open goal in that employee's development plan.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-skills-gaps-plans-points-and-payments-agree-with-their-sources
	 */
	public function testTheSkillsGapIsWhatThePlansAddress(): void {
		$competencies = self::by(self::of('competency'), 'uuid');
		$programmes   = self::by(self::of('programme'), 'uuid');
		$courses      = self::by(self::of('course'), 'uuid');
		$enrolments   = self::groupBy(self::of('enrolment'), 'learnerId');
		$plans        = self::by(self::of('learning-plan'), 'learnerId');
		$attained     = [];
		foreach (self::of('competency-attainment') as $row) {
			if (($row['proficiencyLevelId'] ?? null) !== null) {
				$attained[$row['learnerId']][$row['competencyId']] = true;
			}
		}

		$security = array_values(array_filter(self::of('course'), static fn (array $c): bool => $c['code'] === 'ESD-IBV'))[0]['uuid'];
		$withGap  = 0;
		$without  = 0;
		foreach (self::of('learner-profile') as $profile) {
			$user     = $profile['ncUserId'];
			$required = [];
			foreach ($enrolments[$user] as $enrolment) {
				foreach (($courses[$enrolment['courseId']]['programmeIds'] ?? []) as $programme) {
					foreach ($programmes[$programme]['requiredCompetencyIds'] as $uuid) {
						$required[$uuid] = true;
					}
				}
			}

			foreach ($competencies as $uuid => $competency) {
				if (array_intersect($competency['requiredForRoles'], $profile['roles']) !== []) {
					$required[$uuid] = true;
				}
			}

			$gaps = array_diff_key($required, ($attained[$user] ?? []));
			foreach (array_keys($gaps) as $uuid) {
				$title = $competencies[$uuid]['title'];
				$goals = array_filter(
					$plans[$user]['goals'],
					static fn (array $g): bool => str_starts_with($g['description'], $title . ':') && $g['status'] === 'open'
				);
				self::assertNotEmpty($goals, $user . ' has an open goal for the gap ' . $competencies[$uuid]['code']);
			}

			$finishedSecurity = array_filter(
				$enrolments[$user],
				static fn (array $e): bool => $e['courseId'] === $security && $e['lifecycle'] === 'completed'
			);
			$dig01 = array_filter(array_keys($gaps), static fn (string $u): bool => $competencies[$u]['code'] === 'DIG-01');
			self::assertSame($finishedSecurity === [], $dig01 !== [], $user . ': a DIG-01 gap means the security course is unfinished');

			if (str_starts_with($profile['department'], 'Operatie/Installatie') === true) {
				if ($gaps === []) {
					$without++;
				} else {
					$withGap++;
				}
			}
		}//end foreach

		self::assertGreaterThan(0, $withGap, 'some service technicians have a gap');
		self::assertGreaterThan(0, $without, 'some service technicians have none');
	}//end testTheSkillsGapIsWhatThePlansAddress()

	/**
	 * Every development plan is coordinated by the employee's own manager and
	 * every mid-year review belongs to a plan and was held by its coordinator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-skills-gaps-plans-points-and-payments-agree-with-their-sources
	 */
	public function testPlansAreCoordinatedByTheEmployeesManager(): void {
		$profiles = self::by(self::of('learner-profile'), 'ncUserId');
		$plans    = self::by(self::of('learning-plan'), 'uuid');
		foreach ($plans as $plan) {
			$expected = ($profiles[$plan['learnerId']]['managerId'] ?? 'corporate-hr-01');
			self::assertSame($expected, $plan['coordinatorId'], $plan['slug']);
			self::assertNotEmpty($plan['goals'], $plan['slug']);
		}

		self::assertGreaterThan(100, count(self::of('learning-plan-evaluation')));
		foreach (self::of('learning-plan-evaluation') as $evaluation) {
			$plan = $plans[$evaluation['learningPlanId']];
			self::assertSame($plan['coordinatorId'], $evaluation['evaluatedBy'], $evaluation['slug']);
			self::assertEqualsCanonicalizing(array_column($plan['goals'], 'goalId'), array_column($evaluation['goalOutcomes'], 'goalId'));
		}
	}//end testPlansAreCoordinatedByTheEmployeesManager()

	/**
	 * One point award per completed enrolment; each employee's total is the
	 * sum of their awards and their level the highest one that total reaches;
	 * an engagement score stays inside the evaluator's formula; a risk flag
	 * only follows an unfinished course.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-skills-gaps-plans-points-and-payments-agree-with-their-sources
	 */
	public function testPointsLevelsAndScoresAddUp(): void {
		$enrolments = self::by(self::of('enrolment'), 'uuid');
		$completed  = array_filter(self::of('enrolment'), static fn (array $e): bool => $e['lifecycle'] === 'completed');
		self::assertCount(count($completed), self::of('point-award'));

		$totals = [];
		foreach (self::of('point-award') as $award) {
			self::assertSame('completed', $enrolments[$award['sourceObjectId']]['lifecycle']);
			self::assertSame($enrolments[$award['sourceObjectId']]['learnerId'], $award['learnerId']);
			$totals[$award['learnerId']] = (($totals[$award['learnerId']] ?? 0) + $award['points']);
		}

		$levels = self::of('engagement-level');
		usort($levels, static fn (array $a, array $b): int => $a['minPoints'] <=> $b['minPoints']);
		foreach (self::of('learner-engagement') as $engagement) {
			$total = ($totals[$engagement['learnerId']] ?? 0);
			self::assertEquals($total, $engagement['totalPoints'], $engagement['learnerId']);
			$reached = array_values(array_filter($levels, static fn (array $l): bool => $l['minPoints'] <= $total));
			self::assertSame(end($reached)['uuid'], $engagement['levelId'], $engagement['learnerId']);
		}

		self::assertCount(count(self::of('learner-profile')), self::of('learner-engagement'));
		self::assertContains('Leerkampioenen Esdoorn Techniek', array_column(self::of('leaderboard'), 'name'));

		$lessons = array_filter(self::of('lesson'), static fn (array $l): bool => $l['courseId'] === (self::of('engagement-score')[0]['courseId'] ?? ''));
		$minutes = array_sum(array_column($lessons, 'durationMinutes'));
		$scores  = self::by(self::of('engagement-score'), 'uuid');
		foreach ($scores as $score) {
			$floor = (int)round(min(1, $score['timeOnTaskMinutes'] / $minutes) * 70);
			self::assertGreaterThanOrEqual($floor, $score['score'], $score['slug']);
			self::assertLessThanOrEqual($floor + 30, $score['score'], $score['slug']);
		}

		$byLearner = self::groupBy(self::of('enrolment'), 'learnerId');
		self::assertNotEmpty(self::of('engagement-risk-flag'));
		foreach (self::of('engagement-risk-flag') as $flag) {
			$open = array_filter(
				$byLearner[$flag['learnerId']],
				static fn (array $e): bool => $e['courseId'] === $flag['courseId'] && $e['lifecycle'] !== 'completed'
			);
			self::assertNotEmpty($open, $flag['slug'] . ' follows an unfinished course');
			self::assertSame($flag['learnerId'], $scores[$flag['engagementScoreId']]['learnerId']);
		}
	}//end testPointsLevelsAndScoresAddUp()

	/**
	 * A paid course is paid through shillinq (D19), so the set carries no
	 * orders or payments. Every paid-course enrolment has exactly one
	 * entitlement for its fee, active with the day the payment settled when
	 * the enrolment went ahead, pending while it waits, and none when it was
	 * withdrawn.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-learniq-keeps-feeitem-and-entitlement-and-no-pay-screen-of-its-own
	 */
	public function testPaidCoursesAreOrderedPaidAndEntitled(): void {
		foreach (['order', 'order-line', 'payment-transaction'] as $retired) {
			self::assertSame([], self::of($retired), "$retired left learniq for shillinq");
		}

		$feesByCourse = self::by(self::of('fee-item'), 'linkedCourseId');
		$entitlements = self::of('entitlement');
		$states       = [];
		foreach (self::of('enrolment') as $enrolment) {
			$fee = ($feesByCourse[$enrolment['courseId']] ?? null);
			if ($fee === null) {
				continue;
			}

			$mine = array_values(array_filter(
				$entitlements,
				static fn (array $e): bool => $e['learnerId'] === $enrolment['learnerId'] && $e['feeItemId'] === $fee['uuid']
			));
			if ($enrolment['lifecycle'] === 'withdrawn') {
				self::assertSame([], $mine, $enrolment['slug'] . ' was withdrawn');
				$states['withdrawn'] = true;
				continue;
			}

			self::assertCount(1, $mine, $enrolment['slug'] . ' has one entitlement');
			$expected = ($enrolment['lifecycle'] === 'pending') ? 'pending' : 'active';
			self::assertSame($expected, $mine[0]['lifecycle'], $enrolment['slug']);
			self::assertSame($enrolment['courseId'], $mine[0]['grantedResourceId']);
			self::assertArrayNotHasKey('orderLineId', $mine[0]);
			if ($expected === 'active') {
				self::assertNotEmpty($mine[0]['paymentSettledAt']);
			}

			$states[$expected] = true;
		}//end foreach

		self::assertEqualsCanonicalizing(['active', 'pending', 'withdrawn'], array_keys($states));
	}//end testPaidCoursesAreOrderedPaidAndEntitled()

	/**
	 * Loads and removes cleanly: the service offers the set with its true
	 * count, and its removal list is every object exactly once, children
	 * before parents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-the-company-set-loads-and-removes-cleanly
	 */
	public function testTheServiceOffersAndRemovesExactlyThisSet(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$demo = $this->createMock(DemoDataService::class);
		$demo->method('listChoices')->willReturn([]);
		$service = new SeedProfileService($appManager, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $demo, $this->createMock(\OCA\Learniq\Service\LoadedExampleSets::class));

		$offered = array_values(array_filter($service->listChoices(), static fn (array $c): bool => $c['id'] === 'corporate'))[0];
		self::of('school');
		$all = [];
		foreach (array_keys((array)self::$objects) as $schema) {
			$all = array_merge($all, self::of($schema));
		}

		self::assertSame(count($all), $offered['objectCount']);
		self::assertSame('Company', $offered['label']);

		$uuids = $service->uuidsFor('corporate');
		self::assertCount(count($all), array_unique($uuids));
		self::assertSame(end($all)['uuid'], $uuids[0], 'the last-loaded object is removed first');
		self::assertSame(self::of('school')[0]['uuid'], end($uuids), 'the company is removed last');
	}//end testTheServiceOffersAndRemovesExactlyThisSet()

	/**
	 * The file is what the generator produces, so nobody edits the JSON by
	 * hand and the rules in scripts/example-sets/corporate.py stay the truth.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#scenario-the-file-is-reproducible
	 */
	public function testTheFileIsWhatTheGeneratorProduces(): void {
		$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
		if ($python === '') {
			self::markTestSkipped('python3 is not available here; run python3 scripts/example-sets/corporate.py --check by hand.');
		}

		$script = dirname(__DIR__, 3) . '/scripts/example-sets/corporate.py';
		exec(escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --check 2>&1', $output, $exitCode);

		self::assertSame(0, $exitCode, implode("\n", $output));
	}//end testTheFileIsWhatTheGeneratorProduces()

	/**
	 * The corporate seed row lives in the set, found by title, and the
	 * register no longer carries it: OpenRegister never read
	 * `x-openregister-seed`, so the row only became reachable by moving.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-corporate/specs/example-sets/spec.md#requirement-the-register-no-longer-carries-the-dark-corporate-seed
	 */
	public function testThePromotedSeedMovedOutOfTheRegister(): void {
		$sessions = array_values(array_filter(
			self::of('external-training-record'),
			static fn (array $r): bool => $r['title'] === 'NIS2 board awareness session'
		));
		self::assertNotEmpty($sessions);
		$profiles = self::by(self::of('learner-profile'), 'uuid');
		self::assertContains('corporate-directeur-01', array_map(static fn (array $r): string => $profiles[$r['learnerId']]['ncUserId'], $sessions));
		foreach ($sessions as $record) {
			self::assertSame('NIS2', $record['regulationSlug']);
			self::assertSame('verified', $record['lifecycle']);
		}

		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/learniq_register.json'), true);
		self::assertSame([], ($register['components']['schemas']['ExternalTrainingRecord']['x-openregister-seed'] ?? []));
	}//end testThePromotedSeedMovedOutOfTheRegister()

	/**
	 * Every regulation code the set uses is a Regulation row in the set, or
	 * AVG, which the register seeds (D29).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-regulation-rows/specs/example-sets/spec.md#scenario-every-company-regulation-reference-resolves
	 */
	public function testEveryRegulationReferenceResolves(): void {
		$shipped = array_column(self::of('regulation'), 'slug');
		self::assertNotContains('AVG', $shipped, 'the register seeds AVG; a second row would duplicate it');

		$used = [];
		foreach ((array)self::$objects as $schema => $rows) {
			foreach ($rows as $row) {
				if (isset($row['regulationSlug']) === true) {
					$used[$row['regulationSlug']] = $schema;
				}
			}
		}

		self::assertGreaterThanOrEqual(9, count($used));
		foreach (array_keys($used) as $code) {
			self::assertTrue($code === 'AVG' || in_array($code, $shipped, true), $code . ' is used but has no Regulation row');
		}
	}//end testEveryRegulationReferenceResolves()

	/**
	 * The rows count in the Compliance overview: published, active, with the
	 * audiences the set trains for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-regulation-rows/specs/example-sets/spec.md#requirement-the-company-and-training-sets-carry-the-regulations-they-reference
	 */
	public function testRegulationsArePublishedWithTheirAudiences(): void {
		$rows = self::by(self::of('regulation'), 'slug');
		foreach ($rows as $code => $row) {
			self::assertSame('published', $row['lifecycle'], $code);
			self::assertTrue($row['active'], $code);
			self::assertMatchesRegularExpression('/^[A-Z0-9_-]+$/', (string)$code);
		}

		self::assertSame('all-employees', $rows['GEDRAGSCODE']['audienceScope']);
		self::assertSame('all-employees', $rows['INFORMATIEBEVEILIGING']['audienceScope']);
		self::assertSame('board', $rows['NIS2']['audienceScope']);
		self::assertEqualsCanonicalizing(['manager', 'compliance-officer'], $rows['NIS2']['audienceRoles']);
		foreach (['BHV', 'FGASSEN'] as $designated) {
			self::assertSame('role-specific', $rows[$designated]['audienceScope'], $designated);
			self::assertSame([], $rows[$designated]['audienceRoles'], $designated . ' falls on designated people, not a role');
		}

		$board = array_filter(
			self::of('learner-profile'),
			static fn (array $p): bool => array_intersect($p['roles'], ['manager', 'compliance-officer']) !== []
		);
		$nis2  = array_filter(self::of('external-training-record'), static fn (array $r): bool => ($r['regulationSlug'] ?? '') === 'NIS2');
		self::assertSame(count($board), count(array_unique(array_column($nis2, 'learnerId'))), 'every board member has a NIS2 record');
	}//end testRegulationsArePublishedWithTheirAudiences()
}//end class
