<?php

/**
 * Assertions on the GuardResult a lifecycle guard returns.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use OCA\OpenRegister\Lifecycle\GuardResult;
use PHPUnit\Framework\Assert;

/**
 * A guard OpenRegister runs answers with a GuardResult (learniq#983). A denial
 * must carry a message, because OpenRegister shows it to the caller.
 */
trait GuardVerdicts {

	/**
	 * Assert the guard allowed the transition.
	 *
	 * @param GuardResult $verdict The guard's answer.
	 * @param string $context What the case is, prefixed to the failure message.
	 *
	 * @return void
	 */
	protected static function assertAllowed(GuardResult $verdict, string $context = ''): void {
		Assert::assertTrue($verdict->isAllowed(), trim($context . ' Expected the guard to allow, it denied: ' . (string)$verdict->getMessage()));
	}//end assertAllowed()

	/**
	 * Assert the guard denied the transition with a message for the caller.
	 *
	 * @param GuardResult $verdict The guard's answer.
	 * @param string $context What the case is, prefixed to the failure message.
	 *
	 * @return void
	 */
	protected static function assertDenied(GuardResult $verdict, string $context = ''): void {
		Assert::assertFalse($verdict->isAllowed(), trim($context . ' Expected the guard to deny, it allowed.'));
		Assert::assertNotSame('', trim((string)$verdict->getMessage()), trim($context . ' A denial must tell the caller why.'));
	}//end assertDenied()
}//end trait
