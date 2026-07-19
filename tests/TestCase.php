<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

abstract class TestCase extends BaseTestCase
{
    /**
     * Original values of env vars overridden via reloadWithEnv(), captured so
     * they can be restored in teardown. A value of false means "was unset".
     *
     * @var array<string, string|false>
     */
    private array $originalEnv = [];

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Restore any env vars a test overrode via reloadWithEnv(), so a value set
     * by one test never contaminates the next.
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $original) {
            if ($original === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$original}");
                $_ENV[$key] = $original;
                $_SERVER[$key] = $original;
            }
        }

        if ($this->originalEnv !== []) {
            // Also reset the memoized env repository. Its immutable writer keeps
            // a "loaded by Dotenv" ledger; any key still on that ledger would be
            // re-written from .env on the next application boot, clobbering the
            // values restored above. A fresh repository treats them as external
            // definitions that reloading .env leaves untouched.
            Env::enablePutenv();
        }

        $this->originalEnv = [];

        // The LDAP directory-bind path registers a custom Fortify auth callback
        // (FortifyServiceProvider::configureLdapAuthentication). It lives on a
        // static, so booting a later test without LDAP would otherwise inherit
        // the previous test's callback; clear it so each test starts clean.
        Fortify::$authenticateUsingCallback = null;

        parent::tearDown();
    }

    /**
     * Reboot the application with the given REGISTRATION_ENABLED value.
     *
     * Fortify decides whether to register the `/register` routes at boot from
     * `config('fortify.features')`, so the env var has to be in place before the
     * app boots — hence the refresh rather than a runtime `config()` override.
     */
    protected function reloadWithRegistrationEnabled(bool $enabled): void
    {
        $this->reloadWithEnv(['REGISTRATION_ENABLED' => $enabled]);
    }

    /**
     * Reboot the application with DEMO_MODE set to the given value.
     *
     * Demo mode gates boot-time decisions (whether Fortify registers the
     * `/register` routes, whether the destructive-action guard and the mail
     * transport override are active), so the env var has to be in place before
     * the app boots — hence the refresh rather than a runtime `config()` set.
     */
    protected function reloadWithDemoMode(bool $enabled): void
    {
        $this->reloadWithEnv(['DEMO_MODE' => $enabled]);
    }

    /**
     * Reboot the application with the given environment variables in place.
     *
     * Boot-time decisions (which Fortify routes to register, the SSO-only
     * enforcement) read these before the app boots, so the value has to be set
     * and the app refreshed rather than overridden at runtime with `config()`.
     *
     * @param  array<string, bool|int|string>  $vars
     */
    protected function reloadWithEnv(array $vars): void
    {
        foreach ($vars as $key => $value) {
            // Capture the pre-existing value once, so teardown restores the
            // original (or unsets it) rather than leaking this override.
            if (! array_key_exists($key, $this->originalEnv)) {
                $this->originalEnv[$key] = getenv($key);
            }

            $string = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            putenv("{$key}={$string}");
            $_ENV[$key] = $string;
            $_SERVER[$key] = $string;
        }

        // Reset the memoized env repository so its immutable "already loaded"
        // guard is cleared. Without this, a value baked into .env at first boot
        // (as CI does via `cp .env.example .env`) would survive the reload and
        // clobber the override above; a fresh repository instead treats our
        // value as an external definition that reloading .env leaves untouched.
        Env::enablePutenv();

        $this->refreshApplication();

        // RefreshDatabase opened its transaction against the pre-refresh
        // connection; re-open one on the fresh app so writes in this test are
        // still rolled back instead of leaking into the next test.
        if (method_exists($this, 'beginDatabaseTransaction')) {
            $this->beginDatabaseTransaction();
        }
    }
}
