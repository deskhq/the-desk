<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    use RedirectsToCurrentTeam;

    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false], 201);
        }

        $this->forgetUnreachableIntendedUrl($request);

        return redirect()->intended($this->redirectPathForCurrentTeam($request));
    }
}
