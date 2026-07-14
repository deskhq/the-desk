<?php

namespace App\Services\Sso;

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
 * authorization-code exchange; user claims are read from the userinfo endpoint,
 * so there is no id_token/JWT verification to hand-roll.
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
     * same model Socialite's built-in providers use and keeps us free of
     * hand-rolled id_token/JWT verification, as the issue requires. Strict
     * id_token signature + subject validation is a possible future hardening.
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
