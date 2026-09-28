<?php

/**
 * Learniq Timetable Connector Client
 *
 * The timetable-import job's call to the OpenConnector (integriq) source run
 * route, for a school WITHOUT planninq. Moved verbatim out of
 * {@see \OCA\Learniq\Timetabling\TimetableImportHandler} when that handler
 * gained its planninq path (sessions-from-planninq), so the handler keeps a
 * readable constructor. Its behaviour is unchanged, including the finding
 * recorded on the path constant below: the route it calls does not exist.
 * With planninq installed this class is not used at all; the job goes to
 * planninq through integriq's typed event instead.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-timetable-import-delegates-the-wire-protocol-to-openconnector-via-dataexchangejob
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

use OCA\Learniq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Calls the OpenConnector source run route for a timetable import.
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-timetable-import-delegates-the-wire-protocol-to-openconnector-via-dataexchangejob
 */
class TimetableConnectorClient {

	private const TARGET = 'timetable-import';

	/**
	 * The OpenConnector REST endpoint for triggering a source run — same
	 * path/contract shape as DataExchangeRunHandler's own
	 * OPENCONNECTOR_RUN_PATH (documented assumption, not verified against a
	 * live OpenConnector instance): for `direction: import` the response is
	 * expected to additionally carry a `records` array of raw external
	 * records, one per Zermelo/Untis/Xedule occurrence.
	 */
	/**
	 * Path AFTER the app segment; the segment is resolved at call time.
	 *
	 * 🔴 THE SEGMENT IS RIGHT AND THE ROUTE IS NOT. Resolving the app name
	 * through FleetAppId closed the half of this that a name-based check can
	 * see, and it is worth being explicit that it closed only that half.
	 *
	 * Verified 2026-09-09 against integriq `development` a5e43d8: there is no
	 * `api/sources/{id}/run` under either namespace. That app's entire
	 * `sources#` surface is `test`, `logs`, `tripCircuitBreaker` and
	 * `resetCircuitBreaker`; the run-shaped routes it does publish are
	 * `jobs#run`, `synchronizations#run` and `flows#run`. A source is READ BY a
	 * synchronization there, it is not a thing you run. The docblock above
	 * always said this path was an assumption rather than a verified contract.
	 *
	 * So this call still 404s, now on every instance rather than half of them,
	 * and the fix is a run endpoint or a switch to `synchronizations#run` —
	 * not another edit to the name. Same route, same conclusion, recorded at
	 * {@see \OCA\Learniq\Listener\DataExchangeRunHandler}.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_RUN_PATH = 'api/sources/%s/run';

	private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IURLGenerator $urlGenerator NC URL generator for internal requests.
	 * @param IAppConfig $appConfig NC app config for token lookup.
	 * @param IAppManager $appManager NC app manager. Resolving the fleet app id
	 *                                needs it, and it arrives as a dependency
	 *                                rather than out of the global server.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Call the OpenConnector REST API for the `timetable-import` connection.
	 *
	 * @param array<string,mixed> $payload Request payload (job scope).
	 *
	 * @return array<string,mixed>|null Response data, or null on failure.
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-timetable-import-delegates-the-wire-protocol-to-openconnector-via-dataexchangejob
	 */
	public function run(array $payload): ?array {
		$path = FleetAppId::path($this->appManager, 'integriq', sprintf(self::OPENCONNECTOR_RUN_PATH, self::TARGET));
		$url = $this->urlGenerator->getAbsoluteURL('/index.php' . $path);

		$apiToken = $this->appConfig->getValueString(
			app: 'learniq',
			key: self::OPENCONNECTOR_TOKEN_KEY,
			default: ''
		);

		$requestOptions = [
			'json' => $payload,
			'timeout' => 120,
		];

		if ($apiToken === '') {
			$this->logger->warning(
				'[TimetableConnectorClient] No OpenConnector API token configured '
				. '(learniq.openconnector_api_token); the call may fail with 401/403.'
			);
		}

		if ($apiToken !== '') {
			$requestOptions['headers'] = ['Authorization' => 'Bearer ' . $apiToken];
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post($url, $requestOptions);

			$body = json_decode($response->getBody(), true);
			if (is_array($body) === false) {
				$this->logger->error('[TimetableConnectorClient] OpenConnector returned non-JSON.');
				return null;
			}

			return $body;
		} catch (\Exception $e) {
			$this->logger->error(
				'[TimetableConnectorClient] OpenConnector call failed: {msg}',
				['msg' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end run()
}//end class
