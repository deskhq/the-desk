<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\PasswordConfirmedResponse as PasswordConfirmedResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasswordConfirmedResponse implements PasswordConfirmedResponseContract
{
    use RedirectsToCurrentTeam;

    /**
     * Return to the guarded page the confirmation was raised for.
     *
     * That page is normally waiting in the intended URL, put there by the
     * `password.confirm` middleware. When it is not — someone opened the confirm
     * screen directly — Fortify falls back to `fortify.home`, the public
     * marketing page, so the workspace stands in instead.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        $this->forgetUnreachableIntendedUrl($request);

        return redirect()->intended($this->redirectPathForCurrentTeam($request));
    }
}
