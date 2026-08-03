<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * One page of a simply-paginated admin log: the rows, and the URLs either side.
 *
 * Simple pagination is the right envelope for a log. It asks for one row more
 * than it shows to learn whether a next page exists, rather than counting the
 * whole table for a page count nobody reads — an audit log grows without bound
 * and is walked, not jumped around in.
 *
 * It is deliberately *not* a cursor. A cursor would page a deep log more cheaply,
 * but these two surfaces are admin screens read from the top and filtered, and
 * their prev/next controls are page URLs the client already speaks. Switching
 * modes is a change to what a page *is*, not a deduplication — the cursor-paged
 * surfaces ({@see ThreadInboxPage}, `MessagePage`) are their own envelope for that
 * reason, and #1199 deliberately left them alone in both directions.
 *
 * The walk is the point of this class. Both admin logs used to spell it out
 * themselves — the page size, `latest()->orderByDesc('id')`, `simplePaginate`,
 * `withQueryString()`, then the same three envelope keys — which is four places
 * for one decision to drift in (#1199). It is declared once, here, and the two
 * read-models supply only what genuinely differs: their scoped query and the DTO
 * each row becomes.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class SimplePage implements Arrayable
{
    /**
     * How many rows a page of an admin log shows.
     */
    public const int PER_PAGE = 30;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function __construct(
        private array $rows,
        private ?string $previousPageUrl,
        private ?string $nextPageUrl,
    ) {}

    /**
     * Page the given query newest-first and turn each row into its DTO.
     *
     * `latest()` alone is not a total order: an audit entry and a security event
     * are both written in bursts, and rows sharing a `created_at` to the second
     * would otherwise be free to swap places between two requests — which in a
     * paged walk means a row shown twice and another never shown at all. The id
     * breaks the tie, so the sequence a reader pages through is stable.
     *
     * `withQueryString()` carries the active filters onto the prev/next links, so
     * paging a filtered log stays filtered.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): Data  $row
     */
    public static function newestFirst(Builder $query, Closure $row): self
    {
        $paginator = $query
            ->latest()
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();

        return new self(
            array_map(static fn ($model): array => $row($model)->toArray(), $paginator->items()),
            $paginator->previousPageUrl(),
            $paginator->nextPageUrl(),
        );
    }

    /**
     * The page as the client reads it, matching `SimplePage<T>` in
     * `resources/js/types/pagination.ts`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->rows,
            'prevPageUrl' => $this->previousPageUrl,
            'nextPageUrl' => $this->nextPageUrl,
        ];
    }
}
