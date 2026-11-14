<?php

/**
 * Learniq AppealTerm
 *
 * The exam board's appeal term in days, an app setting each school sets from
 * its own examination regulations.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Settings
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

namespace OCA\Learniq\Service\Settings;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\AppealTermInterface;
use OCP\IAppConfig;

/**
 * Reads the appeal term (`examBoardAppealDays`). An unset, empty or
 * out-of-range value reads as the default of 42 days, the six-week term of
 * the CBE.
 *
 * @spec openspec/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-the-appeal-deadline-from-the-schools-appeal-term
 */
class AppealTerm implements AppealTermInterface {

	/**
	 * The app config key.
	 */
	public const KEY = 'examBoardAppealDays';

	/**
	 * The longest term the setting accepts.
	 */
	public const MAX_DAYS = 365;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The appeal term in days.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
	 */
	public function days(): int {
		return self::normalise(stored: $this->appConfig->getValueString(AppIdentity::APP_ID, self::KEY, ''));
	}//end days()

	/**
	 * The term a stored value stands for: a whole number from 1 to 365, else the default.
	 *
	 * @param string $stored The stored value.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
	 */
	public static function normalise(string $stored): int {
		$value = trim($stored);
		if (preg_match('/^\d{1,3}$/', $value) !== 1 || (int)$value < 1 || (int)$value > self::MAX_DAYS) {
			return self::DEFAULT_DAYS;
		}

		return (int)$value;
	}//end normalise()
}//end class
