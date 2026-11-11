<?php

/**
 * Learniq PortalAssessmentCatalogue unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

require_once __DIR__ . '/../../../Support/PortalFakeRegister.php';


use DateTimeImmutable;
use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Service\LessonReleaseEvaluator;
use OCA\Learniq\Service\Portal\PortalAssessmentCatalogue;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Tests\Support\PortalFakeRegister;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalAssessmentCatalogue.
 */
class PortalAssessmentCatalogueTest extends TestCase {

	/**
	 * Build the catalogue over a register.
	 *
	 * @param PortalFakeRegister $register The register.
	 *
	 * @return PortalAssessmentCatalogue
	 */
	private function catalogue(PortalFakeRegister $register): PortalAssessmentCatalogue {
		$release = $this->createMock(LessonReleaseEvaluator::class);
		$release->method('evaluate')->willReturn(['available' => true, 'reason' => null, 'availableAt' => null]);

		return new PortalAssessmentCatalogue(
			reader: new PortalAttemptReader(objectService: $register->objectService($this)),
			release: $release,
			policy: new AssessmentAccessPolicy()
		);
	}//end catalogue()

	/**
	 * The pupil.
	 *
	 * @return PortalLearner
	 */
	private function learner(): PortalLearner {
		return new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: 't-1', user: $this->createMock(IUser::class));
	}//end learner()

	/**
	 * Tests come through the pupil's courses and cohorts, once each; a
	 * withdrawn enrolment and a draft test bring nothing.
	 *
	 * @return void
	 */
	public function testExamsComeThroughCoursesAndCohortsOnce(): void {
		$register = new PortalFakeRegister();
		$register->put('enrolment', 'en-1', ['learnerId' => 'pupil-1', 'courseId' => 'c-1', 'cohortId' => 'h-1', 'lifecycle' => 'active']);
		$register->put('enrolment', 'en-2', ['learnerId' => 'pupil-1', 'courseId' => 'c-2', 'lifecycle' => 'withdrawn']);
		$register->put('exam', 'e-course', ['courseId' => 'c-1', 'cohortId' => 'h-1', 'lifecycle' => 'published']);
		$register->put('exam', 'e-cohort', ['cohortId' => 'h-1', 'lifecycle' => 'published']);
		$register->put('exam', 'e-draft', ['courseId' => 'c-1', 'lifecycle' => 'draft']);
		$register->put('exam', 'e-withdrawn', ['courseId' => 'c-2', 'lifecycle' => 'published']);

		$ids = array_map(static fn (array $entry): string => $entry['exam']['id'], $this->catalogue($register)->examsFor(learner: $this->learner()));
		sort($ids);

		self::assertSame(['e-cohort', 'e-course'], $ids);
	}//end testExamsComeThroughCoursesAndCohortsOnce()

	/**
	 * Each start rule gives its own reason; an open test gives none.
	 *
	 * @return void
	 */
	public function testEachRuleGivesItsReason(): void {
		$catalogue = $this->catalogue(new PortalFakeRegister());
		$now = new DateTimeImmutable('2026-10-01T09:00:00+00:00');
		$open = ['lifecycle' => 'published', 'tenant_id' => 't-1', 'maxAttempts' => 2];
		$enrolment = ['courseId' => 'c-1'];

		self::assertNull($catalogue->startBlock(learner: $this->learner(), exam: $open, enrolment: $enrolment, attemptsUsed: 1, now: $now));
		self::assertSame('attempts-used', $catalogue->startBlock(learner: $this->learner(), exam: $open, enrolment: $enrolment, attemptsUsed: 2, now: $now));
		self::assertSame('not-available', $catalogue->startBlock(learner: $this->learner(), exam: $open, enrolment: null, attemptsUsed: 0, now: $now));
		self::assertSame('not-available', $catalogue->startBlock(learner: $this->learner(), exam: ['tenant_id' => 't-2'] + $open, enrolment: $enrolment, attemptsUsed: 0, now: $now));
		self::assertSame('proctored', $catalogue->startBlock(learner: $this->learner(), exam: ['proctoring' => ['lockdownBrowser' => true]] + $open, enrolment: $enrolment, attemptsUsed: 0, now: $now));
		self::assertNull($catalogue->startBlock(learner: $this->learner(), exam: ['proctoring' => ['nativeTestMode' => false]] + $open, enrolment: $enrolment, attemptsUsed: 0, now: $now));
		self::assertSame('not-open', $catalogue->startBlock(learner: $this->learner(), exam: ['availableFrom' => '2026-10-02T00:00:00+00:00'] + $open, enrolment: $enrolment, attemptsUsed: 0, now: $now));
		self::assertSame('closed', $catalogue->startBlock(learner: $this->learner(), exam: ['availableUntil' => '2026-09-30T00:00:00+00:00'] + $open, enrolment: $enrolment, attemptsUsed: 0, now: $now));
	}//end testEachRuleGivesItsReason()

	/**
	 * A draft test and a test the release rules hold back are not available,
	 * and the rules refuse in a fixed order: availability before proctoring,
	 * proctoring before the window, the window before release, and release
	 * before the attempt count.
	 *
	 * @return void
	 */
	public function testRulesRefuseInTheirOrder(): void {
		$catalogue = $this->catalogue(new PortalFakeRegister());
		$now = new DateTimeImmutable('2026-10-01T09:00:00+00:00');
		$open = ['lifecycle' => 'published', 'tenant_id' => 't-1', 'maxAttempts' => 1];
		$enrolment = ['courseId' => 'c-1'];
		$proctored = ['proctoring' => ['lockdownBrowser' => true]];
		$closed = ['availableUntil' => '2026-09-30T00:00:00+00:00'];

		self::assertSame('not-available', $catalogue->startBlock(learner: $this->learner(), exam: ['lifecycle' => 'draft'] + $open, enrolment: $enrolment, attemptsUsed: 0, now: $now));
		self::assertSame('not-available', $catalogue->startBlock(learner: $this->learner(), exam: ['tenant_id' => 't-2'] + $proctored + $open, enrolment: $enrolment, attemptsUsed: 0, now: $now));
		self::assertSame('proctored', $catalogue->startBlock(learner: $this->learner(), exam: $proctored + $closed + $open, enrolment: $enrolment, attemptsUsed: 1, now: $now));
		self::assertSame('closed', $catalogue->startBlock(learner: $this->learner(), exam: $closed + $open, enrolment: $enrolment, attemptsUsed: 1, now: $now));
		self::assertNull($catalogue->startBlock(learner: $this->learner(), exam: ['tenant_id' => ''] + $open, enrolment: $enrolment, attemptsUsed: 0, now: $now));

		$release = $this->createMock(LessonReleaseEvaluator::class);
		$release->method('evaluate')->willReturn(['available' => false, 'reason' => 'drip', 'availableAt' => null]);
		$held = new PortalAssessmentCatalogue(
			reader: new PortalAttemptReader(objectService: (new PortalFakeRegister())->objectService($this)),
			release: $release,
			policy: new AssessmentAccessPolicy()
		);

		self::assertSame('not-available', $held->startBlock(learner: $this->learner(), exam: $open, enrolment: $enrolment, attemptsUsed: 1, now: $now));
		self::assertSame('closed', $held->startBlock(learner: $this->learner(), exam: $closed + $open, enrolment: $enrolment, attemptsUsed: 0, now: $now));
	}//end testRulesRefuseInTheirOrder()
}//end class
