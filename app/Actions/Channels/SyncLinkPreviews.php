<?php

namespace App\Actions\Channels;

use App\Enums\LinkPreviewStatus;
use App\Jobs\UnfurlMessageLinks;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class SyncLinkPreviews
{
    /**
     * How many URLs in a single message we unfurl. Keeps a link-heavy message
     * from spawning a wall of cards (and fetches).
     */
    private const int MAX_LINKS = 3;

    /**
     * Bare http/https URLs, consuming up to the next whitespace. Mirrors the
     * client-side autolink rule in `resources/js/lib/messageBody.ts` so what we
     * unfurl matches what the body linkifies.
     */
    private const string URL_PATTERN = '/\bhttps?:\/\/\S+/i';

    /**
     * Punctuation that commonly trails a URL in prose and isn't part of it.
     */
    private const string TRAILING_PUNCTUATION = '/[.,!?;:\'")\]]+$/';

    /**
     * Reconcile the message's link-preview rows against the URLs in its body and
     * queue an unfurl for any newly-added link.
     *
     * Like {@see SyncMentions} this runs on both post and edit: rows for URLs that
     * left the body are dropped, rows for URLs still present are kept (preserving
     * already-resolved metadata so an edit doesn't re-skeleton an unchanged link),
     * and new URLs get a pending row. Only when a pending row is (re)created do we
     * dispatch the queued job, so an edit that touches no links stays fetch-free.
     */
    public function handle(Message $message): void
    {
        $urls = $this->extractUrls($message->body);

        $hasPending = DB::transaction(function () use ($message, $urls): bool {
            if ($urls === []) {
                $message->linkPreviews()->delete();

                return false;
            }

            $message->linkPreviews()->whereNotIn('url', $urls)->delete();

            // Park survivors above the unique(position) range so slots can be
            // reassigned (including reorders) without a transient collision.
            $message->linkPreviews()->update(['position' => DB::raw('position + '.self::MAX_LINKS)]);

            $existing = $message->linkPreviews()->get()->keyBy('url');
            $pendingCreated = false;

            foreach ($urls as $position => $url) {
                $row = $existing->get($url);

                if ($row !== null) {
                    $row->update(['position' => $position]);

                    continue;
                }

                $message->linkPreviews()->create([
                    'url' => $url,
                    'status' => LinkPreviewStatus::Pending,
                    'position' => $position,
                ]);
                $pendingCreated = true;
            }

            return $pendingCreated;
        });

        if ($hasPending) {
            dispatch(new UnfurlMessageLinks($message->id));
        }
    }

    /**
     * Extract the distinct http(s) URLs from a body, in order of appearance and
     * capped at {@see self::MAX_LINKS}, stripping any trailing prose punctuation.
     *
     * @return array<int, string>
     */
    private function extractUrls(string $body): array
    {
        preg_match_all(self::URL_PATTERN, $body, $matches);

        $urls = [];

        foreach ($matches[0] as $match) {
            $url = (string) preg_replace(self::TRAILING_PUNCTUATION, '', $match);

            if (! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return array_slice($urls, 0, self::MAX_LINKS);
    }
}
