<?php

/**
 * Learniq CredentialReissueService unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CredentialReissueService;
use OCA\Learniq\Service\CredentialSigningService;
use OCA\Learniq\Service\EuropassIssuer;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A run over a register-faithful store; signing and the Europass form are
 * doubles, the transition is recorded with the data it writes.
 */
class CredentialReissueServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Transitions fired: id, action, data and who ran them.
	 *
	 * @var array<int, array{id: string, action: string, data: array<string, mixed>, as: string}>
	 */
	private array $fired = [];

	/**
	 * Who the current call runs as.
	 *
	 * @var string
	 */
	private string $as = '';

	/**
	 * App config values written.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The service; credential ids in `$failing` make the transition throw.
	 *
	 * @param array<int, string> $failing Credential ids whose transition fails.
	 *
	 * @return CredentialReissueService
	 */
	private function service(array $failing = []): CredentialReissueService {
		$this->store = new RegisterFaithfulStore();
		$base = ['courseId' => 'c-bhv', 'learnerId' => 'l-1', 'kind' => 'certificate', 'issuedAt' => '2025-01-10T10:00:00+00:00', 'expiresAt' => '2027-01-10T10:00:00+00:00', 'tenant_id' => 't1', 'signature' => 'old', 'issuedBy' => 'Oude naam'];
		$this->store->rows['credential'] = [
			['id' => 'cr-1', 'lifecycle' => 'issued'] + $base,
			['id' => 'cr-2', 'lifecycle' => 'issued', 'walletOfferStatus' => 'claimed', 'reissueCount' => 1] + $base,
			['id' => 'cr-3', 'lifecycle' => 'revoked'] + $base,
			['id' => 'cr-4', 'lifecycle' => 'expired'] + $base,
			['id' => 'cr-5', 'lifecycle' => 'issued', 'reissueRunId' => 'run-1'] + $base,
		];
		$this->store->rows['school'] = [['id' => 's-1', 'name' => 'Opleider BV', 'tenant_id' => 't1']];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation): mixed {
				$this->as = $user->getUID();
				try {
					return $operation();
				} finally {
					$this->as = '';
				}
			}
		);

		$transitions = $this->createMock(TransitionEngine::class);
		$transitions->method('transition')->willReturnCallback(
			function (string $objectId, string $action, array $data = []) use ($failing): ObjectEntity {
				if (in_array($objectId, $failing, true) === true) {
					throw new RuntimeException('database gone');
				}

				$this->fired[] = ['id' => $objectId, 'action' => $action, 'data' => $data, 'as' => $this->as];
				return new ObjectEntity();
			}
		);

		$signer = $this->createMock(CredentialSigningService::class);
		$signer->method('sign')->willReturnCallback(
			static fn (array $credential): array => $credential + ['openbadges3Payload' => ['issuer' => ['name' => $credential['issuedBy']]], 'signature' => 'new', 'issuerDid' => 'did:web:new', 'verificationUrl' => 'https://x/verify']
		);
		$europass = $this->createMock(EuropassIssuer::class);
		$europass->method('withEuropass')->willReturnCallback(static fn (array $credential): array => $credential + ['edciPayload' => ['id' => 'urn:uuid:' . $credential['id']]]);

		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});

		return new CredentialReissueService(objects: $objects, transitions: $transitions, signer: $signer, europass: $europass, config: $config, logger: new NullLogger());
	}//end service()

	/**
	 * The HR officer.
	 *
	 * @return IUser
	 */
	private function hr(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr.jansen');

		return $user;
	}//end hr()

	/**
	 * The preview counts issued, revoked and expired credentials.
	 *
	 * @return void
	 */
	public function testThePreviewCountsWhatIsTouchedAndLeft(): void {
		self::assertSame(['issued' => 3, 'revoked' => 1, 'expired' => 1], $this->service()->preview(courseId: 'c-bhv'));
	}//end testThePreviewCountsWhatIsTouchedAndLeft()

	/**
	 * Only issued credentials outside this run are reissued, as the HR
	 * officer, with fresh signatures and the history, and nothing of the
	 * identity or dates in the written data.
	 *
	 * @return void
	 */
	public function testAReissueKeepsWhoAndWhenAndRecordsWhy(): void {
		$totals = $this->service()->run(courseId: 'c-bhv', runId: 'run-1', reason: 'Nieuwe tekst', actor: $this->hr());

		self::assertSame(['processed' => 2, 'skipped' => 3, 'failed' => 0], $totals);
		self::assertSame(['cr-1', 'cr-2'], array_column($this->fired, 'id'));
		foreach ($this->fired as $fired) {
			self::assertSame('reissue', $fired['action']);
			self::assertSame('hr.jansen', $fired['as']);
			self::assertSame(['new', 'Opleider BV', 'Nieuwe tekst', 'hr.jansen', 'run-1'], [$fired['data']['signature'], $fired['data']['issuedBy'], $fired['data']['reissueReason'], $fired['data']['reissuedBy'], $fired['data']['reissueRunId']]);
			self::assertSame([], array_intersect(array_keys($fired['data']), ['id', 'learnerId', 'courseId', 'issuedAt', 'expiresAt', 'kind']));
			self::assertSame([], array_diff(array_keys($fired['data']), CredentialReissueService::REISSUE_INPUTS));
		}

		self::assertSame([1, 2], array_map(static fn (array $f): int => $f['data']['reissueCount'], $this->fired));
		self::assertArrayHasKey('reissue_run_run-1', $this->config);
	}//end testAReissueKeepsWhoAndWhenAndRecordsWhy()

	/**
	 * A claimed wallet offer is cleared with a note; others are left alone.
	 *
	 * @return void
	 */
	public function testAWalletOfferIsClearedWithANote(): void {
		$this->service()->run(courseId: 'c-bhv', runId: 'run-2', reason: 'r', actor: $this->hr());
		$byId = array_column($this->fired, 'data', 'id');

		self::assertArrayHasKey('walletOfferStatus', $byId['cr-2']);
		self::assertNull($byId['cr-2']['walletOfferStatus']);
		self::assertStringContainsString('previous', str_replace('from before', 'previous', $byId['cr-2']['walletOfferNote']));
		self::assertArrayNotHasKey('walletOfferStatus', $byId['cr-1']);
	}//end testAWalletOfferIsClearedWithANote()

	/**
	 * One failure is counted and the run goes on.
	 *
	 * @return void
	 */
	public function testOneFailureDoesNotStopTheRun(): void {
		$totals = $this->service(failing: ['cr-1'])->run(courseId: 'c-bhv', runId: 'run-3', reason: 'r', actor: $this->hr());

		self::assertSame(['processed' => 2, 'skipped' => 2, 'failed' => 1], $totals);
		self::assertSame(['cr-2', 'cr-5'], array_column($this->fired, 'id'));
	}//end testOneFailureDoesNotStopTheRun()
}//end class
