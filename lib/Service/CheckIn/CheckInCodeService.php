<?php

/**
 * Learniq Check-in Code Service
 *
 * The code a learner sends to check in (attendance-self-check-in). It is never
 * stored: it is the first eight base32 characters of an HMAC-SHA256 of the
 * window id and a step, keyed with a learniq secret kept in app config and
 * made on first use. In `rotating-qr` mode the step is the current
 * thirty-second slot, and the current and the previous slot are accepted; in
 * `link` mode the step is 0, so one code holds for the whole window.
 *
 * A learniq secret rather than Nextcloud's instance secret: a leak or a
 * rotation of this key then touches check-in codes only (the open point in
 * design.md, decided here and named in the PR).
 *
 * @category Service
 * @package  OCA\Learniq\Service\CheckIn
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
 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CheckIn;

use OCA\Learniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;

/**
 * Makes and checks check-in codes.
 *
 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
 */
class CheckInCodeService {

	/**
	 * Seconds one rotating code lives.
	 */
	public const STEP_SECONDS = 30;

	/**
	 * The app config key of the signing secret.
	 */
	private const SECRET_KEY = 'check_in_code_secret';

	/**
	 * Characters of a code.
	 */
	private const LENGTH = 8;

	/**
	 * RFC 4648 base32 alphabet.
	 */
	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * Constructor.
	 *
	 * @param IConfig       $config Nextcloud config, for the secret.
	 * @param ISecureRandom $random Makes the secret on first use.
	 * @param ITimeFactory  $time   The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly ISecureRandom $random,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The code that is valid now for a window.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 * @param string $mode     `rotating-qr` or `link`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#scenario-an-old-photo-of-the-code-does-not-work
	 */
	public function current(string $windowId, string $mode): string {
		return $this->codeFor(windowId: $windowId, step: $this->step(mode: $mode));
	}//end current()

	/**
	 * Whether a code is valid now: the current step, or the previous one in
	 * rotating mode.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 * @param string $mode     `rotating-qr` or `link`.
	 * @param string $code     The code the learner sent.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#scenario-an-old-photo-of-the-code-does-not-work
	 */
	public function verify(string $windowId, string $mode, string $code): bool {
		$code = strtoupper(trim($code));
		if ($code === '' || $windowId === '') {
			return false;
		}

		$step = $this->step(mode: $mode);
		$steps = [$step];
		if ($step > 0) {
			$steps[] = $step - 1;
		}

		foreach ($steps as $candidate) {
			if (hash_equals($this->codeFor(windowId: $windowId, step: $candidate), $code) === true) {
				return true;
			}
		}

		return false;
	}//end verify()

	/**
	 * Seconds until the current rotating code changes.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
	 */
	public function secondsLeft(): int {
		return self::STEP_SECONDS - ($this->time->getTime() % self::STEP_SECONDS);
	}//end secondsLeft()

	/**
	 * The code for a window and step.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 * @param int    $step     The time step, 0 in link mode.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
	 */
	public function codeFor(string $windowId, int $step): string {
		$mac = hash_hmac('sha256', $windowId . '|' . $step, $this->secret(), true);

		return substr($this->base32(bytes: $mac), 0, self::LENGTH);
	}//end codeFor()

	/**
	 * The step for a mode: the thirty-second slot, or 0 for a link.
	 *
	 * @param string $mode `rotating-qr` or `link`.
	 *
	 * @return int
	 */
	private function step(string $mode): int {
		if ($mode === 'link') {
			return 0;
		}

		return intdiv($this->time->getTime(), self::STEP_SECONDS);
	}//end step()

	/**
	 * The signing secret, made once and kept in app config.
	 *
	 * @return string
	 */
	private function secret(): string {
		$secret = $this->config->getAppValue(Application::APP_ID, self::SECRET_KEY, '');
		if ($secret === '') {
			$secret = $this->random->generate(64);
			$this->config->setAppValue(Application::APP_ID, self::SECRET_KEY, $secret);
		}

		return $secret;
	}//end secret()

	/**
	 * RFC 4648 base32 without padding.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function base32(string $bytes): string {
		$bits = '';
		foreach (str_split($bytes) as $byte) {
			$bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
		}

		$out = '';
		foreach (str_split($bits, 5) as $chunk) {
			$out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
		}

		return $out;
	}//end base32()
}//end class
