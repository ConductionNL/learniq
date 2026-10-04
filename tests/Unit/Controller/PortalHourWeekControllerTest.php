<?php

/**
 * Tests for PortalHourWeekController.
 *
 * The verifier is the real one, over a known secret, so a forged or
 * wrong-audience assertion is refused by the code that runs in production.
 * It is the only credential this endpoint has: the route is `#[PublicPage]`,
 * because portaliq forwards without a Nextcloud session.
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
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalHourWeekController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalHourWeekApproval;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The endpoint takes the trainer from the assertion and nothing else.
 */
class PortalHourWeekControllerTest extends TestCase {

	private const SECRET = 'a-test-secret-of-sufficient-length';

	private const TRAINER = 'ee010027-0000-4000-8000-000000000001';

	private const WEEK = 'ee03002b-0000-4000-8000-000000000001';

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
	 * @return PortalHourWeekController
	 */
	private function controller(string $jwt, array $params, ?PortalOutcome $outcome = null, bool $throws = false): PortalHourWeekController {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($jwt);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$approvals = $this->createMock(PortalHourWeekApproval::class);
		$approvals->method('approve')->willReturnCallback(
			function (string $trainerRef, string $trust, array $body) use ($outcome, $throws): PortalOutcome {
				$this->calls[] = ['trainerRef' => $trainerRef, 'trust' => $trust, 'body' => $body];
				if ($throws === true) {
					throw new RuntimeException('SQLSTATE secret table name');
				}

				return ($outcome ?? new PortalOutcome(
					status: 200,
					body: ['hourWeekId' => self::WEEK, 'hoursApproved' => 30, 'lifecycle' => 'corrected', 'assuranceLevel' => 'basic']
				));
			}
		);

		return new PortalHourWeekController(
			request: $request,
			verifier: new PortalAssertionVerifier(secretOverride: self::SECRET),
			approvals: $approvals,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * A trainer's assertion passes the trainer and the session's trust on, and
	 * only the three whitelisted fields of her form. Anything else she might
	 * send, including who approved and how sure the school is, is dropped
	 * before the service sees it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function testATrainerAssertionReachesTheService(): void {
		$response = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: [
				'practicalTrainerId' => self::TRAINER,
				'hourWeekId' => self::WEEK,
				'hoursApproved' => 30,
				'note' => 'Donderdag twee uur eerder weg.',
				'approvedBy' => 'iemand-anders',
				'assuranceLevel' => 'high',
				'hoursSubmitted' => 99,
			]
		)->approve();

		self::assertSame(200, $response->getStatus());
		self::assertSame('corrected', $response->getData()['lifecycle']);
		self::assertSame(self::TRAINER, $this->calls[0]['trainerRef']);
		self::assertSame('low', $this->calls[0]['trust']);
		self::assertSame(
			['hourWeekId' => self::WEEK, 'hoursApproved' => 30, 'note' => 'Donderdag twee uur eerder weg.'],
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
			$response = $this->controller(jwt: $jwt, params: ['practicalTrainerId' => self::TRAINER, 'hourWeekId' => self::WEEK])->approve();
			self::assertSame(401, $response->getStatus(), $jwt);
			self::assertSame([], $this->calls);
		}
	}//end testAForgedAssertionIsRefused()

	/**
	 * Another audience's assertion, and one without the trainer claim, are
	 * refused. A pupil may enter her own hours; she may not approve them.
	 *
	 * @return void
	 */
	public function testAnotherAudienceIsRefused(): void {
		$response = $this->controller(
			jwt: self::assertion(['audience' => 'student', 'trust' => 'substantial']),
			params: ['practicalTrainerId' => self::TRAINER, 'hourWeekId' => self::WEEK]
		)->approve();
		self::assertSame(403, $response->getStatus());
		self::assertSame([], $this->calls);

		$response = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: ['hourWeekId' => self::WEEK]
		)->approve();
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
			params: ['practicalTrainerId' => self::TRAINER, 'hourWeekId' => self::WEEK],
			outcome: new PortalOutcome(status: 409, body: ['error' => 'already_decided'])
		)->approve();
		self::assertSame(409, $refused->getStatus());
		self::assertSame(['error' => 'already_decided'], $refused->getData());

		$broken = $this->controller(
			jwt: self::assertion(['audience' => 'praktijkopleider', 'trust' => 'low']),
			params: ['practicalTrainerId' => self::TRAINER, 'hourWeekId' => self::WEEK],
			throws: true
		)->approve();
		self::assertSame(502, $broken->getStatus());
		self::assertSame(['error' => 'downstream_error'], $broken->getData());
		self::assertStringNotContainsString('SQLSTATE', (string)json_encode($broken->getData()));
	}//end testRefusalsAndErrorsAreReportedPlainly()
}//end class
