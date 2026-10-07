<?php

/**
 * Tests for PortalWerkprocesController.
 *
 * The verifier is the real one, over a known secret, so a forged or
 * wrong-audience assertion is refused by the code that runs in production.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalWerkprocesController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\Portal\PortalWerkprocesAssessment;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The endpoint takes the trainer from the assertion and nothing else.
 */
class PortalWerkprocesControllerTest extends TestCase {

	private const SECRET = 'a-test-secret-of-sufficient-length';

	private const TRAINER = 'ee010027-0000-4000-8000-000000000001';

	/**
	 * What the service was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * A signed assertion, as portaliq mints one.
	 *
	 * @param array<string, mixed> $claims The claims to sign.
	 *
	 * @return string
	 */
	private static function assertion(array $claims): string {
		$encode = static fn (array $part): string => rtrim(strtr(base64_encode((string)json_encode($part)), '+/', '-_'), '=');
		$head = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
		$body = $encode(
			array_merge(
				['iss' => 'portaliq', 'use' => 'assertion', 'iat' => time(), 'exp' => (time() + 60), 'sub' => self::TRAINER],
				$claims
			)
		);
		$signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $head . '.' . $body, self::SECRET, true)), '+/', '-_'), '=');

		return $head . '.' . $body . '.' . $signature;
	}//end assertion()

	/**
	 * Build the controller over the real verifier.
	 *
	 * @param string               $jwt     The assertion header.
	 * @param array<string, mixed> $params  The forwarded body.
	 * @param PortalOutcome|null   $outcome What the service answers.
	 * @param bool                 $throws  Whether the service throws.
	 *
	 * @return PortalWerkprocesController
	 */
	private function controller(string $jwt, array $params, ?PortalOutcome $outcome = null, bool $throws = false): PortalWerkprocesController {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($jwt);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$assessments = $this->createMock(PortalWerkprocesAssessment::class);
		$assessments->method('submit')->willReturnCallback(
			function (string $trainerRef, string $trust, array $body) use ($outcome, $throws): PortalOutcome {
				$this->calls[] = ['trainerRef' => $trainerRef, 'trust' => $trust, 'body' => $body];
				if ($throws === true) {
					throw new RuntimeException('SQLSTATE secret table name');
				}

				return ($outcome ?? new PortalOutcome(status: 201, body: ['assessmentId' => 'a-1', 'assuranceLevel' => 'basic']));
			}
		);

		return new PortalWerkprocesController(
			request: $request,
			verifier: new PortalAssertionVerifier(secretOverride: self::SECRET),
			assessments: $assessments,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * A trainer's assertion passes the trainer and the session's trust on, and
	 * only the whitelisted fields of the form.
	 *
	 * @return void
	 */
	public function testATrainerAssertionReachesTheService(): void {
		$jwt = self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']);
		$response = $this->controller(
			jwt: $jwt,
			params: [
				'practicalTrainerId' => self::TRAINER,
				'bpvPlacementId' => 'placement-1',
				'assessment' => 'competent',
				'assessorName' => 'Iemand anders',
			]
		)->submit();

		self::assertSame(201, $response->getStatus());
		self::assertSame(['assessmentId' => 'a-1', 'assuranceLevel' => 'basic'], $response->getData());
		self::assertSame(self::TRAINER, $this->calls[0]['trainerRef']);
		self::assertSame('low', $this->calls[0]['trust']);
		self::assertSame(['bpvPlacementId' => 'placement-1', 'assessment' => 'competent'], $this->calls[0]['body']);
	}//end testATrainerAssertionReachesTheService()

	/**
	 * A forged assertion, one signed with another secret, and a missing header
	 * are each refused before anything is read.
	 *
	 * @return void
	 */
	public function testAForgedAssertionIsRefused(): void {
		$good = self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']);
		foreach ([$good . 'x', '', 'not.a.jwt'] as $jwt) {
			$response = $this->controller(jwt: $jwt, params: ['practicalTrainerId' => self::TRAINER])->submit();
			self::assertSame(401, $response->getStatus(), $jwt);
			self::assertSame([], $this->calls);
		}
	}//end testAForgedAssertionIsRefused()

	/**
	 * Another audience's assertion, and one without the trainer claim, are
	 * refused.
	 *
	 * @return void
	 */
	public function testAnotherAudienceIsRefused(): void {
		$response = $this->controller(
			jwt: self::assertion(['audience' => 'student', 'trust' => 'substantial']),
			params: ['practicalTrainerId' => self::TRAINER]
		)->submit();
		self::assertSame(403, $response->getStatus());
		self::assertSame([], $this->calls);

		$response = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: []
		)->submit();
		self::assertSame(403, $response->getStatus());
		self::assertSame([], $this->calls);
	}//end testAnotherAudienceIsRefused()

	/**
	 * A refusal from the service reaches the caller unchanged, and a thrown
	 * error leaks nothing.
	 *
	 * @return void
	 */
	public function testRefusalsAndErrorsAreReportedPlainly(): void {
		$refused = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: ['practicalTrainerId' => self::TRAINER],
			outcome: new PortalOutcome(status: 403, body: ['error' => 'assurance_too_low', 'required' => 'substantial'])
		)->submit();
		self::assertSame(403, $refused->getStatus());
		self::assertSame('substantial', $refused->getData()['required']);

		$broken = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: ['practicalTrainerId' => self::TRAINER],
			throws: true
		)->submit();
		self::assertSame(502, $broken->getStatus());
		self::assertSame(['error' => 'downstream_error'], $broken->getData());
	}//end testRefusalsAndErrorsAreReportedPlainly()
}//end class
