<?php

declare(strict_types=1);

namespace App\Data;

use App\SlashCommands\SlashCommand;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry of the composer's slash-command autocomplete manifest. Built from a
 * registered {@see SlashCommand} with its copy already translated under the
 * active locale, so the client renders name · hint · description without any
 * further i18n. The manifest is server-authoritative: adding a command surfaces
 * it in autocomplete with no frontend change.
 */
#[TypeScript]
class SlashCommandData extends Data
{
    public function __construct(
        public string $name,
        public string $description,
        public ?string $argumentHint,
    ) {}

    public static function fromCommand(SlashCommand $command): self
    {
        return new self(
            name: $command->name(),
            description: $command->description(),
            argumentHint: $command->argumentHint(),
        );
    }
}
