<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds the highlighted, match-centered snippet a search result renders.
 *
 * Two entry points, one per driver. In production Meilisearch returns a cropped
 * `_formatted.body` with our sentinel tags around each (typo/stem-aware) match;
 * {@see fromFormatted()} escapes that text and turns only the sentinels into
 * `<mark>`, so a body containing literal HTML can never inject markup. The
 * `collection` driver (tests + local) has no `_formatted`, so {@see highlight()}
 * produces a driver-agnostic window centered on the first literal term match,
 * wrapping the residual query terms in `<mark>` over fully escaped text.
 *
 * Every character sourced from a message body passes through {@see e()}; the only
 * unescaped output is the literal `<mark>` tags and the `…` window markers, both
 * safe. The result is therefore always XSS-safe HTML.
 */
final class MessageSnippet
{
    /**
     * The sentinel tags Meilisearch wraps matches with (configured as its
     * `highlightPreTag`/`highlightPostTag`). They carry no HTML-special
     * characters, so they survive escaping and can be swapped for real `<mark>`
     * tags after the surrounding body text has been escaped.
     */
    public const string HIGHLIGHT_PRE_TAG = '[[thedesk:hl]]';

    public const string HIGHLIGHT_POST_TAG = '[[/thedesk:hl]]';

    /**
     * How many characters the rendered window spans.
     */
    private const int WINDOW = 160;

    /**
     * How many characters of context to keep before the first match, so the
     * matched term sits a little in from the leading edge rather than flush left.
     */
    private const int LEAD = 32;

    /**
     * Escape a Meilisearch `_formatted.body` value and turn its sentinel tags
     * into `<mark>`. The crop marker and highlighting are already applied by the
     * engine; this only makes the value safe to render as HTML.
     */
    public static function fromFormatted(string $formatted): string
    {
        return str_replace(
            [self::HIGHLIGHT_PRE_TAG, self::HIGHLIGHT_POST_TAG],
            ['<mark>', '</mark>'],
            e($formatted),
        );
    }

    /**
     * Build a match-centered, `<mark>`-highlighted window over a raw message body
     * for the residual query terms, for drivers that return no `_formatted` value.
     */
    public static function highlight(string $body, string $query): string
    {
        $terms = self::terms($query);
        $start = self::windowStart($body, $terms);
        $text = mb_substr($body, $start, self::WINDOW);

        $leadingEllipsis = $start > 0 ? '…' : '';
        $trailingEllipsis = $start + self::WINDOW < mb_strlen($body) ? '…' : '';

        return $leadingEllipsis.self::mark($text, $terms).$trailingEllipsis;
    }

    /**
     * The unique, lower-cased words of the query used for literal highlighting.
     *
     * @return list<string>
     */
    private static function terms(string $query): array
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($query)), flags: PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($words ?: []));
    }

    /**
     * The offset the window opens at: a little before the first literal match, or
     * the head of the body when no term matches (or the query is empty).
     *
     * @param  list<string>  $terms
     */
    private static function windowStart(string $body, array $terms): int
    {
        $earliest = null;

        foreach ($terms as $term) {
            $position = mb_stripos($body, $term);

            if ($position !== false && ($earliest === null || $position < $earliest)) {
                $earliest = $position;
            }
        }

        if ($earliest === null || $earliest <= self::LEAD) {
            return 0;
        }

        return $earliest - self::LEAD;
    }

    /**
     * Escape the window text and wrap every occurrence of any term in `<mark>`.
     *
     * @param  list<string>  $terms
     */
    private static function mark(string $text, array $terms): string
    {
        $ranges = self::matchRanges($text, $terms);

        $result = '';
        $cursor = 0;

        foreach ($ranges as [$open, $close]) {
            $result .= e(mb_substr($text, $cursor, $open - $cursor));
            $result .= '<mark>'.e(mb_substr($text, $open, $close - $open)).'</mark>';
            $cursor = $close;
        }

        return $result.e(mb_substr($text, $cursor));
    }

    /**
     * The merged, ordered [open, close) character ranges every term matches
     * within the window text. Overlapping matches are merged so a wrapped run
     * never nests a second `<mark>`.
     *
     * @param  list<string>  $terms
     * @return list<array{0: int, 1: int}>
     */
    private static function matchRanges(string $text, array $terms): array
    {
        $ranges = [];

        foreach ($terms as $term) {
            $length = mb_strlen($term);
            $from = 0;

            while (($position = mb_stripos($text, $term, $from)) !== false) {
                $ranges[] = [$position, $position + $length];
                $from = $position + $length;
            }
        }

        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($ranges as [$open, $close]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $open <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $close);

                continue;
            }

            $merged[] = [$open, $close];
        }

        return $merged;
    }
}
