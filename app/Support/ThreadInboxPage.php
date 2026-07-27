<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\ThreadInboxItemData;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Support\Arrayable;
use Inertia\ProvidesScrollMetadata;
use Inertia\ScrollMetadata;

/**
 * One page of the Threads inbox, already turned into cards.
 *
 * The rows cannot simply be mapped through the paginator (`->through()`): a
 * cursor paginator builds its cursor by reading the query's order-by columns
 * (`last_reply_at`, `id`) off the *last item*, and a card DTO carries neither —
 * so the first page that has a successor dies with "Undefined property:
 * ThreadInboxItemData::$last_reply_at" while Inertia serializes the prop. It only
 * bites past the page size, which is why the full-width inbox this replaces
 * shipped with the same latent break.
 *
 * Taking the cursors off the models first, and handing Inertia the cards plus the
 * metadata already read from them, keeps both halves honest.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ThreadInboxPage implements Arrayable
{
    /**
     * The scroll metadata, read while the page still held Eloquent models.
     */
    private ScrollMetadata $metadata;

    /**
     * The page's cards.
     *
     * @var array<int, ThreadInboxItemData>
     */
    private array $cards;

    /**
     * @param  CursorPaginator<int, Message>  $paginator
     */
    public function __construct(CursorPaginator $paginator, User $viewer)
    {
        $this->metadata = ScrollMetadata::fromPaginator($paginator);

        $this->cards = array_map(
            fn (Message $message): ThreadInboxItemData => ThreadInboxItemData::fromMessage($message, $viewer),
            $paginator->items(),
        );
    }

    /**
     * The page as the client reads it: the cards under `data` (the key
     * `Inertia::scroll()` merges on), plus the cursors either side.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(fn (ThreadInboxItemData $card): array => $card->toArray(), $this->cards),
            'next_cursor' => $this->metadata->getNextPage(),
            'prev_cursor' => $this->metadata->getPreviousPage(),
        ];
    }

    /**
     * The pagination metadata Inertia's infinite scroll pages on.
     */
    public function metadata(): ProvidesScrollMetadata
    {
        return $this->metadata;
    }
}
