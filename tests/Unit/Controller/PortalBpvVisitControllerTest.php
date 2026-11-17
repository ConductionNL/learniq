<?php

/**
 * Tests for PortalBpvVisitController.
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
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalBpvVisitController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalVisitAnswer;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The endpoint takes the trainer from the assertion and nothing else.
 */
#[CoversClass(PortalBpvVisitController::class)]
#[UsesClass(PortalAssertionVerifier::class)]
#[UsesClass(PortalOutcome::class)]
class PortalBpvVisitControllerTest extends TestCase {

	private const SECRET = 'a-test-secret-of-sufficient-length';

	private const TRAINER = 'ee010027-0000-4000-8000-000000000001';

	private const VISIT = 'ee030050-0000-4000-8000-000000000001';

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
	 * @return PortalBpvVisitController
	 */
	private function controller(string $jwt, array $params, ?PortalOutcome $outcome = null, bool $throws = false): PortalBpvVisitController {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($jwt);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$answers = $this->createMock(PortalVisitAnswer::class);
		$answers->method('answer')->willReturnCallback(
			function (string $trainerRef, array $body) use ($outcome, $throws): PortalOutcome {
				$this->calls[] = ['trainerRef' => $trainerRef, 'body' => $body];
				if ($throws === true) {
					throw new RuntimeException('SQLSTATE secret table name');
				}

				return ($outcome ?? new PortalOutcome(
					status: 200,
					body: ['bpvVisitId' => self::VISIT, 'lifecycle' => 'planned', 'plannedAt' => '2026-10-13T10:00:00+02:00']
				));
			}
		);

		return new PortalBpvVisitController(
			request: $request,
			verifier: new PortalAssertionVerifier(secretOverride: self::SECRET),
			answers: $answers,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * A trainer's assertion passes the trainer on, and only the three
	 * whitelisted fields of her form: a planned moment or a state she might
	 * send is dropped before the service sees it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
	 */
	public function testATrainerAssertionReachesTheService(): void {
		$response = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: [
				'practicalTrainerId' => self::TRAINER,
				'bpvVisitId' => self::VISIT,
				'outcome' => 'accept',
				'trainerResponse' => 'Prima.',
				'plannedAt' => '2030-01-01T00:00:00Z',
				'lifecycle' => 'done',
				'schoolCoachId' => 'iemand-anders',
			]
		)->answer();

		self::assertSame(200, $response->getStatus());
		self::assertSame('planned', $response->getData()['lifecycle']);
		self::assertSame(self::TRAINER, $this->calls[0]['trainerRef']);
		self::assertSame(
			['bpvVisitId' => self::VISIT, 'outcome' => 'accept', 'trainerResponse' => 'Prima.'],
			$this->calls[0]['body']
		);
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
			$response = $this->controller(jwt: $jwt, params: ['practicalTrainerId' => self::TRAINER, 'bpvVisitId' => self::VISIT])->answer();
			self::assertSame(401, $response->getStatus(), $jwt);
			self::assertSame([], $this->calls);
		}
	}//end testAForgedAssertionIsRefused()

	/**
	 * Another audience's assertion, and one without the trainer claim, are
	 * refused. A pupil may not agree a visit date for her company.
	 *
	 * @return void
	 */
	public function testAnotherAudienceIsRefused(): void {
		$response = $this->controller(
			jwt: self::assertion(['audience' => 'student', 'trust' => 'substantial']),
			params: ['practicalTrainerId' => self::TRAINER, 'bpvVisitId' => self::VISIT]
		)->answer();
		self::assertSame(403, $response->getStatus());
		self::assertSame([], $this->calls);

		$response = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: ['bpvVisitId' => self::VISIT]
		)->answer();
		self::assertSame(403, $response->getStatus());
		self::assertSame([], $this->calls);
	}//end testAnotherAudienceIsRefused()

	/**
	 * A refusal from the service reaches the caller unchanged, and a thrown
	 * error leaks nothing: a public route never returns an internal message.
	 *
	 * @return void
	 */
	public function testRefusalsAndErrorsAreReportedPlainly(): void {
		$refused = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: ['practicalTrainerId' => self::TRAINER, 'bpvVisitId' => self::VISIT],
			outcome: new PortalOutcome(status: 409, body: ['error' => 'not_proposed'])
		)->answer();
		self::assertSame(409, $refused->getStatus());
		self::assertSame(['error' => 'not_proposed'], $refused->getData());

		$broken = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: ['practicalTrainerId' => self::TRAINER, 'bpvVisitId' => self::VISIT],
			throws: true
		)->answer();
		self::assertSame(502, $broken->getStatus());
		self::assertSame(['error' => 'downstream_error'], $broken->getData());
		self::assertStringNotContainsString('SQLSTATE', (string)json_encode($broken->getData()));
	}//end testRefusalsAndErrorsAreReportedPlainly()
}//end class
