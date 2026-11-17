<?php

/**
 * The exam board's practical exam results endpoint.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PracticalExamResultsController;
use OCA\Learniq\Kernel\Contracts\PracticalExamBoardResultsInterface;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Controller\PracticalExamResultsController
 */
class PracticalExamResultsControllerTest extends TestCase {

	private PracticalExamBoardResultsInterface&MockObject $results;

	private ?IUser $user = null;

	protected function setUp(): void {
		parent::setUp();
		$this->results = $this->createMock(PracticalExamBoardResultsInterface::class);
		$this->user = $this->createMock(IUser::class);
	}

	private function controller(): PracticalExamResultsController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);

		return new PracticalExamResultsController(request: $this->createMock(IRequest::class), userSession: $session, results: $this->results, logger: new NullLogger());
	}

	public function testTheCoSignedResultsOfTheSitting(): void {
		$this->results->expects($this->once())->method('forSitting')->with('s-1')->willReturn([['assessmentId' => 'pa-1']]);

		$response = $this->controller()->index(examSittingId: ' s-1 ');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['results' => [['assessmentId' => 'pa-1']]], $response->getData());
	}

	public function testNoSessionNoSittingAndAFailedRead(): void {
		$this->user = null;
		$this->results->expects($this->once())->method('forSitting')->willThrowException(new RuntimeException('gone'));
		self::assertSame(401, $this->controller()->index(examSittingId: 's-1')->getStatus());

		$this->user = $this->createMock(IUser::class);
		self::assertSame(422, $this->controller()->index(examSittingId: '')->getStatus());

		$failed = $this->controller()->index(examSittingId: 's-1');
		self::assertSame(502, $failed->getStatus());
		self::assertSame(['error' => 'downstream_error'], $failed->getData());
	}
}
