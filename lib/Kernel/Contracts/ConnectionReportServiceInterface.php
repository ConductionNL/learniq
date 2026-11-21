<?php

/**
 * Contract of ConnectionReportService
 *
 * The public surface of ConnectionReportService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of ConnectionReportService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConnectionReportServiceInterface {

	/**
	 * The connection key of the EUDI wallet row in lib/Settings/connections.json.
	 *
	 * @var string
	 */
	public const WALLET_KEY = 'eudi-wallet';

	/**
	 * The status last recorded for a connection (`configured`, `unconfigured`,
	 * `error` or `unavailable`), or null when nothing was recorded.
	 *
	 * @param string $key The connection key.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-setup-check-names-the-missing-wallet-token
	 */
	public function lastStatus(string $key): ?string;

	/**
	 * Record whether LTI tools can open: integriq is the LTI platform, so the
	 * row is available exactly when its launch event exists.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-the-connection-registry-says-whether-lti-works
	 */
	public function observeLti(): void;

	/**
	 * Record whether the timetable connection can work: integriq delivers the
	 * timetable into planninq, so it is available exactly when planninq is
	 * enabled. The daily job and a settings save call it before they report;
	 * the report itself only goes out when integriq is there.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-the-timetable-connection-is-available-when-planninq-and-integriq-are-installed
	 */
	public function observeTimetable(): void;

	/**
	 * Send the stored observation of every reported connection to integriq.
	 *
	 * It sends again on every run. Integriq refuses a report for a row it has
	 * not synced yet, and a resend is one event a day, so the next run lands.
	 * The message says since when the outcome holds, so a resend claims no
	 * fresh call. Without integriq nothing is sent or logged. A listener that
	 * throws is logged per connection and never escapes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
	 */
	public function reportObservations(): void;
}//end interface
