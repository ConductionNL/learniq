<?php

/**
 * Learniq Shillinq Contribution Client
 *
 * The one place learniq talks to shillinq's school contribution contract
 * (shillinq change extracurricular-fee-to-shillinq, contract version 1):
 * `OCA\Shillinq\Service\ContributionRaiseService::raise()`, called in process
 * with the same array the `POST /apps/shillinq/api/contributions/raise`
 * endpoint takes. Duck-typed: the class is looked up by name, so learniq has
 * no `use` of a shillinq class and no `info.xml` dependency, and runs without
 * shillinq installed.
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
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Calls shillinq's contribution raise service in process, duck-typed.
 *
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
 */
class ShillinqContributionClient {

	public const SHILLINQ_APP = 'shillinq';
	public const RAISE_SERVICE = 'OCA\\Shillinq\\Service\\ContributionRaiseService';

	/**
	 * The contract allows at most this many recipients per call.
	 */
	public const MAX_RECIPIENTS = 200;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Tells whether shillinq is installed.
	 * @param ContainerInterface $container Resolves shillinq's service by class name.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether shillinq is installed and ships the raise service.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function isAvailable(): bool {
		return $this->appManager->isInstalled(self::SHILLINQ_APP) === true && class_exists(self::RAISE_SERVICE) === true;
	}//end isAvailable()

	/**
	 * Raise one chunk of contributions.
	 *
	 * Shillinq checks the session user's `payment.request` action itself and
	 * throws `InvalidArgumentException` (the endpoint's 400) or a
	 * `RuntimeException` whose message starts with `403`; both pass through.
	 *
	 * @param array<string, mixed> $payload The contract's request body.
	 *
	 * @return array<string, mixed> The contract's response body.
	 *
	 * @throws RuntimeException When shillinq is not available or answers with something that is not an array.
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function raise(array $payload): array {
		if ($this->isAvailable() === false) {
			throw new RuntimeException('Shillinq is not installed, so no contribution can be raised.');
		}

		$result = $this->container->get(self::RAISE_SERVICE)->raise($payload);
		if (is_array($result) === false) {
			throw new RuntimeException('Shillinq answered the raise with something that is not a result.');
		}

		return $result;
	}//end raise()
}//end class
