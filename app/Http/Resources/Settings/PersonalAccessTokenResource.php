<?php

declare(strict_types=1);

namespace App\Http\Resources\Settings;

use App\Models\PersonalAccessToken;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises a human personal access token for the owner's settings surface —
 * its metadata and bound team, never its (hashed) secret.
 *
 * @mixin PersonalAccessToken
 */
class PersonalAccessTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities ?? [],
            'team' => $this->team instanceof Team ? [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ] : null,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
