<?php

/**
 * The practical exam receiver answers only a verified external-assessor assertion.
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
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalPracticalExamController;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalPracticalExamAssessmentInterface;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\PortalPracticalExamController
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
 */
class PortalPracticalExamControllerTest extends TestCase {

	private PortalAssertionVerifierInterface&MockObject $verifier;

	private PortalPracticalExamAssessmentInterface&MockObject $assessments;

	protected function setUp(): void {
		parent::setUp();
		$this->verifier = $this->createMock(PortalAssertionVerifierInterface::class);
		$this->assessments = $this->createMock(PortalPracticalExamAssessmentInterface::class);
	}

	/**
	 * @param array<string, mixed> $params The request parameters.
	 */
	private function controller(array $params): PortalPracticalExamController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('jwt');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		return new PortalPracticalExamController(request: $request, verifier: $this->verifier, assessments: $this->assessments);
	}

	public function testAnUnverifiedAssertionIsUnauthorisedAndReachesNothing(): void {
		$this->verifier->method('verify')->willReturn(null);
		$this->assessments->expects($this->never())->method('submit');
		$this->assessments->expects($this->never())->method('draft');

		self::assertSame(401, $this->controller(params: ['externalAssessorId' => 'ruud'])->submit()->getStatus());
		self::assertSame(401, $this->controller(params: ['externalAssessorId' => 'ruud'])->draft()->getStatus());
	}

	public function testAnotherAudienceOrNoAssessorIsForbidden(): void {
		$this->assessments->expects($this->never())->method('submit');
		$this->verifier->method('verify')->willReturnOnConsecutiveCalls(['audience' => 'praktijkopleider', 'trust' => 'low'], ['audience' => 'external-assessor', 'trust' => 'low']);

		self::assertSame(403, $this->controller(params: ['externalAssessorId' => 'ruud'])->submit()->getStatus());
		self::assertSame(403, $this->controller(params: [])->submit()->getStatus());
	}

	public function testTheFormReachesTheServiceWithTheTrustAndOnlyItsFields(): void {
		$this->verifier->method('verify')->willReturn(['audience' => 'external-assessor', 'trust' => 'substantial']);
		$this->assessments->expects($this->once())->method('submit')
			->with('ruud', 'substantial', ['examSittingId' => 's-1', 'learnerRef' => 'daan', 'criteria' => [['score' => 3]], 'lifecycle' => 'draft'])
			->willReturn(new PortalOutcome(status: 201, body: ['assessmentId' => 'pa-1']));

		$response = $this->controller(
			params: ['externalAssessorId' => 'ruud', 'examSittingId' => 's-1', 'learnerRef' => 'daan', 'criteria' => [['score' => 3]], 'lifecycle' => 'draft', 'assuranceLevel' => 'high']
		)->submit();

		self::assertSame(201, $response->getStatus());
		self::assertSame(['assessmentId' => 'pa-1'], $response->getData());
	}

	public function testTheDraftIsAskedForThisAssessorCandidateAndSitting(): void {
		$this->verifier->method('verify')->willReturn(['audience' => 'external-assessor', 'trust' => 'low']);
		$this->assessments->expects($this->once())->method('draft')->with('ruud', 's-1', 'daan')
			->willReturn(new PortalOutcome(status: 200, body: ['draft' => null]));

		$response = $this->controller(params: ['externalAssessorId' => 'ruud', 'examSittingId' => 's-1', 'learnerRef' => 'daan'])->draft();

		self::assertSame(['draft' => null], $response->getData());
	}
}
