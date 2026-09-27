<?php

/**
 * Learniq PortalAttemptReader and PortalAttemptWriter unit tests.
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

require_once __DIR__ . '/../../../Support/PortalFakeRegister.php';


use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Service\Portal\PortalAttemptWriter;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Tests\Support\PortalFakeRegister;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalAttemptReader and PortalAttemptWriter.
 */
class PortalAttemptStorageTest extends TestCase {

	/**
	 * Reads find rows only through filters, and an unknown id is null.
	 *
	 * @return void
	 */
	public function testReadsUseTheShapeOpenRegisterAnswers(): void {
		$register = new PortalFakeRegister();
		$register->put('assessment-result', 'a-1', ['assessmentId' => 'e-1', 'learnerId' => 'pupil-1']);
		$register->put('assessment-result', 'a-2', ['assessmentId' => 'e-1', 'learnerId' => 'pupil-2']);
		$register->put('exam', 'e-1', ['title' => 'Toets', 'accessCode' => 'ROOM-12']);
		$reader = new PortalAttemptReader(objectService: $register->objectService($this));

		self::assertSame(['a-1'], array_column($reader->attemptsFor(examId: 'e-1', ncUserId: 'pupil-1'), 'id'));
		self::assertSame('ROOM-12', $reader->exam(id: 'e-1')['accessCode']);
		self::assertNull($reader->exam(id: 'e-9'));
		self::assertNull($reader->attempt(id: ''));
	}//end testReadsUseTheShapeOpenRegisterAnswers()

	/**
	 * Every write runs as the pupil; a save strips @self and names the row.
	 *
	 * @return void
	 */
	public function testWritesRunAsThePupil(): void {
		$register = new PortalFakeRegister();
		$register->put('exam', 'e-1', ['itemRefs' => []]);
		$register->put('assessment-result', 'a-1', ['learnerId' => 'pupil-1', 'lifecycle' => 'in-progress']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$learner = new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: '', user: $user);
		$writer = new PortalAttemptWriter(objectService: $register->objectService($this), transitionEngine: $register->transitionEngine($this));

		$created = $writer->createAttempt(learner: $learner, data: ['assessmentId' => 'e-1', 'learnerId' => 'pupil-1']);
		$writer->saveAttempt(learner: $learner, id: 'a-1', row: ['learnerId' => 'pupil-1', '@self' => ['x' => 1], 'lifecycle' => 'in-progress']);
		$writer->fireSubmit(learner: $learner, id: 'a-1');

		self::assertSame('new-1', $created['id']);
		self::assertSame(['pupil-1', 'pupil-1'], array_column($register->writes, 'as'));
		self::assertSame('a-1', $register->writes[1]['id']);
		self::assertArrayNotHasKey('@self', $register->writes[1]['data']);
		self::assertSame([['id' => 'a-1', 'action' => 'submit', 'as' => 'pupil-1']], $register->transitions);
	}//end testWritesRunAsThePupil()
}//end class
