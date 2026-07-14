<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Channels\SyncLinkPreviews;
use App\Actions\Channels\SyncMentions;

/**
 * The server-side carve-out that keeps mention/URL parsing in lockstep with what
 * the client renders. The message renderer (`resources/js/lib/messageBody.ts`)
 * treats the contents of an inline `` `code` `` span as literal — a mention
 * token or URL inside it neither resolves nor autolinks — so the server must not
 * notify or unfurl for those either. {@see maskInlineCode} blanks every
 * inline-code span before {@see SyncMentions} and
 * {@see SyncLinkPreviews} scan the body.
 *
 * The span-matching mirrors the CommonMark backtick rule the client's Markdown
 * parser uses: a run of N backticks opens a span that closes at the next run of
 * exactly N backticks; with no such closer the backticks are literal. Ship 2's
 * fenced code blocks will extend this same carve-out.
 */
class InlineMarkdown
{
    /**
     * Replace the contents of every inline-code span (fences included) with
     * spaces, leaving the rest of the body byte-for-byte intact so offsets and
     * non-code tokens are preserved. Backticks are ASCII, so byte scanning is
     * safe for UTF-8 bodies: multibyte characters outside code are copied
     * verbatim, and a masked span is filled with the same number of spaces.
     */
    public static function maskInlineCode(string $body): string
    {
        $length = strlen($body);
        $result = '';
        $index = 0;

        while ($index < $length) {
            if ($body[$index] !== '`') {
                $result .= $body[$index];
                $index++;

                continue;
            }

            $runStart = $index;

            while ($index < $length && $body[$index] === '`') {
                $index++;
            }

            $runLength = $index - $runStart;
            $closeStart = self::findClosingRun($body, $index, $length, $runLength);

            if ($closeStart === null) {
                // No matching closer: the opening backticks are literal text.
                $result .= substr($body, $runStart, $runLength);

                continue;
            }

            // Blank the whole span (opening fence, contents, closing fence).
            $spanEnd = $closeStart + $runLength;
            $result .= str_repeat(' ', $spanEnd - $runStart);
            $index = $spanEnd;
        }

        return $result;
    }

    /**
     * Find the start offset of the next backtick run of exactly $runLength,
     * scanning from $from. Returns null when no run of that length remains.
     */
    private static function findClosingRun(string $body, int $from, int $length, int $runLength): ?int
    {
        $index = $from;

        while ($index < $length) {
            if ($body[$index] !== '`') {
                $index++;

                continue;
            }

            $runStart = $index;

            while ($index < $length && $body[$index] === '`') {
                $index++;
            }

            if ($index - $runStart === $runLength) {
                return $runStart;
            }
        }

        return null;
    }
}
