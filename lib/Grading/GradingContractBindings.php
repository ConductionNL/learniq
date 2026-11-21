<?php

/**
 * Bindings of the Kernel contracts to their Grading implementations
 *
 * @category  Grading
 * @package   OCA\Learniq\Grading
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

namespace OCA\Learniq\Grading;

use OCA\Learniq\Kernel\Contracts\GradeFormulaEvaluatorInterface;
use OCA\Learniq\Kernel\Contracts\GradeVisibilityResolverInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Learniq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Grading
 * @package  OCA\Learniq\Grading
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec exclude Structural composition, no functional requirement.
 */
final class GradingContractBindings {

	/**
	 * Contract => the class that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BINDINGS = [
		GradeFormulaEvaluatorInterface::class => GradeFormulaEvaluator::class,
		GradeVisibilityResolverInterface::class => GradeVisibilityResolver::class,
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
