<?php

/**
 * Contract of IntegriqExchangeClient
 *
 * The public surface of IntegriqExchangeClient that other components call, so they depend
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

use OCA\Learniq\Exception\ExchangeRequestRefusedException;
use OCA\Learniq\Exception\IntegriqUnavailableException;

/**
 * Contract of IntegriqExchangeClient.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntegriqExchangeClientInterface {

	/**
	 * Whether integriq is enabled and new enough to carry exchange jobs.
	 *
	 * @return bool True when a request can be made.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	public function isAvailable(): bool;

	/**
	 * Ask integriq to carry one exchange job.
	 *
	 * @param string                    $target      The exchange target, such as `leerplicht`.
	 * @param string                    $direction   export, import or sync.
	 * @param string                    $ownerRef    The row that caused the job, as `<schema>/<uuid>`.
	 * @param array<string, mixed>      $scope       Selectors and target parameters, never personal data.
	 * @param string|null               $mappingSlug The integriq mapping, such as `learniq-leerplicht-export-melding`.
	 * @param string                    $requestedBy The requesting user, or `system`.
	 * @param string                    $name        A label for integriq's job list.
	 * @param array<string, mixed>|null $history     Migration only: a finished job's history.
	 *
	 * @return string The integriq job id.
	 *
	 * @throws IntegriqUnavailableException    When integriq cannot carry it.
	 * @throws ExchangeRequestRefusedException When integriq refused it.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	public function requestJob(
		string $target,
		string $direction,
		string $ownerRef,
		array $scope,
		?string $mappingSlug,
		string $requestedBy,
		string $name = '',
		?array $history = null,
	): string;

	/**
	 * Ask integriq to store one of learniq's own mappings, upserted by slug.
	 *
	 * @param string               $slug        The slug, starting with `learniq-`.
	 * @param string               $name        A label.
	 * @param string               $description What the mapping does.
	 * @param array<string, mixed> $mapping     Output key to source path or Twig template.
	 *
	 * @return string The integriq mapping id.
	 *
	 * @throws IntegriqUnavailableException    When integriq cannot carry it.
	 * @throws ExchangeRequestRefusedException When integriq refused it.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
	 */
	public function requestMapping(string $slug, string $name, string $description, array $mapping): string;
}//end interface
