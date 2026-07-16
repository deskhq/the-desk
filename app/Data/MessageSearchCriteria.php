<?php

declare(strict_types=1);

namespace App\Data;

use App\Actions\Channels\SearchMessages;
use App\Enums\SearchScope;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The resolved, validated inputs of a single message search: the residual text
 * query plus the single-valued author, channel, and date-range facets.
 *
 * A plain value object (not a spatie {@see Data}) because it
 * never crosses the HTTP boundary — the controller builds it from the validated
 * request and hands it to {@see SearchMessages}. Ids are
 * resolved on the client (`from:name` -> user id, `in:#channel` -> channel id)
 * and arrive here already resolved; the channel ACL is re-asserted authoritatively
 * regardless of what channel facet is supplied.
 */
final readonly class MessageSearchCriteria
{
    public function __construct(
        public string $query,
        public ?string $authorId = null,
        public ?string $channelId = null,
        public ?CarbonInterface $after = null,
        public ?CarbonInterface $before = null,
        public SearchScope $scope = SearchScope::Team,
    ) {}
}
