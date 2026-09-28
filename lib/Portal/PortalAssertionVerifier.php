<?php

/**
 * Learniq Portal Assertion Verifier
 *
 * When portaliq forwards an endpoint action server-to-server (ADR-046
 * contract v2, A6) it attaches an `X-Portal-Subject` header: a 60 second HS256
 * JWT carrying the resolved portal subject. This class verifies it. It is the
 * only identity source for a portal request to learniq (ADR-005).
 *
 * A copy of the fleet receiver (filinq's and shillinq's
 * `PortalAssertionVerifier`, after petstore's reference): hand-rolled HS256
 * with hash_equals, the same fail-closed checks, no portaliq import and no JWT
 * library, so the receiver stays self-contained and reviewable.
 *
 * One deliberate difference: the secret. Portaliq's
 * `PortalSessionService::__construct()` now builds its minter from the
 * dedicated `jwt_signing_secret` app value only and refuses to mint without
 * one (the older copies still fall back to the instance secret). A receiver
 * accepts only what the minter can produce, so this one reads the dedicated
 * secret only. The secret never comes from the request.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Verifies portaliq's `X-Portal-Subject` HS256 assertion, fail-closed.
 *
 * `verify()` returns the claims only when every check passes and null
 * otherwise. It never throws and never tells the caller which check failed.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
class PortalAssertionVerifier {

	/**
	 * The header portaliq attaches the assertion to.
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * The only accepted algorithm. An exact match refuses `none` and any
	 * asymmetric algorithm-confusion header in one check.
	 */
	private const ALG = 'HS256';

	/**
	 * The minting edge; portaliq stamps it on every assertion.
	 */
	private const ISSUER = 'portaliq';

	/**
	 * The `use` claim of an assertion. A portal session token has none, so it
	 * can never drive an endpoint.
	 */
	private const USE_ASSERTION = 'assertion';

	/**
	 * The app whose config holds the secret: portaliq's, the minter's.
	 */
	private const PORTALIQ_APP_ID = 'portaliq';

	/**
	 * The portaliq app value holding the dedicated signing secret.
	 */
	private const SECRET_KEY = 'jwt_signing_secret';

	/**
	 * Portaliq refuses to mint with a shorter secret; this refuses to accept.
	 */
	private const MIN_SECRET_LENGTH = 16;

	/**
	 * Tolerated clock skew on `iat`. The forward is a same-host hop.
	 */
	private const IAT_LEEWAY = 60;

	/**
	 * Constructor. Tests pass a plain secret instead of config.
	 *
	 * @param IConfig|null $config Source of portaliq's app value.
	 * @param string|null $secretOverride Plain secret for tests.
	 * @param LoggerInterface|null $logger Rejection reasons, debug level only.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?IConfig $config = null,
		private readonly ?string $secretOverride = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Verify an assertion and return its claims, or null.
	 *
	 * Checks, in order: three non-empty segments; header `alg` exactly HS256;
	 * the HMAC matches (constant time); the claims are a JSON object; `use`
	 * is `assertion`; `iss` is `portaliq`; `exp` is an integer in the future;
	 * `iat` is an integer not in the future and not after `exp`; `sub` is a
	 * non-empty string.
	 *
	 * @param string $jwt The raw header value.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function verify(string $jwt): ?array {
		$secret = $this->secret();
		if ($secret === null) {
			return $this->reject(reason: 'no usable signing secret');
		}

		$parts = explode('.', $jwt);
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return $this->reject(reason: 'malformed structure');
		}

		[$hPart, $cPart, $sPart] = $parts;

		$header = json_decode($this->b64UrlDecode(encoded: $hPart), true);
		if (is_array($header) === false || ($header['alg'] ?? '') !== self::ALG) {
			return $this->reject(reason: 'unexpected algorithm');
		}

		$expected = $this->b64UrlEncode(bytes: hash_hmac('sha256', $hPart . '.' . $cPart, $secret, true));
		if (hash_equals($expected, $sPart) === false) {
			return $this->reject(reason: 'signature mismatch');
		}

		$claims = json_decode($this->b64UrlDecode(encoded: $cPart), true);
		if (is_array($claims) === false) {
			return $this->reject(reason: 'malformed claims');
		}

		$problem = $this->claimProblem(claims: $claims);
		if ($problem !== null) {
			return $this->reject(reason: $problem);
		}

		return $claims;
	}//end verify()

	/**
	 * What is wrong with the claim set, or null when nothing is.
	 *
	 * @param array<string, mixed> $claims The decoded claims.
	 *
	 * @return string|null
	 */
	private function claimProblem(array $claims): ?string {
		if (($claims['use'] ?? '') !== self::USE_ASSERTION) {
			return 'not an assertion';
		}

		if (($claims['iss'] ?? '') !== self::ISSUER) {
			return 'unexpected issuer';
		}

		$sub = ($claims['sub'] ?? null);
		if (is_string($sub) === false || $sub === '') {
			return 'missing subject';
		}

		return $this->timeProblem(claims: $claims);
	}//end claimProblem()

	/**
	 * What is wrong with `exp` and `iat`, or null when nothing is.
	 *
	 * @param array<string, mixed> $claims The decoded claims.
	 *
	 * @return string|null
	 */
	private function timeProblem(array $claims): ?string {
		$now = time();
		$exp = ($claims['exp'] ?? null);
		if (is_int($exp) === false || $exp <= $now) {
			return 'expired or missing exp';
		}

		$iat = ($claims['iat'] ?? null);
		if (is_int($iat) === false || $iat > ($now + self::IAT_LEEWAY) || $iat > $exp) {
			return 'implausible iat';
		}

		return null;
	}//end timeProblem()

	/**
	 * Portaliq's dedicated signing secret, or null when it is missing or too
	 * short (verification then fails closed).
	 *
	 * @return string|null
	 */
	private function secret(): ?string {
		$secret = $this->secretOverride;
		if ($secret === null && $this->config !== null) {
			$secret = (string)$this->config->getAppValue(self::PORTALIQ_APP_ID, self::SECRET_KEY, '');
		}

		if ($secret === null || strlen($secret) < self::MIN_SECRET_LENGTH) {
			return null;
		}

		return $secret;
	}//end secret()

	/**
	 * Fail closed; log the reason at debug level only.
	 *
	 * @param string $reason Why the assertion was refused.
	 *
	 * @return null
	 */
	private function reject(string $reason): null {
		$this->logger?->debug('Learniq: portal assertion rejected', ['reason' => $reason]);

		return null;
	}//end reject()

	/**
	 * Unpadded base64url encode, portaliq's encoding.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64UrlEncode()

	/**
	 * Base64url decode, portaliq's decoding.
	 *
	 * @param string $encoded Encoded string.
	 *
	 * @return string
	 */
	private function b64UrlDecode(string $encoded): string {
		$pad = (4 - (strlen($encoded) % 4));
		if ($pad < 4) {
			$encoded .= str_repeat('=', $pad);
		}

		return (string)base64_decode(strtr($encoded, '-_', '+/'));
	}//end b64UrlDecode()
}//end class
