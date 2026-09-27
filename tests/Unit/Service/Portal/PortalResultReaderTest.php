<?php

/**
 * Learniq PortalResultReader unit tests.
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

require_once __DIR__ . '/../../../Support/PortalFakeRegister.php';

use DateTime;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Service\Portal\PortalItemPresenter;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalResultReader;
use OCA\Learniq\Tests\Support\PortalFakeRegister;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalResultReader::result().
 */
class PortalResultReaderTest extends TestCase {

	private PortalFakeRegister $register;

	/**
	 * A graded attempt on a pass-mark test, with a GradeEntry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new PortalFakeRegister();
		$this->register->put('exam', 'exam-1', ['title' => 'Toets', 'scoringScheme' => 'passMark', 'passMark' => 3]);
		$this->register->put('item', 'item-1', ['title' => 'Q1', 'interactionType' => 'choice', 'qtiBody' => '<itemBody><prompt>Pick B</prompt></itemBody>', 'correctResponse' => 'SECRET_B']);
		$this->register->put('item', 'item-2', ['title' => 'Explain', 'interactionType' => 'extendedText', 'qtiBody' => '<itemBody><p>Explain.</p></itemBody>']);
		$this->register->put(
			'assessment-result',
			'att-1',
			[
				'assessmentId' => 'exam-1',
				'learnerId' => 'pupil-1',
				'lifecycle' => 'graded',
				'gradeEntryId' => 'ge-1',
				'drawnItemRefs' => [['itemId' => 'item-1', 'points' => 1], ['itemId' => 'item-2', 'points' => 4]],
				'responses' => [
					['itemId' => 'item-1', 'response' => ['value' => 'SECRET_B'], 'autoScore' => 1, 'manualScore' => null],
					['itemId' => 'item-2', 'response' => ['value' => 'Because.'], 'autoScore' => null, 'manualScore' => 3],
				],
			]
		);
	}//end setUp()

	/**
	 * The reader with a fixed clock.
	 *
	 * @return PortalResultReader
	 */
	private function reader(): PortalResultReader {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-10T12:00:00+02:00'));

		return new PortalResultReader(
			reader: new PortalAttemptReader(objectService: $this->register->objectService($this)),
			presenter: new PortalItemPresenter(),
			time: $time
		);
	}//end reader()

	/**
	 * The pupil.
	 *
	 * @param string $uid Nextcloud user id.
	 *
	 * @return PortalLearner
	 */
	private function learner(string $uid = 'pupil-1'): PortalLearner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return new PortalLearner(profileRef: 'lp-1', ncUserId: $uid, tenantId: '', user: $user);
	}//end learner()

	/**
	 * Submitted, graded with a concept grade, or graded with a grade visible
	 * only later: not released.
	 *
	 * @return void
	 */
	public function testNothingIsShownBeforeRelease(): void {
		$this->register->put('grade-entry', 'ge-1', ['lifecycle' => 'concept']);
		self::assertSame(['released' => false], $this->reader()->result(learner: $this->learner(), attemptId: 'att-1')->body);

		$this->register->put('grade-entry', 'ge-1', ['lifecycle' => 'published', 'visibleFrom' => '2026-10-11T08:00:00+02:00']);
		self::assertSame(['released' => false], $this->reader()->result(learner: $this->learner(), attemptId: 'att-1')->body);

		$this->register->put('grade-entry', 'ge-1', ['lifecycle' => 'invalidated']);
		self::assertSame(['released' => false], $this->reader()->result(learner: $this->learner(), attemptId: 'att-1')->body);

		$this->register->rows['assessment-result']['att-1']['lifecycle'] = 'submitted';
		$this->register->put('grade-entry', 'ge-1', ['lifecycle' => 'published']);
		self::assertSame(['released' => false], $this->reader()->result(learner: $this->learner(), attemptId: 'att-1')->body);
	}//end testNothingIsShownBeforeRelease()

	/**
	 * Once published, the pupil sees the total, pass or fail and per-item
	 * scores; never the correct answer.
	 *
	 * @return void
	 */
	public function testAPublishedGradeReleasesTheResult(): void {
		$this->register->put('grade-entry', 'ge-1', ['lifecycle' => 'published', 'visibleFrom' => '2026-10-09T08:00:00+02:00']);

		$body = $this->reader()->result(learner: $this->learner(), attemptId: 'att-1')->body;

		self::assertTrue($body['released']);
		self::assertSame(4.0, $body['score']);
		self::assertSame(5.0, $body['maxScore']);
		self::assertTrue($body['passed']);
		self::assertSame('Pick B', $body['items'][0]['prompt']);
		self::assertSame('SECRET_B', $body['items'][0]['response']);
		self::assertSame(3, $body['items'][1]['score']);
		self::assertStringNotContainsString('correctResponse', (string)json_encode($body));
	}//end testAPublishedGradeReleasesTheResult()

	/**
	 * A graded attempt without a GradeEntry is released by the grading.
	 *
	 * @return void
	 */
	public function testAGradedAttemptWithoutAGradeEntryIsReleased(): void {
		$this->register->rows['assessment-result']['att-1']['gradeEntryId'] = null;

		self::assertTrue($this->reader()->result(learner: $this->learner(), attemptId: 'att-1')->body['released']);
	}//end testAGradedAttemptWithoutAGradeEntryIsReleased()

	/**
	 * Another pupil's attempt is not found.
	 *
	 * @return void
	 */
	public function testAnotherPupilsResultIsNotFound(): void {
		$this->register->put('grade-entry', 'ge-1', ['lifecycle' => 'published']);

		self::assertSame(404, $this->reader()->result(learner: $this->learner('pupil-2'), attemptId: 'att-1')->status);
		self::assertSame(404, $this->reader()->result(learner: $this->learner(), attemptId: 'nope')->status);
	}//end testAnotherPupilsResultIsNotFound()
}//end class
