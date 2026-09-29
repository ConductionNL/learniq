<?php

/**
 * Learniq cmi5 Launch Token Service
 *
 * Mints and verifies RS256 JWT launch tokens for cmi5 AU (Assignable Unit)
 * launches, and owns the instance key-pair they are signed with. This is a
 * legitimate PHP service per ADR-031 §"What apps SHOULD still write in PHP":
 * cryptographic signing cannot be expressed as schema metadata.
 *
 * Key storage: the private key PEM is encrypted with `ICrypto` and stored in
 * app config under {@see self::PRIVATE_KEY_NAME}; the public key PEM is stored
 * in plain text under {@see self::PUBLIC_KEY_NAME}. Minting is enabled once
 * both exist, which an admin provisions through `Cmi5KeyAdminController`.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#2-cmi5launchtokenservice
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use RuntimeException;
use Throwable;

/**
 * Mints and verifies RS256 JWT launch tokens for cmi5 AU launches.
 *
 * Claims per the cmi5 specification §8.2 (Launch Token): iss (app URL), sub
 * (learner uid), aud (lesson id), iat, exp (iat + 3600), jti (registration),
 * activityId (AU activity IRI) and registration.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class Cmi5LaunchTokenService {

	/**
	 * App-config key holding the ICrypto-encrypted private key PEM.
	 *
	 * @var string
	 */
	public const PRIVATE_KEY_NAME = 'learniq.cmi5.launch.private';

	/**
	 * App-config key holding the public key PEM.
	 *
	 * @var string
	 */
	public const PUBLIC_KEY_NAME = 'learniq.cmi5.launch.public';

	/**
	 * Token lifetime in seconds.
	 *
	 * @var int
	 */
	public const TOKEN_TTL_SECONDS = 3600;

	/**
	 * The app id the keys are stored under.
	 *
	 * @var string
	 */
	private const APP_ID = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ICrypto       $crypto       Nextcloud crypto service for the encrypted private key.
	 * @param IAppConfig    $appConfig    App config holding both key PEMs.
	 * @param IURLGenerator $urlGenerator Builds the issuer URL.
	 */
	public function __construct(
		private readonly ICrypto $crypto,
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Whether cmi5 launch token minting is available.
	 *
	 * Controllers MUST call this before mintLaunchToken() and answer HTTP 503
	 * with a human-readable body when false.
	 *
	 * @return bool True once both halves of the key-pair are stored.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#2-cmi5launchtokenservice
	 */
	public function isEnabled(): bool {
		return $this->storedPrivateKey() !== '' && $this->publicKeyPem() !== '';
	}//end isEnabled()

	/**
	 * Generate a fresh RSA-2048 key-pair and store it, replacing any old one.
	 *
	 * @return array{fingerprint: string, publicKey: string} The new public key and its SHA-256 fingerprint.
	 *
	 * @throws RuntimeException When openssl cannot generate or export the key.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#1-key-provisioning
	 */
	public function generateKeyPair(): array {
		$resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		if ($resource === false) {
			throw new RuntimeException('openssl could not generate the cmi5 launch key-pair');
		}

		$privatePem = '';
		if (openssl_pkey_export($resource, $privatePem) === false) {
			throw new RuntimeException('openssl could not export the cmi5 launch private key');
		}

		$details   = openssl_pkey_get_details($resource);
		$publicPem = (string)($details['key'] ?? '');
		if ($publicPem === '') {
			throw new RuntimeException('openssl could not export the cmi5 launch public key');
		}

		$this->appConfig->setValueString(
			app: self::APP_ID,
			key: self::PRIVATE_KEY_NAME,
			value: $this->crypto->encrypt($privatePem),
			sensitive: true
		);
		$this->appConfig->setValueString(app: self::APP_ID, key: self::PUBLIC_KEY_NAME, value: $publicPem);

		return ['fingerprint' => hash('sha256', $publicPem), 'publicKey' => $publicPem];
	}//end generateKeyPair()

	/**
	 * The stored public key and its fingerprint, or null when none is stored.
	 *
	 * @return array{fingerprint: string, publicKey: string}|null The key status.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#1-key-provisioning
	 */
	public function keyStatus(): ?array {
		$publicPem = $this->publicKeyPem();
		if ($publicPem === '') {
			return null;
		}

		return ['fingerprint' => hash('sha256', $publicPem), 'publicKey' => $publicPem];
	}//end keyStatus()

	/**
	 * Mint a cmi5 AU launch JWT (RS256).
	 *
	 * Always call isEnabled() first: without a stored key this throws.
	 *
	 * @param string $learnerId      The learner's Nextcloud user id.
	 * @param string $lessonId       The lesson UUID (the AU).
	 * @param string $registrationId A UUID identifying this launch attempt.
	 * @param string $activityId     The AU's xAPI activity IRI.
	 *
	 * @return string The signed compact JWT.
	 *
	 * @throws RuntimeException When no key is stored or signing fails.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#2-cmi5launchtokenservice
	 */
	public function mintLaunchToken(
		string $learnerId,
		string $lessonId,
		string $registrationId,
		string $activityId,
	): string {
		$privatePem = $this->decryptedPrivateKey();
		$now        = time();
		$header     = $this->base64UrlEncode(data: (string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
		$claims     = [
			'iss'          => $this->urlGenerator->getAbsoluteURL('/apps/learniq'),
			'sub'          => $learnerId,
			'aud'          => $lessonId,
			'iat'          => $now,
			'exp'          => $now + self::TOKEN_TTL_SECONDS,
			'jti'          => $registrationId,
			'activityId'   => $activityId,
			'registration' => $registrationId,
		];
		$payload    = $this->base64UrlEncode(data: (string)json_encode($claims));

		$signature = '';
		if (openssl_sign($header . '.' . $payload, $signature, $privatePem, OPENSSL_ALGO_SHA256) === false) {
			throw new RuntimeException('openssl could not sign the cmi5 launch token');
		}

		return $header . '.' . $payload . '.' . $this->base64UrlEncode(data: $signature);
	}//end mintLaunchToken()

	/**
	 * Verify a launch JWT and return its claims, or null when it is not valid.
	 *
	 * Valid means: three segments, header `alg` RS256, an RS256 signature that
	 * verifies against the stored public key, and an `exp` in the future.
	 *
	 * @param string $token The compact JWT.
	 *
	 * @return array<string, mixed>|null The claims, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
	 */
	public function verifyLaunchToken(string $token): ?array {
		$parts     = explode('.', $token);
		$publicPem = $this->publicKeyPem();
		if (count($parts) !== 3 || $publicPem === '') {
			return null;
		}

		$header = json_decode($this->base64UrlDecode(data: $parts[0]), true);
		if (is_array($header) === false || ($header['alg'] ?? '') !== 'RS256') {
			return null;
		}

		$verified = openssl_verify(
			$parts[0] . '.' . $parts[1],
			$this->base64UrlDecode(data: $parts[2]),
			$publicPem,
			OPENSSL_ALGO_SHA256
		);
		$claims   = json_decode($this->base64UrlDecode(data: $parts[1]), true);
		if ($verified !== 1 || is_array($claims) === false) {
			return null;
		}

		if ((int)($claims['exp'] ?? 0) <= time() || (string)($claims['sub'] ?? '') === '') {
			return null;
		}

		return $claims;
	}//end verifyLaunchToken()

	/**
	 * Wrap a launch JWT into the cmi5 `auth-token` the AU sends as `Authorization: Basic <auth-token>`.
	 *
	 * The cmi5 spec (§8.2.3) makes the AU send the auth-token verbatim as Basic credentials.
	 * Nextcloud base64-decodes every Basic header and, when the result has the
	 * `user:password` shape, attempts a login and answers 401 before any app
	 * code runs (`OC\User\Session::tryBasicAuthLogin`, caught in `index.php`).
	 * A bare JWT has that shape: its header decodes to `{"alg":"RS256",...`.
	 * The base64 of the JWT decodes to the JWT itself, whose alphabet
	 * (base64url plus `.`) never contains a colon, so Nextcloud sees no
	 * credentials, skips the login and lets the public LRS route run.
	 *
	 * @param string $launchToken The compact JWT from mintLaunchToken().
	 *
	 * @return string The auth-token handed out by the fetch URL.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#7-basic-auth-reachability
	 */
	public function authToken(string $launchToken): string {
		return base64_encode($launchToken);
	}//end authToken()

	/**
	 * Verify the credential an AU sends (Basic or Bearer) and return the launch claims, or null.
	 *
	 * Accepts the auth-token from authToken() and, for Bearer callers, the bare
	 * JWT. Standard base64 has no `.`, so a credential with exactly two dots is
	 * a bare JWT; anything else is decoded strictly first.
	 *
	 * @param string $credential The credential after the `Basic ` or `Bearer ` scheme.
	 *
	 * @return array<string, mixed>|null The claims, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#7-basic-auth-reachability
	 */
	public function verifyAuthToken(string $credential): ?array {
		$launchToken = $credential;
		if (substr_count($credential, '.') !== 2) {
			$decoded = base64_decode($credential, true);
			if ($decoded === false) {
				return null;
			}

			$launchToken = $decoded;
		}

		return $this->verifyLaunchToken(token: $launchToken);
	}//end verifyAuthToken()

	/**
	 * The encrypted private key as stored, or '' when absent.
	 *
	 * @return string The stored ciphertext.
	 */
	private function storedPrivateKey(): string {
		return $this->appConfig->getValueString(app: self::APP_ID, key: self::PRIVATE_KEY_NAME, default: '');
	}//end storedPrivateKey()

	/**
	 * The public key PEM as stored, or '' when absent.
	 *
	 * @return string The PEM.
	 */
	private function publicKeyPem(): string {
		return $this->appConfig->getValueString(app: self::APP_ID, key: self::PUBLIC_KEY_NAME, default: '');
	}//end publicKeyPem()

	/**
	 * Decrypt the stored private key.
	 *
	 * @return string The private key PEM.
	 *
	 * @throws RuntimeException When no key is stored or it cannot be decrypted.
	 */
	private function decryptedPrivateKey(): string {
		$stored = $this->storedPrivateKey();
		if ($stored === '') {
			throw new RuntimeException('No cmi5 launch key is provisioned');
		}

		try {
			return $this->crypto->decrypt($stored);
		} catch (Throwable $e) {
			throw new RuntimeException('The cmi5 launch key cannot be decrypted', 0, $e);
		}
	}//end decryptedPrivateKey()

	/**
	 * Base64url-encode without padding.
	 *
	 * @param string $data Raw bytes.
	 *
	 * @return string The encoded string.
	 */
	private function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}//end base64UrlEncode()

	/**
	 * Base64url-decode, tolerating missing padding.
	 *
	 * @param string $data The encoded string.
	 *
	 * @return string Raw bytes ('' when not decodable).
	 */
	private function base64UrlDecode(string $data): string {
		$decoded = base64_decode(strtr($data, '-_', '+/'), true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}//end base64UrlDecode()
}//end class
