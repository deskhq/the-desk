<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
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
        $value = $enabled ? 'true' : 'false';

        putenv("REGISTRATION_ENABLED={$value}");
        $_ENV['REGISTRATION_ENABLED'] = $value;
        $_SERVER['REGISTRATION_ENABLED'] = $value;

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
