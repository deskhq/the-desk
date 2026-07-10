<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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

        $this->refreshApplication();

        // RefreshDatabase opened its transaction against the pre-refresh
        // connection; re-open one on the fresh app so writes in this test are
        // still rolled back instead of leaking into the next test.
        if (method_exists($this, 'beginDatabaseTransaction')) {
            $this->beginDatabaseTransaction();
        }
    }
}
