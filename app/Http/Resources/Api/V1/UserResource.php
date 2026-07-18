<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Data\UserData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The stable public-API shape of a user (a human member or a bot). Deliberately
 * minimal and decoupled from the frontend {@see UserData} DTO so the
 * v1 contract stays fixed as the internal shape evolves.
 *
 * @mixin User
 */
class UserResource extends JsonResource
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
            'type' => $this->type->value,
        ];
    }
}
