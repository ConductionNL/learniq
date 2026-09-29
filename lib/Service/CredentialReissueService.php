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
 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\AppInfo\Application;
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
 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */
class CredentialReissueService {

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
	 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
	 */
	public function preview(string $courseId): array {
		$counts = ['issued' => 0, 'revoked' => 0, 'expired' => 0];
		foreach ($this->credentials(courseId: $courseId, offset: 0, limit: 10000) as $credential) {
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
	 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
	 */
	public function summary(string $runId): ?array {
		$json = $this->config->getValueString(app: Application::APP_ID, key: self::RUN_KEY_PREFIX . $runId, default: '');
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
	 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
	 */
	public function run(string $courseId, string $runId, string $reason, IUser $actor): array {
		$totals = ['processed' => 0, 'skipped' => 0, 'failed' => 0];
		$offset = 0;
		$more = true;
		while ($more === true) {
			$batch = $this->credentials(courseId: $courseId, offset: $offset, limit: self::BATCH);
			foreach ($batch as $credential) {
				$outcome = $this->reissueOne(credential: $credential, runId: $runId, reason: $reason, actor: $actor);
				$totals[$outcome]++;
			}

			$more = count($batch) === self::BATCH;
			$offset += self::BATCH;
		}

		$this->config->setValueString(
			app: Application::APP_ID,
			key: self::RUN_KEY_PREFIX . $runId,
			value: (string)json_encode($totals + ['courseId' => $courseId, 'finishedAt' => $this->now()])
		);

		return $totals;
	}//end run()

	/**
	 * Reissue one credential: `processed`, `skipped` (not issued, or already
	 * in this run) or `failed`.
	 *
	 * @param array<string, mixed> $credential The credential.
	 * @param string               $runId      The run id.
	 * @param string               $reason     The reason.
	 * @param IUser                $actor      Who started the run.
	 *
	 * @return string
	 */
	private function reissueOne(array $credential, string $runId, string $reason, IUser $actor): string {
		if (($credential['lifecycle'] ?? 'issued') !== 'issued' || ($credential['reissueRunId'] ?? '') === $runId) {
			return 'skipped';
		}

		try {
			$data = $this->rebuilt(credential: $credential, runId: $runId, reason: $reason, actor: $actor);
			if ($data === null) {
				throw new RuntimeException('the credential could not be signed');
			}

			$this->objects->runAs(
				user: $actor,
				operation: fn () => $this->transitions->transition(objectId: (string)$credential['id'], action: 'reissue', data: $data)
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[CredentialReissueService] Credential {id} was not reissued in run {run}: {msg}',
				['id' => $credential['id'], 'run' => $runId, 'msg' => $exception->getMessage()]
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
	 * @param string               $runId      The run id.
	 * @param string               $reason     The reason.
	 * @param IUser                $actor      Who started the run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rebuilt(array $credential, string $runId, string $reason, IUser $actor): ?array {
		$current = $credential;
		$current['issuedBy'] = $this->issuerName(tenantId: (string)($credential['tenant_id'] ?? '')) ?? ($credential['issuedBy'] ?? '');
		unset($current['openbadges3Payload'], $current['signature'], $current['edciPayload']);

		$signed = $this->signer->sign(credential: $current);
		if ($signed === null) {
			return null;
		}

		$signed = $this->europass->withEuropass(credential: $signed);
		$data = [
			'openbadges3Payload' => $signed['openbadges3Payload'],
			'signature' => $signed['signature'],
			'issuerDid' => $signed['issuerDid'],
			'verificationUrl' => $signed['verificationUrl'] ?? ($credential['verificationUrl'] ?? null),
			'issuedBy' => $current['issuedBy'],
			'edciPayload' => $signed['edciPayload'] ?? ($credential['edciPayload'] ?? null),
			'reissuedAt' => $this->now(),
			'reissueCount' => ((int)($credential['reissueCount'] ?? 0)) + 1,
			'reissueReason' => $reason,
			'reissuedBy' => $actor->getUID(),
			'reissueRunId' => $runId,
		];

		if (in_array(($credential['walletOfferStatus'] ?? null), ['offered', 'claimed'], true) === true) {
			$data['walletOfferStatus'] = null;
			$date = substr($data['reissuedAt'], 0, 10);
			$data['walletOfferNote'] = 'The wallet holds the version from before the reissue of ' . $date . '. Offer the new version again.';
		}

		return $data;
	}//end rebuilt()

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
	 * @param string $courseId The course uuid.
	 * @param int    $offset   The offset.
	 * @param int    $limit    The page size.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function credentials(string $courseId, int $offset, int $limit): array {
		$rows = $this->objects->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'courseId' => $courseId],
				'limit' => $limit,
				'offset' => $offset,
			],
			_rbac: false,
			_multitenancy: false
		);

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
	}//end credentials()

	/**
	 * Now, as an ATOM date-time.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
