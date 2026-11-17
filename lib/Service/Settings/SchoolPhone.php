<?php

/**
 * Learniq SchoolPhone
 *
 * The school's phone number for questions about a certificate, as the
 * administrator stores it in the app settings. The public verification page
 * shows it (certificate-verification-and-key-rotation).
 *
 * @category  Service
 * @package   OCA\Learniq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Settings;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\SchoolPhoneInterface;
use OCP\IAppConfig;

/**
 * Reads the school's phone number from the app config.
 */
class SchoolPhone implements SchoolPhoneInterface {

	/**
	 * The app config key.
	 *
	 * @var string
	 */
	public const KEY = 'schoolPhone';

	/**
	 * The longest value read as a phone number.
	 *
	 * @var integer
	 */
	public const MAX_LENGTH = 32;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The stored phone number, or an empty string when none is set or it is not a phone number.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
	 */
	public function phone(): string {
		return self::normalise(stored: $this->appConfig->getValueString(AppIdentity::APP_ID, self::KEY, ''));
	}//end phone()

	/**
	 * A stored value as a phone number: trimmed, digits with + ( ) - . and spaces, at least one digit.
	 *
	 * The page is public, so anything else (a URL, markup, a long text) reads as no number.
	 *
	 * @param string $stored The stored value.
	 *
	 * @return string The number, or an empty string.
	 *
	 * @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
	 */
	public static function normalise(string $stored): string {
		$value = trim($stored);
		if ($value === '' || strlen($value) > self::MAX_LENGTH) {
			return '';
		}

		if (preg_match('/^\+?[0-9 ().\-]+$/', $value) !== 1 || preg_match('/[0-9]/', $value) !== 1) {
			return '';
		}

		return $value;
	}//end normalise()
}//end class
