<?php

/**
 * Bindings of the Kernel contracts to their Listener implementations
 *
 * @category  Listener
 * @package   OCA\Learniq\Listener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural composition, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\SessionChangeNoticeHandlerInterface;
use OCA\Learniq\Kernel\Contracts\SupportRequestSubmitHandlerInterface;
use OCA\Learniq\Kernel\Contracts\WerkprocesAssessmentLearnerStampInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Learniq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec exclude Structural composition, no functional requirement.
 */
final class ListenerContractBindings {

	/**
	 * Contract => the class that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BINDINGS = [
		SessionChangeNoticeHandlerInterface::class => SessionChangeNoticeHandler::class,
		SupportRequestSubmitHandlerInterface::class => SupportRequestSubmitHandler::class,
		WerkprocesAssessmentLearnerStampInterface::class => WerkprocesAssessmentLearnerStamp::class,
	];

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $implementation) {
			$context->registerServiceAlias($contract, $implementation);
		}
	}//end register()
}//end class
