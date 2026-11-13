<?php

/**
 * Learniq Credential Reissue Service
 *
 * Reissues every issued certificate of a course in one action
 * (credentials-bulk-reissue). A run rebuilds each credential's Open Badges
 * payload and, where the kind has one, its Europass form from the current
 * course, issuer and key, signs both again, and fires the `reissue` self-loop
 * so OpenRegister records it in the audit trail and the learner is told. The
 * credential keeps its id, learner, course, issue date, expiry and kind.
 *
 * Idempotent per run: a credential that already carries the run id is
 * skipped, so a run that stopped half way can run again. One failed
 * credential is logged and does not stop the run. A credential offered to the
 * EUDI wallet gets its offer status cleared with a note, so staff can offer
 * the new version. The run summary is kept in app config under the run id.
 *
 * The transitions run as the staff member who started the run
 * (`ObjectService::runAs()`), so the audit trail names them.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\CredentialReissueServiceInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Preview and run a bulk reissue.
 *
 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */
class CredentialReissueService implements CredentialReissueServiceInterface {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'credential';

	/**
	 * Credentials read per batch.
	 */
	public const BATCH = 200;

	/**
	 * Prefix of the app config key that holds a run summary.
	 */
	public const RUN_KEY_PREFIX = 'reissue_run_';

	/**
	 * The fields the `reissue` transition writes.
	 */
	public const REISSUE_INPUTS = [
		'openbadges3Payload', 'signature', 'issuerDid', 'verificationUrl', 'issuedBy', 'edciPayload',
		'reissuedAt', 'reissueCount', 'reissueReason', 'reissuedBy', 'reissueRunId',
		'walletOfferStatus', 'walletOfferNote',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService            $objects     OpenRegister object access.
	 * @param TransitionEngine         $transitions OpenRegister lifecycle transitions.
	 * @param CredentialSigningService $signer      Rebuilds and signs the Open Badges payload.
	 * @param EuropassIssuer           $europass    Rebuilds and signs the Europass form.
	 * @param IAppConfig               $config      Holds run summaries.
	 * @param LoggerInterface          $logger      Logs a failed credential.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly TransitionEngine $transitions,
		private readonly CredentialSigningService $signer,
		private readonly EuropassIssuer $europass,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * How many credentials of the course a reissue would touch and leave.
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return array{issued: int, revoked: int, expired: int}
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
	 */
	public function preview(string $courseId): array {
		$counts = ['issued' => 0, 'revoked' => 0, 'expired' => 0];
		foreach ($this->credentials(courseId: $courseId, page: new CredentialPage(offset: 0, limit: 10000)) as $credential) {
			$state = (string)($credential['lifecycle'] ?? 'issued');
			if (array_key_exists($state, $counts) === true) {
				$counts[$state]++;
			}
		}

		return $counts;
	}//end preview()

	/**
	 * The stored summary of a run, or null.
	 *
	 * @param string $runId The run id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
	 */
	public function summary(string $runId): ?array {
		$json = $this->config->getValueString(app: AppIdentity::APP_ID, key: self::RUN_KEY_PREFIX . $runId, default: '');
		$summary = json_decode($json, true);
		if (is_array($summary) === false) {
			return null;
		}

		return $summary;
	}//end summary()

	/**
	 * Run a reissue over every issued credential of the course, in batches.
	 *
	 * @param string $courseId The course uuid.
	 * @param string $runId    The run id.
	 * @param string $reason   The reason given.
	 * @param IUser  $actor    The staff member who started the run.
	 *
	 * @return array{processed: int, skipped: int, failed: int}
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
	 */
	public function run(string $courseId, string $runId, string $reason, IUser $actor): array {
		$run = new CredentialReissueRun(runId: $runId, decision: new ReissueDecision(actor: $actor, reason: $reason));
		$totals = $this->reissueAll(courseId: $courseId, run: $run);

		$this->config->setValueString(
			app: AppIdentity::APP_ID,
			key: self::RUN_KEY_PREFIX . $runId,
			value: (string)json_encode($totals + ['courseId' => $courseId, 'finishedAt' => $this->now()])
		);

		return $totals;
	}//end run()

	/**
	 * Reissue every issued credential of the course, a batch at a time.
	 *
	 * @param string               $courseId The course uuid.
	 * @param CredentialReissueRun $run      The run.
	 *
	 * @return array{processed: int, skipped: int, failed: int}
	 */
	private function reissueAll(string $courseId, CredentialReissueRun $run): array {
		$totals = ['processed' => 0, 'skipped' => 0, 'failed' => 0];
		$offset = 0;
		$more = true;
		while ($more === true) {
			$batch = $this->credentials(courseId: $courseId, page: new CredentialPage(offset: $offset, limit: self::BATCH));
			foreach ($batch as $credential) {
				$outcome = $this->reissueOne(credential: $credential, run: $run);
				$totals[$outcome]++;
			}

			$more = count($batch) === self::BATCH;
			$offset += self::BATCH;
		}

		return $totals;
	}//end reissueAll()

	/**
	 * Reissue one credential: `processed`, `skipped` (not issued, or already
	 * in this run) or `failed`.
	 *
	 * @param array<string, mixed> $credential The credential.
	 * @param CredentialReissueRun $run        The run.
	 *
	 * @return string
	 */
	private function reissueOne(array $credential, CredentialReissueRun $run): string {
		if (($credential['lifecycle'] ?? 'issued') !== 'issued' || ($credential['reissueRunId'] ?? '') === $run->runId) {
			return 'skipped';
		}

		try {
			$data = $this->rebuilt(credential: $credential, run: $run);
			if ($data === null) {
				throw new RuntimeException('the credential could not be signed');
			}

			$this->objects->runAs(
				user: $run->decision->actor,
				operation: fn () => $this->transitions->transition(objectId: (string)$credential['id'], action: 'reissue', data: $data)
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[CredentialReissueService] Credential {id} was not reissued in run {run}: {msg}',
				['id' => $credential['id'], 'run' => $run->runId, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}

		return 'processed';
	}//end reissueOne()

	/**
	 * The transition data: freshly signed payloads and the history fields.
	 * Identity and dates are passed to the signer as they are and never
	 * written back.
	 *
	 * @param array<string, mixed> $credential The credential.
	 * @param CredentialReissueRun $run        The run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rebuilt(array $credential, CredentialReissueRun $run): ?array {
		$current = $this->currentCredential(credential: $credential);
		$signed = $this->signer->sign(credential: $current);
		if ($signed === null) {
			return null;
		}

		$signed = $this->europass->withEuropass(credential: $signed);
		$data = $this->signedFields(signed: $signed, credential: $credential);
		$data['issuedBy'] = $current['issuedBy'];
		$data['edciPayload'] = $signed['edciPayload'] ?? ($credential['edciPayload'] ?? null);
		$data += $run->historyFields(previousCount: (int)($credential['reissueCount'] ?? 0), now: $this->now());

		return $this->withWalletNote(credential: $credential, data: $data);
	}//end rebuilt()

	/**
	 * The credential as it is signed again: the tenant's current School name
	 * as issuer (else the stored one), without the old signed payloads.
	 *
	 * @param array<string, mixed> $credential The credential.
	 *
	 * @return array<string, mixed>
	 */
	private function currentCredential(array $credential): array {
		$current = $credential;
		$current['issuedBy'] = $this->issuerName(tenantId: (string)($credential['tenant_id'] ?? '')) ?? ($credential['issuedBy'] ?? '');
		unset($current['openbadges3Payload'], $current['signature'], $current['edciPayload']);

		return $current;
	}//end currentCredential()

	/**
	 * The freshly signed fields; the verification URL falls back to the stored one.
	 *
	 * @param array<string, mixed> $signed     The signed credential.
	 * @param array<string, mixed> $credential The stored credential.
	 *
	 * @return array<string, mixed>
	 */
	private function signedFields(array $signed, array $credential): array {
		return [
			'openbadges3Payload' => $signed['openbadges3Payload'],
			'signature' => $signed['signature'],
			'issuerDid' => $signed['issuerDid'],
			'verificationUrl' => $signed['verificationUrl'] ?? ($credential['verificationUrl'] ?? null),
		];
	}//end signedFields()

	/**
	 * A wallet holding the previous version is told to fetch the new one:
	 * its offer status is cleared and a dated note added.
	 *
	 * @param array<string, mixed> $credential The stored credential.
	 * @param array<string, mixed> $data       The transition data.
	 *
	 * @return array<string, mixed>
	 */
	private function withWalletNote(array $credential, array $data): array {
		if (in_array(($credential['walletOfferStatus'] ?? null), ['offered', 'claimed'], true) === true) {
			$data['walletOfferStatus'] = null;
			$date = substr($data['reissuedAt'], 0, 10);
			$data['walletOfferNote'] = 'The wallet holds the version from before the reissue of ' . $date . '. Offer the new version again.';
		}

		return $data;
	}//end withWalletNote()

	/**
	 * The tenant's School name, the issuer on a newly issued credential, or null.
	 *
	 * @param string $tenantId The tenant.
	 *
	 * @return string|null
	 */
	private function issuerName(string $tenantId): ?string {
		$rows = $this->objects->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => 'school', 'tenant_id' => $tenantId], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		if ($rows === []) {
			return null;
		}

		$row = $rows[0];
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false || (string)($row['name'] ?? '') === '') {
			return null;
		}

		return (string)$row['name'];
	}//end issuerName()

	/**
	 * A page of the course's credentials.
	 *
	 * @param string         $courseId The course uuid.
	 * @param CredentialPage $page     From where, and how many.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function credentials(string $courseId, CredentialPage $page): array {
		$rows = $this->objects->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'courseId' => $courseId],
				'limit' => $page->limit,
				'offset' => $page->offset,
			],
			_rbac: false,
			_multitenancy: false
		);

		return $this->credentialRows(rows: $rows);
	}//end credentials()

	/**
	 * The found credentials as arrays, each with its id; anything that is not one is left out.
	 *
	 * @param array<int, mixed> $rows What findAll() answered.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function credentialRows(array $rows): array {
		$credentials = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
				$credentials[] = $row;
			}
		}

		return $credentials;
	}//end credentialRows()

	/**
	 * Now, as an ATOM date-time.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
