<?php

/**
 * TextValue: a payload value read as a string.
 *
 * Payload values arrive as mixed. Many readers take one as text the same
 * way: a string as is (or trimmed), anything else as the empty string.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Learniq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared conversion helper: it carries the value reading of the callers' own specs and adds no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Learniq\Support;

/**
 * Reads a mixed value as a string, '' for anything that is not one.
 *
 * @spec exclude shared conversion helper: it carries the value reading of the callers' own specs and adds no behaviour of its own
 */
final class TextValue {

	/**
	 * The value when it is a string, '' otherwise.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 *
	 * @spec exclude shared conversion helper: it carries the value reading of the callers' own specs and adds no behaviour of its own
	 */
	public function orEmpty(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end orEmpty()

	/**
	 * The value trimmed when it is a string, '' otherwise.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 *
	 * @spec exclude shared conversion helper: it carries the value reading of the callers' own specs and adds no behaviour of its own
	 */
	public function trimmedOrEmpty(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end trimmedOrEmpty()
}//end class
