<?php

/**
 * Learniq Verwerkingsregister CSV Builder
 *
 * Builds the AVG Art. 30 verwerkingsregister artefact for the compliance audit
 * pack by calling OpenRegister's per-access processing-log endpoint
 * (`/api/avg/verwerkingen`, OR-PA-7/8) and rendering the platform output as CSV.
 *
 * Per ADR-022 learniq ships NO export engine of its own: this class applies no
 * Art. 30 column semantics, it is a flat passthrough of whatever OpenRegister
 * returns. When OpenRegister lacks the capability entirely (route missing,
 * endpoint unreachable, unexpected body) the artefact carries a loud
 * "platform capability missing" warning rather than being silently omitted.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use Throwable;

/**
 * Renders OpenRegister's AVG processing log as the audit pack's Art. 30 slice.
 *
 * @psalm-api
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class VerwerkingsregisterCsvBuilder {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Current request (its session is forwarded to OR).
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IURLGenerator $urlGenerator NC URL generator for the OR endpoint.
	 * @param CsvCellSanitizer $sanitizer CSV formula-injection neutraliser.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly CsvCellSanitizer $sanitizer,
	) {

	}//end __construct()

	/**
	 * Fetch the AVG Art. 30 verwerkingsregister for learniq's slice from
	 * OpenRegister and return it as CSV for inclusion in the audit pack.
	 *
	 * @param string $dateFrom ISO-8601 lower bound forwarded to the platform.
	 * @param string $dateTo ISO-8601 upper bound forwarded to the platform.
	 *
	 * @return string CSV content, or a loud warning when the platform capability is absent.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function build(string $dateFrom, string $dateTo): string {
		try {
			$url = $this->urlGenerator->linkToRoute('openregister.processingLog.index');
		} catch (Throwable $e) {
			return $this->warning(
				reason: 'OpenRegister does not expose the AVG processing-log capability '
					. '(route openregister.processingLog.index is not registered). '
					. 'The Art. 30 verwerkingsregister is provided by OpenRegister (OR-PA-7); '
					. 'install or upgrade OpenRegister (>= 0.2.14) to include it.'
			);
		}

		$absoluteUrl = $this->urlGenerator->getAbsoluteURL($url)
			. '?register=learniq&from=' . rawurlencode($dateFrom) . '&to=' . rawurlencode($dateTo);

		// Forward the caller's session so OpenRegister applies its own RBAC
		// (OR-PA-8). Learniq performs no access decision of its own here.
		$cookieHeader = (string)$this->request->getHeader('Cookie');

		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$absoluteUrl,
				[
					'headers' => [
						'Accept' => 'application/json',
						'Cookie' => $cookieHeader,
						'OCS-APIRequest' => 'true',
						'requesttoken' => (string)$this->request->getHeader('requesttoken'),
					],
					'nextcloud' => ['allow_local_address' => true],
				]
			);
		} catch (Throwable $e) {
			return $this->warning(
				reason: 'The OpenRegister AVG processing-log endpoint could not be reached (' . $e->getMessage() . '). '
					. 'The Art. 30 verwerkingsregister is provided by OpenRegister (OR-PA-7) and could not be included.'
			);
		}

		$body = (string)$response->getBody();
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || isset($decoded['results']) === false) {
			return $this->warning(
				reason: 'OpenRegister returned an unexpected response for the AVG processing log; '
					. 'the Art. 30 verwerkingsregister could not be included.'
			);
		}

		return $this->fromEntries(entries: (array)$decoded['results']);
	}//end build()

	/**
	 * Render the OpenRegister processing-log entries (platform output) as CSV.
	 *
	 * This is a flat passthrough of whatever fields OpenRegister returns; it
	 * applies no Art. 30 column semantics of its own (those are OR-PA-7's).
	 *
	 * @param array<int,array<string,mixed>> $entries Platform processing-log rows.
	 *
	 * @return string CSV content.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function fromEntries(array $entries): string {
		$handle = fopen('php://memory', 'r+');
		if ($handle === false) {
			return '';
		}

		if (empty($entries) === true) {
			fputcsv($handle, ['activity', 'register', 'schema', 'action', 'actor', 'subjectIdType', 'created']);
			rewind($handle);
			$csv = (string)stream_get_contents($handle);
			fclose($handle);
			return $csv;
		}

		// Header = the union of keys present on the first row (platform-defined).
		$first = (array)($entries[0] ?? []);
		$columns = array_keys($first);
		fputcsv($handle, $columns);

		foreach ($entries as $entry) {
			$row = (array)$entry;
			$cells = [];
			foreach ($columns as $column) {
				$value = ($row[$column] ?? '');
				if (is_array($value) === true) {
					$value = (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				}

				$cells[] = $this->sanitizer->sanitize(value: (string)$value);
			}

			fputcsv($handle, $cells);
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end fromEntries()

	/**
	 * Build the loud "platform capability missing" warning artefact content.
	 *
	 * @param string $reason Human-readable reason the register could not be included.
	 *
	 * @return string Warning CSV content.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function warning(string $reason): string {
		$handle = fopen('php://memory', 'r+');
		if ($handle === false) {
			return 'WARNING,' . $reason . "\n";
		}

		fputcsv($handle, ['status', 'message']);
		fputcsv($handle, ['PLATFORM CAPABILITY MISSING', $this->sanitizer->sanitize(value: $reason)]);
		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end warning()
}//end class
