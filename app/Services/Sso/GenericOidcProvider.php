<?php

namespace App\Services\Sso;

use App\Exceptions\Sso\InvalidIdTokenException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

/**
 * A provider-agnostic OpenID Connect Socialite driver.
 *
 * Rather than hard-coding one IdP's endpoints, it reads them from the provider's
 * discovery document (`.well-known/openid-configuration`), so a single env
 * configuration works against any conformant OIDC provider (Okta, Microsoft
 * Entra ID, Google Workspace, Auth0, Keycloak, …). Socialite performs the OAuth2
 * authorization-code exchange; user claims are read from the userinfo endpoint.
 *
 * As defence-in-depth, when the token response carries an id_token it is
 * validated (signature via the provider JWKS, issuer, audience, expiry) and its
 * subject must match the UserInfo subject before the claims are trusted — see
 * {@see self::validateIdToken()}. This is optional (config `sso.oidc
 * .validate_id_token`) because a conformant provider need not return an id_token
 * at all, in which case UserInfo-over-TLS with a confidential client remains the
 * trust anchor.
 */
class GenericOidcProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * OIDC separates scopes with spaces, not the OAuth2 default comma.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * The OIDC scopes requested by default; `openid` is required for OIDC and
     * `profile`/`email` yield the name and address used for provisioning.
     *
     * @var array<int, string>
     */
    protected $scopes = ['openid', 'profile', 'email'];

    /**
     * The provider's discovery document URL.
     */
    protected string $discoveryUrl = '';

    /**
     * Point the driver at the provider's discovery document.
     */
    public function setDiscoveryUrl(string $discoveryUrl): static
    {
        $this->discoveryUrl = $discoveryUrl;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->discovery()['authorization_endpoint'], $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl(): string
    {
        return $this->discovery()['token_endpoint'];
    }

    /**
     * {@inheritdoc}
     *
     * Claims come from the UserInfo endpoint, called over TLS with the access
     * token obtained via the authorization-code exchange (a confidential client
     * authenticating with its secret, state-protected by Socialite). This is the
     * same model Socialite's built-in providers use; {@see self::userInstance()}
     * additionally cross-checks a returned id_token against these claims.
     *
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->discovery()['userinfo_endpoint'], [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'nickname' => $user['preferred_username'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => $user['picture'] ?? null,
        ]);
    }

    /**
     * {@inheritdoc}
     *
     * Validate the id_token (when present) before the mapped user is built, so a
     * token whose subject, issuer, or audience disagrees with the exchange — or
     * whose signature does not verify against the provider JWKS — aborts the
     * sign-in rather than trusting the UserInfo claims.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $user
     */
    #[\Override]
    protected function userInstance(array $response, array $user): User
    {
        $this->validateIdToken($response, $user);

        return parent::userInstance($response, $user);
    }

    /**
     * Cross-check a returned id_token against the exchange and UserInfo claims.
     *
     * Skipped entirely when disabled by config or when the provider returns no
     * id_token (UserInfo-over-TLS then remains the trust anchor). Otherwise the
     * token's signature is verified against the provider JWKS and its standard
     * claims (issuer, audience, expiry) are checked, and its `sub` must equal the
     * UserInfo subject — defeating a misbehaving or compromised UserInfo response
     * that returns a different account than the one the IdP actually signed for.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $user
     */
    protected function validateIdToken(array $response, array $user): void
    {
        if (! config('sso.oidc.validate_id_token', true)) {
            return;
        }

        $idToken = $response['id_token'] ?? null;

        if (! is_string($idToken) || $idToken === '') {
            return;
        }

        // Verifies the signature against the JWKS and rejects an expired token.
        $claims = JWT::decode($idToken, JWK::parseKeySet($this->jwks(), 'RS256'));

        $expectedIssuer = $this->discovery()['issuer'] ?? null;

        throw_if(filled($expectedIssuer) && ($claims->iss ?? null) !== $expectedIssuer, InvalidIdTokenException::class, 'The id_token issuer does not match the provider.');

        throw_unless(in_array($this->clientId, (array) ($claims->aud ?? []), true), InvalidIdTokenException::class, 'The id_token audience does not include this client.');

        throw_if(($claims->sub ?? null) !== ($user['sub'] ?? null), InvalidIdTokenException::class, 'The id_token subject does not match the UserInfo subject.');
    }

    /**
     * Fetch and cache the provider's JSON Web Key Set for id_token verification.
     *
     * Cached for an hour alongside the discovery document; providers publish
     * signing keys under a stable `jwks_uri` and rotate them infrequently.
     *
     * @return array<string, mixed>
     */
    protected function jwks(): array
    {
        return Cache::remember(
            'sso.oidc.jwks:'.$this->discoveryUrl,
            now()->addHour(),
            fn (): array => json_decode((string) $this->getHttpClient()->get($this->discovery()['jwks_uri'])->getBody(), true),
        );
    }

    /**
     * Fetch and cache the provider's discovery document.
     *
     * Cached for an hour so the endpoints aren't re-fetched on every redirect and
     * callback; providers publish these under a stable, long-lived URL.
     *
     * @return array<string, mixed>
     */
    protected function discovery(): array
    {
        return Cache::remember(
            'sso.oidc.discovery:'.$this->discoveryUrl,
            now()->addHour(),
            fn (): array => json_decode((string) $this->getHttpClient()->get($this->discoveryUrl)->getBody(), true),
        );
    }
}
