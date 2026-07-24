<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DeletePushSubscriptionRequest;
use App\Http\Requests\Settings\StorePushSubscriptionRequest;
use Illuminate\Http\Response;

/**
 * The per-device half of web push: one row per browser that has granted
 * permission.
 *
 * Both writes are the browser reporting about *itself*, not a stored account
 * preference — enabling push on a laptop must not start pushing to a phone — so
 * they are plain no-content endpoints the settings toggle calls directly rather
 * than Inertia redirects. Whether this device is subscribed is answered by the
 * browser's own `pushManager.getSubscription()`, so there is no server state to
 * hand back.
 */
class PushSubscriptionController extends Controller
{
    /**
     * Register (or refresh) this browser's push subscription.
     *
     * Keyed on the endpoint, so a browser that re-subscribes — which it does
     * whenever the push service rotates its keys — updates its existing row
     * instead of accumulating dead ones. An endpoint previously claimed by
     * another account is taken over, which is what makes a shared device work
     * after a re-login.
     */
    public function store(StorePushSubscriptionRequest $request): Response
    {
        $request->user()->updatePushSubscription(
            $request->endpoint(),
            $request->publicKey(),
            $request->authToken(),
            $request->contentEncoding(),
        );

        return response()->noContent();
    }

    /**
     * Revoke this browser's push subscription.
     *
     * Scoped to the caller's own rows, and silent about a miss: the toggle is
     * turned off from a device that may already have been unsubscribed by the
     * browser itself, and re-asserting "not subscribed" is not an error.
     */
    public function destroy(DeletePushSubscriptionRequest $request): Response
    {
        $request->user()->deletePushSubscription($request->endpoint());

        return response()->noContent();
    }
}
