<?php

use App\Jobs\DeliverWebhook;
use App\Listeners\SendMessagePushNotifications;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Support\PhpExtensions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * A real VAPID keypair, generated once with `VAPID::createVapidKeys()`.
 *
 * The library validates the decoded byte length of each half (65 and 32), so a
 * placeholder string cannot stand in for a well-formed pair.
 */
function vapidKeypair(): array
{
    return [
        'public' => 'BO7VvBAiqRfu7aE_Vjpq0TMpi8NhlVuGRvipudmbdB2MqzZ6Y_V2qPoqKNGXVaCQkGAsShvrAI0AOeKjLewsGc0',
        'private' => 'TdMzo3G0qCBNQ6JP_vOcWUm58mbb86gbMXzDrkVpDX0',
    ];
}

/**
 * Configure the instance the way an operator who set web push up correctly would.
 */
function configureWebPush(array $overrides = []): void
{
    config(array_replace([
        'webpush.vapid.public_key' => vapidKeypair()['public'],
        'webpush.vapid.private_key' => vapidKeypair()['private'],
        'webpush.vapid.subject' => 'mailto:admin@example.com',
    ], $overrides));
}

/**
 * Report the given extensions as loaded or missing, so a check that reads the
 * PHP runtime can be exercised from an environment that cannot unload one.
 *
 * @param  array<string, bool>  $overrides
 */
function fakeExtensions(array $overrides): void
{
    app()->bind(PhpExtensions::class, fn (): PhpExtensions => new class($overrides) extends PhpExtensions
    {
        /**
         * @param  array<string, bool>  $overrides
         */
        public function __construct(private readonly array $overrides) {}

        #[Override]
        public function loaded(string $extension): bool
        {
            return $this->overrides[$extension] ?? parent::loaded($extension);
        }
    });
}

/**
 * Record a failed queued job the way a worker would.
 */
function recordFailedJob(string $displayName, ?CarbonImmutable $failedAt = null): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $displayName, 'job' => $displayName]),
        'exception' => 'ErrorException: It is highly recommended to install the GMP or BCMath extension',
        'failed_at' => $failedAt ?? now(),
    ]);
}

test('a correctly configured instance passes every check', function (): void {
    configureWebPush();
    User::factory()->create()->updatePushSubscription('https://fcm.googleapis.com/fcm/send/laptop', 'key', 'token');

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Web push is healthy');
});

test('warnings are reported without failing the command', function (): void {
    configureWebPush();

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Web push has no faults, but see the warnings above');
});

test('it surfaces push jobs that already died in failed_jobs', function (): void {
    configureWebPush();
    recordFailedJob(NewMessageNotification::class, CarbonImmutable::parse('2026-07-20 09:15:00'));
    recordFailedJob(SendMessagePushNotifications::class);
    recordFailedJob(DeliverWebhook::class);

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('2 failed push job(s)');
});

test('it points at the right place when failed jobs are not kept in the database', function (): void {
    configureWebPush();
    config(['queue.failed.driver' => 'null']);

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('stored by the `null` driver');
});

test('an instance with no keypair reports push as disabled rather than broken', function (): void {
    configureWebPush(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('web push is disabled on this instance');
});

test('a half-configured keypair fails', function (): void {
    configureWebPush(['webpush.vapid.private_key' => null]);

    $exitCode = Artisan::call('push:doctor');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('half-configured')
        ->and($output)->toContain('Web push cannot be delivered');
});

test('a malformed keypair fails with the reason it was rejected', function (): void {
    configureWebPush(['webpush.vapid.public_key' => 'dG9vLXNob3J0']);

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Public key should be 65 bytes long');
});

test('it fails when the image ships neither gmp nor bcmath', function (): void {
    configureWebPush();
    fakeExtensions(['gmp' => false, 'bcmath' => false]);

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('neither gmp nor bcmath is loaded');
});

test('it names the required extensions the image is missing', function (): void {
    configureWebPush();
    fakeExtensions(['curl' => false, 'openssl' => false]);

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('missing: curl, openssl');
});

test('it warns when no VAPID subject is set, naming what the library falls back to', function (): void {
    configureWebPush(['webpush.vapid.subject' => null]);

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('not set: falling back to '.url('/'));
});

test('it fails when the VAPID subject is neither a mailto address nor a URL', function (): void {
    configureWebPush(['webpush.vapid.subject' => 'admin@example.com']);

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('must be a mailto: address or an https URL');
});

test('it warns when no device has subscribed', function (): void {
    configureWebPush();

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('no device has subscribed');
});

test('it reports how many devices are subscribed and how many people they belong to', function (): void {
    configureWebPush();

    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/laptop', 'key', 'token');
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/phone', 'key', 'token');
    User::factory()->create()->updatePushSubscription('https://fcm.googleapis.com/fcm/send/desktop', 'key', 'token');

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('3 device(s) across 2 user(s)');
});

test('it fails when the delivery channel cannot be constructed', function (): void {
    configureWebPush();

    app()->when(WebPushChannel::class)
        ->needs(WebPush::class)
        ->give(fn (): WebPush => throw new ErrorException('It is highly recommended to install the GMP or BCMath extension'));

    $exitCode = Artisan::call('push:doctor');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('It is highly recommended to install the GMP or BCMath extension');
});
