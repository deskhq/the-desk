<?php

declare(strict_types=1);

use App\Notifications\NewMessageNotification;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

test('a queued job that dies is written to the log', function (): void {
    Log::spy();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn(NewMessageNotification::class);
    $job->shouldReceive('getQueue')->andReturn('default');

    event(new JobFailed('redis', $job, new RuntimeException('It is highly recommended to install GMP')));

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toContain(NewMessageNotification::class)
                ->and($context['connection'])->toBe('redis')
                ->and($context['queue'])->toBe('default')
                ->and($context['exception'])->toBeInstanceOf(RuntimeException::class);

            return true;
        });
});

test('production enforces a strong default password policy', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    try {
        (new AppServiceProvider($this->app))->boot();

        $validator = Validator::make(
            ['password' => 'weak'],
            ['password' => Password::defaults()],
        );

        expect($validator->fails())->toBeTrue()
            ->and(Password::defaults())->not->toBeNull();
    } finally {
        // Restore non-destructive defaults so the test database can be torn down.
        DB::prohibitDestructiveCommands(false);
        Password::defaults(fn (): null => null);
    }
});
