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
 * Portaliq stores that secret SENSITIVE (encrypted at rest), so it is read
 * through `IAppConfig::getValueString()`, which decrypts it, exactly as
 * portaliq reads it. The legacy `IConfig::getAppValue()` returns the stored
 * ciphertext, and every HMAC check then failed (401 on every portal action,
 * 13-14 Oct 2026).
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

use OCP\IAppConfig;
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
	 * @param IAppConfig|null $config Source of portaliq's sensitive app value (read decrypted).
	 * @param string|null $secretOverride Plain secret for tests.
	 * @param LoggerInterface|null $logger Rejection reasons, debug level only.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?IAppConfig $config = null,
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

		$parts = $this->segments(jwt: $jwt);
		if ($parts === null) {
			return $this->reject(reason: 'malformed structure');
		}

		$problem = $this->signatureProblem(parts: $parts, secret: $secret);
		if ($problem !== null) {
			return $this->reject(reason: $problem);
		}

		return $this->acceptedClaims(cPart: $parts[1]);
	}//end verify()

	/**
	 * The three segments of a token, or null unless there are exactly three
	 * and none is empty.
	 *
	 * @param string $jwt The raw header value.
	 *
	 * @return array<int, string>|null
	 */
	private function segments(string $jwt): ?array {
		$parts = explode('.', $jwt);
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return null;
		}

		return $parts;
	}//end segments()

	/**
	 * What is wrong with the header and signature, or null when nothing is:
	 * the header's `alg` must be exactly HS256 and the HMAC must match
	 * (constant time).
	 *
	 * @param array<int, string> $parts  The header, claims and signature segments.
	 * @param string             $secret The signing secret.
	 *
	 * @return string|null
	 */
	private function signatureProblem(array $parts, string $secret): ?string {
		[$hPart, $cPart, $sPart] = $parts;

		if ($this->hasExpectedAlgorithm(hPart: $hPart) === false) {
			return 'unexpected algorithm';
		}

		$expected = $this->b64UrlEncode(bytes: hash_hmac('sha256', $hPart . '.' . $cPart, $secret, true));
		if (hash_equals($expected, $sPart) === false) {
			return 'signature mismatch';
		}

		return null;
	}//end signatureProblem()

	/**
	 * Whether the header segment is a JSON object whose `alg` is exactly HS256.
	 *
	 * @param string $hPart The header segment.
	 *
	 * @return bool
	 */
	private function hasExpectedAlgorithm(string $hPart): bool {
		$header = json_decode($this->b64UrlDecode(encoded: $hPart), true);

		return is_array($header) === true && ($header['alg'] ?? '') === self::ALG;
	}//end hasExpectedAlgorithm()

	/**
	 * The claims of a correctly signed token when they are a JSON object
	 * without a problem; otherwise the refusal.
	 *
	 * @param string $cPart The claims segment.
	 *
	 * @return array<string, mixed>|null
	 */
	private function acceptedClaims(string $cPart): ?array {
		$claims = json_decode($this->b64UrlDecode(encoded: $cPart), true);
		if (is_array($claims) === false) {
			return $this->reject(reason: 'malformed claims');
		}

		$problem = $this->claimProblem(claims: $claims);
		if ($problem !== null) {
			return $this->reject(reason: $problem);
		}

		return $claims;
	}//end acceptedClaims()

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

		if ($this->hasSubject(claims: $claims) === false) {
			return 'missing subject';
		}

		return $this->timeProblem(claims: $claims);
	}//end claimProblem()

	/**
	 * Whether `sub` is a non-empty string.
	 *
	 * @param array<string, mixed> $claims The decoded claims.
	 *
	 * @return bool
	 */
	private function hasSubject(array $claims): bool {
		$sub = ($claims['sub'] ?? null);

		return is_string($sub) === true && $sub !== '';
	}//end hasSubject()

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

		if ($this->hasPlausibleIat(claims: $claims, now: $now) === false) {
			return 'implausible iat';
		}

		return null;
	}//end timeProblem()

	/**
	 * Whether `iat` is an integer not in the future (beyond the leeway) and
	 * not after `exp`.
	 *
	 * @param array<string, mixed> $claims The decoded claims, `exp` already checked.
	 * @param int                  $now    The time the checks run at.
	 *
	 * @return bool
	 */
	private function hasPlausibleIat(array $claims, int $now): bool {
		$iat = ($claims['iat'] ?? null);

		return is_int($iat) === true && $iat <= ($now + self::IAT_LEEWAY) && $iat <= $claims['exp'];
	}//end hasPlausibleIat()

	/**
	 * Portaliq's dedicated signing secret, or null when it is missing or too
	 * short (verification then fails closed). Read through IAppConfig,
	 * non-lazy as portaliq stores it, so the sensitive value comes back
	 * decrypted.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	private function secret(): ?string {
		$secret = $this->configuredSecret();
		if ($secret === null || strlen($secret) < self::MIN_SECRET_LENGTH) {
			return null;
		}

		return $secret;
	}//end secret()

	/**
	 * The plain test secret when one is given, otherwise portaliq's stored
	 * value, or null when there is neither.
	 *
	 * @return string|null
	 */
	private function configuredSecret(): ?string {
		if ($this->secretOverride !== null || $this->config === null) {
			return $this->secretOverride;
		}

		return $this->config->getValueString(self::PORTALIQ_APP_ID, self::SECRET_KEY, '', lazy: false);
	}//end configuredSecret()

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
