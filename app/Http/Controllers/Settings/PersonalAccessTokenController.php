<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Integrations\MintPersonalAccessToken;
use App\Actions\Integrations\RevokePersonalAccessToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorePersonalAccessTokenRequest;
use App\Http\Resources\Settings\PersonalAccessTokenResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The owner-facing management surface for human personal access tokens. A person
 * mints a token that acts as themselves — their memberships and permissions —
 * scoped to a single team and a least-privilege set of abilities, and revokes
 * their own tokens. The token subject is never a bot here; team-scoped bot
 * tokens are managed separately.
 *
 * These endpoints are JSON only: the settings UI that renders against them ships
 * in a follow-up. The whole surface is gated behind the `integrations`
 * middleware, so it 404s when integrations are disabled.
 */
class PersonalAccessTokenController extends Controller
{
    /**
     * List the acting user's own personal access tokens.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        assert($user instanceof User);

        $tokens = $user->tokens()
            ->with('team')
            ->latest()
            ->get();

        return PersonalAccessTokenResource::collection($tokens);
    }

    /**
     * Mint a token bound to a team the user belongs to, returning its plaintext
     * value once.
     */
    public function store(StorePersonalAccessTokenRequest $request, MintPersonalAccessToken $mint): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $team = Team::query()->findOrFail((string) $request->validated('team_id'));

        $token = $mint->handle(
            $user,
            $team,
            $request->validated('name'),
            $request->validated('abilities'),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'data' => new PersonalAccessTokenResource($token->accessToken->load('team')),
        ], 201);
    }

    /**
     * Revoke one of the acting user's own tokens.
     */
    public function destroy(Request $request, string $token, RevokePersonalAccessToken $revoke): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $accessToken = $user->tokens()->whereKey($token)->firstOrFail();

        $revoke->handle($user, $accessToken);

        return response()->noContent();
    }
}
