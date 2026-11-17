<?php

/**
 * Learniq Portal Form Fields
 *
 * The fields a portal form posts to a public portal route: the whitelisted
 * body and single string parameters.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec exclude Controller plumbing, not a behavioural requirement: it reads
 *       the request parameters of a portal form.
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

/**
 * Reads a portal form's parameters. The using class holds `$request`
 * (IRequest) and declares `FIELDS`, the parameters its form may send.
 *
 * @spec exclude Controller plumbing, not a behavioural requirement.
 */
trait PortalFormFields {

	/**
	 * The whitelisted fields the form sent.
	 *
	 * @return array<string, mixed>
	 */
	private function body(): array {
		$body = [];
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$body[$field] = $value;
			}
		}

		return $body;
	}//end body()

	/**
	 * A request parameter as a trimmed string, '' when absent or not a string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function stringParam(string $name): string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end stringParam()
}//end trait
