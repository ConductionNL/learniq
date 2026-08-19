<?php

/**
 * Scholiq LTI AGS Pull Client
 *
 * Transport collaborator for `LtiAgsScorePollJob`: performs the authenticated
 * HTTP call against OpenConnector's `events-cloudevents` pull surface and
 * normalises the response into `{messages, cursor}`. Keeping the transport in
 * its own class leaves the job with only the sweep orchestration (which
 * messages were created/skipped, and when the cursor advances).
 *
 * Auth note (see `LtiAgsScorePollJob`'s class docblock): OpenConnector's
 * `EventsController::pull()` requires an authenticated Nextcloud session, so
 * this client sends HTTP Basic auth using the configured NC username
 * (`openconnector_api_user`) plus the SAME `openconnector_api_token` value
 * reused as the app-password — not a bearer token.
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
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads pending AGS CloudEvent messages from OpenConnector's pull endpoint.
 *
 * @psalm-api
 *
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.1
 */
class LtiAgsPullClient {

	/**
	 * The real (verified at HEAD) OpenConnector `events-cloudevents` pull
	 * endpoint — REQ-LTI-003 / retrofit-2026-05-24-events-cloudevents
	 * task 3. Unlike the launch endpoint this one genuinely exists.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_PULL_PATH = '/apps/openconnector/api/events/subscriptions/%s/pull';

	/**
	 * App-config key for the OpenConnector internal API token. Same key
	 * `DataExchangeRunHandler`/`LtiToolPlacementController` already use.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

	/**
	 * App-config key for the NC username the pull request authenticates as
	 * (Basic auth, see the class docblock auth note).
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_USER_KEY = 'openconnector_api_user';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IURLGenerator $urlGenerator NC URL generator for internal requests.
	 * @param IAppConfig $appConfig NC app config for credential lookup.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Pull pending AGS messages from OpenConnector for a subscription.
	 *
	 * @param string $subscriptionId The scholiq-owned event_subscription UUID.
	 * @param string $cursor The last-seen event_message cursor ('' for a first sweep).
	 *
	 * @return array{messages: array<int,mixed>, cursor: string|null}|null The pull result, or null on failure.
	 *
	 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.1
	 */
	public function pull(string $subscriptionId, string $cursor): ?array {
		$path = sprintf(self::OPENCONNECTOR_PULL_PATH, rawurlencode($subscriptionId));
		$query = ['limit' => 100];
		if ($cursor !== '') {
			$query['cursor'] = $cursor;
		}

		$url = $this->urlGenerator->getAbsoluteURL('/index.php' . $path) . '?' . http_build_query($query);

		$requestOptions = ['timeout' => 30];

		$credentials = $this->resolveBasicAuth();
		if ($credentials !== null) {
			// See the class docblock auth note: EventsController::pull() requires
			// an authenticated NC session, not a bearer token — Basic auth
			// with an app-password is the correct cross-app mechanism here.
			$requestOptions['auth'] = $credentials;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url, $requestOptions);

			$body = json_decode($response->getBody(), true);
			if (is_array($body) === false) {
				$this->logger->error('[LtiAgsPullClient] OpenConnector returned non-JSON for pull.');
				return null;
			}

			$messages = [];
			if (is_array($body['messages'] ?? null) === true) {
				$messages = $body['messages'];
			}

			$pullCursor = null;
			if (is_string($body['cursor'] ?? null) === true) {
				$pullCursor = $body['cursor'];
			}

			return [
				'messages' => $messages,
				'cursor' => $pullCursor,
			];
		} catch (Throwable $exception) {
			$this->logger->error(
				'[LtiAgsPullClient] OpenConnector pull call failed: {msg}',
				['msg' => $exception->getMessage()]
			);
			return null;
		}//end try

	}//end pull()

	/**
	 * Resolve the configured Basic-auth credential pair.
	 *
	 * Warns (once per sweep) when the pair is incomplete, so an operator can
	 * tell a 401/403 apart from an unconfigured integration.
	 *
	 * @return array{0: string, 1: string}|null The [user, password] pair, or null when unconfigured.
	 */
	private function resolveBasicAuth(): ?array {
		$apiUser = $this->appConfig->getValueString(app: Application::APP_ID, key: self::OPENCONNECTOR_USER_KEY, default: '');
		$apiToken = $this->appConfig->getValueString(app: Application::APP_ID, key: self::OPENCONNECTOR_TOKEN_KEY, default: '');

		if ($apiUser !== '' && $apiToken !== '') {
			return [$apiUser, $apiToken];
		}

		$this->logger->warning(
			'[LtiAgsPullClient] OpenConnector API user/token not fully configured '
			. '(scholiq.openconnector_api_user / scholiq.openconnector_api_token); '
			. 'the pull call may fail with 401/403.'
		);

		return null;
	}//end resolveBasicAuth()
}//end class
