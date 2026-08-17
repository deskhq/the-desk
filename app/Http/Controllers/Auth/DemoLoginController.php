<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

final class DemoLoginController extends Controller
{
    /**
     * Sign the visitor straight into the shared demo account.
     *
     * The public demo hands every visitor the same workspace owner, so there is
     * no secret to protect: making a stranger copy `demo@northwind.test` /
     * `demo-password` off the login screen is friction with no security value.
     * This is the one-click way in — the entry CTA posts here and the visitor
     * lands wherever an ordinary login would land, so a stored intended URL is
     * still honoured.
     *
     * It 404s off the demo, which is the real gate; the CTA is hidden to match,
     * but that is UI affordance only. Throttled by IP at the route, since every
     * visitor shares one account and a per-user limit would be useless.
     */
    public function __invoke(Request $request): Response
    {
        abort_unless(config('demo.mode'), 404);

        $demo = User::where('email', DemoSeeder::DEMO_EMAIL)->first();

        // The operator turned the flag on but hasn't run `demo:seed` yet — or
        // the hourly reset is mid-flight, between the wipe and the rebuild.
        // Bounce back to the login screen rather than fail on a missing account.
        if (! $demo instanceof User) {
            return to_route('login')->with(
                'status',
                __('The demo workspace is still being prepared. Please try again in a moment.'),
            );
        }

        Auth::login($demo);
        $request->session()->regenerate();

        return app(LoginResponseContract::class)->toResponse($request);
    }
}
