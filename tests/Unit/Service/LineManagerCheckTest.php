<?php

/**
 * Tests for LineManagerCheck.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LineManagerCheck;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Whether a user is named as manager on any learner profile.
 */
class LineManagerCheckTest extends TestCase {

	/**
	 * A user.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager-1');
		return $user;
	}//end user()

	/**
	 * One profile naming the user as manager answers true; the query asks for exactly that.
	 *
	 * @return void
	 */
	public function testAProfileNamingTheUserAnswersTrue(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('findAll')->with(
			[
				'filters' => ['register' => 'learniq', 'schema' => 'learner-profile', 'managerId' => 'manager-1'],
				'limit'   => 1,
			],
			false
		)->willReturn([['id' => 'lp-1']]);

		self::assertTrue((new LineManagerCheck(objectService: $objects))->managesLearners(user: $this->user()));
	}//end testAProfileNamingTheUserAnswersTrue()

	/**
	 * No such profile answers false.
	 *
	 * @return void
	 */
	public function testNoProfileAnswersFalse(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn([]);

		self::assertFalse((new LineManagerCheck(objectService: $objects))->managesLearners(user: $this->user()));
	}//end testNoProfileAnswersFalse()

	/**
	 * A failing read answers false instead of breaking the page.
	 *
	 * @return void
	 */
	public function testAFailingReadAnswersFalse(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('down'));

		self::assertFalse((new LineManagerCheck(objectService: $objects))->managesLearners(user: $this->user()));
	}//end testAFailingReadAnswersFalse()
}//end class
