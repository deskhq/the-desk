<?php

namespace App\Data;

readonly class UserTeam
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public bool $isPersonal,
        public ?string $role,
        public ?string $roleLabel,
        public int $membersCount = 0,
        public ?bool $isCurrent = null,
    ) {
        //
    }
}
