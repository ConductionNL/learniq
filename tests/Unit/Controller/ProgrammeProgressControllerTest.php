<?php

/**
 * The signed-in learner reads their own programme progress.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ProgrammeProgressController;
use OCA\Learniq\Service\Programme\ProgrammeProgress;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProgrammeProgressController.
 */
class ProgrammeProgressControllerTest extends TestCase {

	/**
	 * A controller for the given signed-in user (null for none).
	 *
	 * @param string|null $uid The signed-in user id.
	 *
	 * @return ProgrammeProgressController
	 */
	private function controller(?string $uid): ProgrammeProgressController {
		$store = new RegisterFaithfulStore();
		$store->rows['enrolment'] = [
			['id' => 'e1', 'learnerId' => 'jan', 'courseId' => 'c-intro', 'programmeId' => 'p-safety', 'mandatory' => true, 'lifecycle' => 'completed'],
			['id' => 'e2', 'learnerId' => 'piet', 'courseId' => 'c-intro', 'programmeId' => 'p-safety', 'mandatory' => true, 'lifecycle' => 'active'],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('find')->willReturn(null);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ProgrammeProgressController(request: $this->createMock(IRequest::class), userSession: $session, progress: new ProgrammeProgress(objects: $objects));
	}//end controller()

	/**
	 * The caller gets their own progress and nobody else's.
	 *
	 * @return void
	 */
	public function testTheCallerReadsOnlyTheirOwnProgress(): void {
		$response = $this->controller(uid: 'jan')->mine();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertCount(1, $data['programmes']);
		self::assertSame([1, 1, true], [$data['programmes'][0]['mandatoryTotal'], $data['programmes'][0]['mandatoryCompleted'], $data['programmes'][0]['complete']]);
	}//end testTheCallerReadsOnlyTheirOwnProgress()

	/**
	 * Without a signed-in user there is nothing to read.
	 *
	 * @return void
	 */
	public function testNoUserIsUnauthorised(): void {
		self::assertSame(401, $this->controller(uid: null)->mine()->getStatus());
	}//end testNoUserIsUnauthorised()
}//end class
