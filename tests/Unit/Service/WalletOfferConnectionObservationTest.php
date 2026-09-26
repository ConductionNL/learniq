<?php

/**
 * What a wallet offer records for integriq's connection registry.
 *
 * WalletOfferDelegationService records one observation per offer on the
 * `eudi-wallet` connection. These tests pin the status and the reason for each
 * way an offer ends, and pin that the guard's own answer does not change
 * whether a reporter is there or not.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use Exception;
use OCA\Learniq\Service\ConnectionReportService;
use OCA\Learniq\Service\WalletOfferDelegationService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the connection observation in WalletOfferDelegationService::check().
 *
 * @covers \OCA\Learniq\Service\WalletOfferDelegationService
 * @uses   \OCA\Learniq\Support\FleetAppId
 */
class WalletOfferConnectionObservationTest extends TestCase {

	/**
	 * Every observation the reporter double received, as [key, status, reason].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $observed = [];

	/**
	 * Build the service with a client that answers as given.
	 *
	 * @param string                       $token    The stored API token.
	 * @param IResponse|Exception|null     $answer   What the client answers, or null for no call.
	 * @param ConnectionReportService|null $reporter The reporter, or none.
	 *
	 * @return WalletOfferDelegationService
	 */
	private function service(string $token, IResponse|Exception|null $answer, ?ConnectionReportService $reporter): WalletOfferDelegationService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($token);

		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://learniq.example' . $path);

		$client = $this->createMock(originalClassName: IClient::class);
		if ($answer instanceof Exception) {
			$client->method('post')->willThrowException($answer);
		} elseif ($answer instanceof IResponse) {
			$client->method('post')->willReturn($answer);
		}

		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new WalletOfferDelegationService(
			clientService: $clientService,
			urlGenerator: $urlGenerator,
			appConfig: $appConfig,
			appManager: $this->createMock(originalClassName: IAppManager::class),
			logger: new NullLogger(),
			connectionReports: $reporter,
		);
	}//end service()

	/**
	 * A reporter double that records every observation.
	 *
	 * @return ConnectionReportService
	 */
	private function reporter(): ConnectionReportService {
		$this->observed = [];
		$reporter = $this->createMock(originalClassName: ConnectionReportService::class);
		$reporter->expects($this->never())->method('reportObservations');
		$reporter->method('observe')->willReturnCallback(
			function (string $key, string $status, string $reason): void {
				$this->observed[] = [$key, $status, $reason];
			}
		);

		return $reporter;
	}//end reporter()

	/**
	 * A response whose body is the given string.
	 *
	 * @param string $body The body.
	 *
	 * @return IResponse
	 */
	private function response(string $body): IResponse {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()

	/**
	 * The transition context for an issued badge.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		return [
			'object' => [
				'id' => 'credential-1',
				'kind' => 'badge',
				'learnerId' => 'learner-1',
				'walletOfferStatus' => null,
				'openbadges3Payload' => ['credentialSubject' => ['id' => 'urn:learniq:learner:learner-1']],
			],
			'transition' => 'offerToWallet',
			'from' => 'issued',
			'to' => 'issued',
		];
	}//end context()

	/**
	 * The ways an offer ends, with the answer, the guard result and the observation.
	 *
	 * @return array<string, array{0: string, 1: callable(self): (IResponse|Exception|null), 2: bool, 3: string, 4: string}>
	 */
	public static function outcomes(): array {
		$offer = json_encode(['credentialOfferUri' => 'https://integriq.example/index.php/apps/integriq/api/eudi/credential-offers/offer-uuid-1']);

		return [
			'the offer reached integriq' => [
				'token-abc',
				static fn (self $test): IResponse => $test->response(body: (string)$offer),
				true,
				'configured',
				'The last wallet offer reached integriq.',
			],
			'no token is set' => [
				'',
				static fn (self $test): ?IResponse => null,
				false,
				'unconfigured',
				'No integriq API token is set, so the last wallet offer was not sent.',
			],
			'the call threw' => [
				'token-abc',
				static fn (self $test): Exception => new Exception('Client error: 401 Unauthorized'),
				false,
				'error',
				'The last wallet offer to integriq failed: Client error: 401 Unauthorized',
			],
			'the answer was not JSON' => [
				'token-abc',
				static fn (self $test): IResponse => $test->response(body: '<html>'),
				false,
				'error',
				'Integriq answered the last wallet offer without JSON.',
			],
			'the answer had no offer' => [
				'token-abc',
				static fn (self $test): IResponse => $test->response(body: '{"offerUrl": "x"}'),
				false,
				'error',
				'Integriq answered the last wallet offer without a usable offer reference.',
			],
		];
	}//end outcomes()

	/**
	 * Each outcome records exactly one observation on the wallet connection.
	 *
	 * @param string   $token  The stored API token.
	 * @param callable $answer Builds what the client answers.
	 * @param bool     $passes Whether the guard lets the transition through.
	 * @param string   $status The recorded status.
	 * @param string   $reason The recorded reason.
	 *
	 * @return void
	 */
	#[DataProvider('outcomes')]
	public function testEachOutcomeRecordsOneObservation(string $token, callable $answer, bool $passes, string $status, string $reason): void {
		$context = $this->context();

		$result = $this->service(token: $token, answer: $answer($this), reporter: $this->reporter())->check($context);

		$this->assertSame(expected: $passes, actual: $result);
		$this->assertSame(expected: [['eudi-wallet', $status, $reason]], actual: $this->observed);
	}//end testEachOutcomeRecordsOneObservation()

	/**
	 * The guard answers and writes the same with or without a reporter.
	 *
	 * @param string   $token  The stored API token.
	 * @param callable $answer Builds what the client answers.
	 *
	 * @return void
	 */
	#[DataProvider('outcomes')]
	public function testTheGuardAnswersTheSameWithoutAReporter(string $token, callable $answer): void {
		$withContext = $this->context();
		$withoutContext = $this->context();

		$with = $this->service(token: $token, answer: $answer($this), reporter: $this->reporter())->check($withContext);
		$without = $this->service(token: $token, answer: $answer($this), reporter: null)->check($withoutContext);

		unset($withContext['object']['walletOfferedAt'], $withoutContext['object']['walletOfferedAt']);
		$this->assertSame(expected: $with, actual: $without);
		$this->assertSame(expected: $withContext, actual: $withoutContext);
	}//end testTheGuardAnswersTheSameWithoutAReporter()
}//end class
