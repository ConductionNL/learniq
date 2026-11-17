<?php

/**
 * Learniq values an assessment service reads from OpenRegister rows and app config.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Service plumbing, not a behavioural requirement: the shared
 *       reading of the assurance floor, rows and text values of the portal
 *       assessment services.
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal\Support;

/**
 * The assurance floor, the plain array of an OpenRegister result and the text
 * values of a row, for a portal assessment service. The using class declares
 * `APP_ID`, `MIN_ASSURANCE_KEY` and `ASSURANCE_ORDER` and holds `$appConfig`
 * (IAppConfig) and `$objectRow` (ObjectRow).
 *
 * @spec exclude Service plumbing, not a behavioural requirement.
 */
trait AssessmentRowValues {

	/**
	 * The assurance a school demands before it accepts an assessment.
	 *
	 * @return string One of the eIDAS levels; `basic` when unset or unknown.
	 */
	private function floor(): string {
		$declared = $this->appConfig->getValueString(self::APP_ID, self::MIN_ASSURANCE_KEY, 'basic');
		if (isset(self::ASSURANCE_ORDER[$declared]) === false) {
			return 'basic';
		}

		return $declared;
	}//end floor()

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $object An array or a serialisable entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		return $this->objectRow->asArray(object: $object);
	}//end toRow()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()

	/**
	 * The text, or null when it is empty.
	 *
	 * @param string $value The text.
	 *
	 * @return string|null
	 */
	private function orNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end trait
