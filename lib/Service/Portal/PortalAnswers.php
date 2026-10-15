<?php

/**
 * Learniq Portal Answers
 *
 * Reads a portal form's answers field by field and remembers which were left
 * empty or are of the wrong kind, so a refusal names the fields to change.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/portal-refusals-name-their-fields/specs/portal-contribution/spec.md#requirement-a-portal-endpoint-refusal-names-the-fields-to-change
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

/**
 * A form's answers as read, and the 422 that names the ones to change.
 *
 * @spec openspec/changes/portal-refusals-name-their-fields/specs/portal-contribution/spec.md#requirement-a-portal-endpoint-refusal-names-the-fields-to-change
 */
final class PortalAnswers {

	/**
	 * Each answer read, trimmed, by field.
	 *
	 * @var array<string, string>
	 */
	private array $answers = [];

	/**
	 * The kind of value each refused answer needs, by field.
	 *
	 * @var array<string, string>
	 */
	private array $invalid = [];

	/**
	 * A text answer, trimmed, or '' when it is missing or not text.
	 *
	 * @param string $field The field.
	 * @param mixed  $value What the form sent.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-refusals-name-their-fields/specs/portal-contribution/spec.md#requirement-a-portal-endpoint-refusal-names-the-fields-to-change
	 */
	public function text(string $field, mixed $value): string {
		$text = '';
		if (is_string($value) === true) {
			$text = trim($value);
		}

		$this->answers[$field] = $text;
		return $text;
	}//end text()

	/**
	 * A whole number from `$min` to `$max`, or null: a number that is not
	 * whole is refused as `integer`, one outside the range as `range`.
	 *
	 * @param string $field The field.
	 * @param mixed  $value What the form sent, a number or its text.
	 * @param int    $min   The smallest number allowed.
	 * @param int    $max   The largest number allowed.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/portal-refusals-name-their-fields/specs/portal-contribution/spec.md#requirement-a-portal-endpoint-refusal-names-the-fields-to-change
	 */
	public function count(string $field, mixed $value, int $min, int $max): ?int {
		if (is_int($value) === true) {
			$value = (string)$value;
		}

		$text = $this->text(field: $field, value: $value);
		$number = filter_var($text, FILTER_VALIDATE_INT);
		if ($number === false) {
			$this->wrongKind(field: $field, kind: 'integer');
			return null;
		}

		if ($number < $min || $number > $max) {
			$this->wrongKind(field: $field, kind: 'range');
			return null;
		}

		return $number;
	}//end count()

	/**
	 * Refuse a filled-in answer as not of the kind the field needs, such as
	 * `date`. An empty answer stays refused as empty.
	 *
	 * @param string $field The field.
	 * @param string $kind  The kind of value it needs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-refusals-name-their-fields/specs/portal-contribution/spec.md#requirement-a-portal-endpoint-refusal-names-the-fields-to-change
	 */
	public function wrongKind(string $field, string $kind): void {
		if (($this->answers[$field] ?? '') !== '') {
			$this->invalid[$field] = $kind;
		}
	}//end wrongKind()

	/**
	 * The 422 that names the fields to change: `errors` holds each answer
	 * left empty (`''`, which the portal words as "<label> is required"),
	 * `invalid` each answer of the wrong kind. A refusal whose answers are
	 * all filled in and right names no field.
	 *
	 * @param string $error  The refusal's error code.
	 * @param string $reason The refusal's message key.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/portal-refusals-name-their-fields/specs/portal-contribution/spec.md#requirement-a-portal-endpoint-refusal-names-the-fields-to-change
	 */
	public function refused(string $error, string $reason): PortalOutcome {
		$body = ['error' => $error];
		$empty = array_keys(array_diff_key(array_filter($this->answers, static fn (string $answer): bool => $answer === ''), $this->invalid));
		if ($empty !== []) {
			$body['errors'] = array_fill_keys($empty, '');
		}

		if ($this->invalid !== []) {
			$body['invalid'] = $this->invalid;
		}

		return new PortalOutcome(status: 422, body: $body, reason: $reason);
	}//end refused()
}//end class
