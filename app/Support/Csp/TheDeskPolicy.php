<?php

declare(strict_types=1);

namespace App\Support\Csp;

use App\Support\ReverbConfig;
use Illuminate\Support\Facades\Vite;
use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;
use Spatie\Csp\Scheme;

/**
 * The Content-Security-Policy every web response carries.
 *
 * CSP does not replace output escaping; it caps the blast radius when escaping
 * fails. The-desk renders user-authored content nearly everywhere (messages,
 * markdown, emoji names, link previews, uploaded file names) and ships as a
 * self-hosted image, so this policy is part of what every operator inherits.
 */
final class TheDeskPolicy implements Preset
{
    /**
     * Directives an operator may extend from the environment, keyed by the
     * `csp.extra.*` config key that carries their comma-separated origins.
     *
     * @var array<string, Directive>
     */
    private const array EXTENDABLE_DIRECTIVES = [
        'script-src' => Directive::SCRIPT,
        'style-src' => Directive::STYLE,
        'img-src' => Directive::IMG,
        'connect-src' => Directive::CONNECT,
        'frame-src' => Directive::FRAME,
    ];

    public function configure(Policy $policy): void
    {
        $policy
            ->add(Directive::DEFAULT, Keyword::SELF)
            // 'strict-dynamic' is not optional here: the built app code-splits
            // per page and Inertia pulls further chunks at runtime through
            // dynamic import(). Those runtime-injected tags carry no nonce, so a
            // nonce-only script-src would break every client-side navigation to
            // a not-yet-loaded page. 'self' stays as the CSP2 fallback — CSP3
            // browsers ignore it once 'strict-dynamic' is present.
            ->add(Directive::SCRIPT, [Keyword::SELF, Keyword::STRICT_DYNAMIC])
            ->addNonce(Directive::SCRIPT)
            // Accepted residual, documented in the self-hosting security docs:
            // reka-ui/shadcn popovers, dropdowns and the emoji picker position
            // themselves by writing style *attributes* at runtime. A nonce here
            // would make browsers ignore 'unsafe-inline', and style-src-attr is
            // unsupported on Safari < 15.4. Script execution, the threat CSP is
            // bought for, is covered by script-src above.
            ->add(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE])
            // Accepted residual: link-preview thumbnails are scraped from
            // arbitrary sites, Giphy results are hotlinked, and the Gravatar base
            // URL is operator-configurable, so no enumerable host list exists.
            // data:/blob: cover local upload previews.
            ->add(Directive::IMG, [Keyword::SELF, Scheme::DATA, Scheme::BLOB, Scheme::HTTPS])
            ->add(Directive::FONT, Keyword::SELF)
            ->add(Directive::CONNECT, Keyword::SELF)
            ->add(Directive::MEDIA, Keyword::SELF)
            // worker-src does not fall back to default-src — the chain is
            // worker-src → child-src → script-src, and our script-src carries a
            // nonce plus 'strict-dynamic', under which new Worker() is blocked.
            // Nothing uses workers today; this spares the next person the
            // baffling bug when something does.
            ->add(Directive::WORKER, Keyword::SELF)
            ->add(Directive::FRAME, Keyword::NONE);

        $this->allowReverb($policy);
        $this->allowViteDevServer($policy);
        $this->allowOperatorSources($policy);
    }

    /**
     * Echo opens its WebSocket against an origin that is routinely a different
     * port than the app itself (the common self-hosted layout serves the app on
     * :443 and Reverb on :8080), and 'self' has not covered WebSocket schemes
     * consistently across browsers — so the origin is always stated, derived
     * from the same config the SPA is handed so the two cannot drift.
     */
    private function allowReverb(Policy $policy): void
    {
        $origin = ReverbConfig::websocketOrigin();

        if ($origin !== null) {
            $policy->add(Directive::CONNECT, $origin);
        }
    }

    /**
     * With `npm run dev` the assets, the injected styles and the HMR socket all
     * come from the Vite dev server on a different origin. The policy stays on in
     * development rather than being switched off: if it is absent locally, the
     * first person to meet it is a self-hoster in production.
     */
    private function allowViteDevServer(Policy $policy): void
    {
        if (! Vite::isRunningHot()) {
            return;
        }

        $origin = trim((string) file_get_contents(Vite::hotFile()));
        $socket = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $origin);

        $policy
            ->add(Directive::SCRIPT, $origin)
            ->add(Directive::STYLE, $origin)
            ->add(Directive::CONNECT, [$origin, $socket]);
    }

    /**
     * Origins the operator added through the CSP_EXTRA_* env keys. Strictly
     * additive: an operator allow-listing their analytics host must not be able
     * to drop the nonce or 'strict-dynamic' and silently un-harden the app.
     */
    private function allowOperatorSources(Policy $policy): void
    {
        foreach (self::EXTENDABLE_DIRECTIVES as $key => $directive) {
            $sources = array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) config("csp.extra.{$key}", '')),
            )));

            if ($sources !== []) {
                $policy->add($directive, $sources);
            }
        }
    }
}
