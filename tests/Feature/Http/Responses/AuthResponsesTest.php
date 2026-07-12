<?php

use App\Http\Responses\LoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Build a request whose authenticated user resolves to the given user.
 */
function requestForUser(?User $user, bool $wantsJson): Request
{
    $request = Request::create('/', 'POST');

    if ($wantsJson) {
        $request->headers->set('Accept', 'application/json');
    }

    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

dataset('auth responses', [
    'login' => [fn (): LoginResponse => new LoginResponse, 200],
    'register' => [fn (): RegisterResponse => new RegisterResponse, 201],
    'two factor login' => [fn (): TwoFactorLoginResponse => new TwoFactorLoginResponse, 200],
    'verify email' => [fn (): VerifyEmailResponse => new VerifyEmailResponse, 204],
]);

test('json requests receive a json response', function (Closure $make, int $status): void {
    $response = $make()->toResponse(requestForUser(User::factory()->create(), wantsJson: true));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe($status);
})->with('auth responses');

test('browser requests redirect to the current team path', function (Closure $make): void {
    $user = User::factory()->create();
    $slug = $user->currentTeam->slug;

    $response = $make()->toResponse(requestForUser($user, wantsJson: false));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toContain("/{$slug}");
})->with('auth responses');

test('the verify email redirect marks the address as verified', function (): void {
    $user = User::factory()->create();

    $response = (new VerifyEmailResponse)->toResponse(requestForUser($user, wantsJson: false));

    expect($response->getTargetUrl())->toContain('verified=1');
});

test('a request without a user is forbidden', function (): void {
    $this->expectException(HttpException::class);

    (new LoginResponse)->toResponse(requestForUser(null, wantsJson: false));
});
