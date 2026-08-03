<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Three of the rules `app/Http/Requests/` carried were not rules about a
 * request. They were rules about the domain, written where the only constructor
 * takes a `Request` — so every one of them cost a route, a session and a
 * rendered 422 to assert.
 *
 * #1150 moved them: the reply and thread-root target to
 * {@see App\Rules\MessageTarget}, the channel-name collision check to
 * {@see App\Rules\AvailableChannelName}, and the forward destination to
 * {@see App\Rules\ForwardDestination} — which asks the `postMessage` gate rather
 * than restating it. Between them they replaced ten spellings across seven form
 * requests.
 *
 * This is what stops an eleventh being written. See `CONTEXT.md`.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * The shape each relocated rule had while it lived in a form request, keyed by
 * the fact it spelled.
 *
 * @return array<string, string>
 */
function domainRuleSpellingPatterns(): array
{
    return [
        // Six copies: the two in `PostMessageRequest`, the two in
        // `Api/V1\StoreMessageRequest`, and one each in `StorePollRequest` and
        // `StoreSlashCommandRequest`.
        'message target chain' => "/exists\('messages'[^;]*systemValues\(\)/s",
        // Three copies, each re-deriving the slug the create path will store:
        // the web create, the rename, and the API create.
        'channel name collision' => '/Channel::FALLBACK_SLUG/',
        // One copy, and the most expensive: `postMessage`'s two conditions
        // restated as an existence check the gate could not keep in step with.
        'post-message gate as an exists' => "/whereNull\('archived_at'\)[^;]*whereIn\('id'/s",
    ];
}

/**
 * Every form request spelling the given shape out, as repository-relative paths.
 *
 * @return array<int, string>
 */
$spellings = function (string $pattern) use ($sourceRoot): array {
    $files = (new Finder)->files()->in($sourceRoot.'/app/Http/Requests')->name('*.php');

    $found = [];

    foreach ($files as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $found[] = str_replace($sourceRoot.'/', '', $file->getPathname());
        }
    }

    sort($found);

    return $found;
};

/**
 * Unlike #1151's route-model guard, none of these leaves a legitimate copy
 * behind: the rules live in `app/Rules/` now, so `app/Http/Requests/` spells
 * none of the three.
 */
test('no form request spells a relocated domain rule out', function (string $shape) use ($spellings): void {
    expect($spellings(domainRuleSpellingPatterns()[$shape]))->toBe([]);
})->with(array_keys(domainRuleSpellingPatterns()));

/**
 * A guard nothing can trip proves nothing, and these are regular expressions
 * over source: a typo makes one match nothing and the suite stays green. Each
 * shape #1150 removed is replayed here verbatim.
 */
test('each pattern still catches the copy it was written for', function (string $shape, string $source): void {
    expect(preg_match(domainRuleSpellingPatterns()[$shape], $source))->toBe(1);
})->with([
    'the thread-root exists chain' => ['message target chain', <<<'PHP'
            'thread_root_id' => [
                'nullable',
                'uuid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $this->channel()->id)
                    ->whereNotIn('type', MessageType::systemValues())
                    ->whereNull('deleted_at')
                    ->whereNull('thread_root_id'),
            ],
    PHP],
    'the reply-target exists chain' => ['message target chain', <<<'PHP'
                Rule::exists('messages', 'id')
                    ->where('channel_id', $this->channel()->id)
                    ->whereNotIn('type', MessageType::systemValues())
                    ->whereNull('deleted_at'),
    PHP],
    'the slug collision after() body' => ['channel name collision', <<<'PHP'
                $slug = NameSlug::distinct((string) $this->input('name'), Channel::FALLBACK_SLUG);
    PHP],
    'the forward destination exists chain' => ['post-message gate as an exists', <<<'PHP'
                Rule::exists('channels', 'id')
                    ->where('team_id', $this->sourceChannel()->team_id)
                    ->whereNull('archived_at')
                    ->whereIn('id', $this->membershipChannelIds()),
    PHP],
]);

/**
 * The other half of a trustworthy tripwire: what it must stay quiet about. Each
 * of these ships in `app/Http/Requests/` today, and flagging one would make the
 * guard noise somebody silences.
 */
test('each pattern leaves the shapes that legitimately remain alone', function (string $source): void {
    foreach (domainRuleSpellingPatterns() as $pattern) {
        expect(preg_match($pattern, $source))->toBe(0);
    }
})->with([
    // The shape all seven converted requests now take.
    'a relocated rule reached by name' => ["'thread_root_id' => ['nullable', 'uuid', MessageTarget::threadRootIn(\$this->channel())],"],
    // `SetMessageReminderRequest` — a live message, with no channel or system
    // notice in the question. A different rule, not a seventh copy of this one.
    'a live-message exists that asks nothing else' => ["Rule::exists('messages', 'id')->whereNull('deleted_at'),"],
    // `ScheduleMessageRequest` — the seventh reply-target spelling, which omits
    // the system-notice clause and so is a behaviour difference rather than a
    // copy. Tracked as #1165, deliberately left standing by #1150.
    'the scheduled reply target that diverges' => [<<<'PHP'
                Rule::exists('messages', 'id')
                    ->where('channel_id', $this->channel()->id)
                    ->whereNull('deleted_at'),
    PHP],
    // `Teams\StoreUserGroupRequest` — the other slug in the request subtree,
    // which merges one in rather than checking a channel name for collisions.
    'a user-group slug merged before validation' => ["'slug' => NameSlug::distinct((string) \$this->input('name'), UserGroup::FALLBACK_SLUG),"],
    // `Api/V1\StoreWebhookSubscriptionRequest` — a tenancy check over channels,
    // which is neither of the two channel questions #1150 relocated.
    'a channel tenancy check' => [<<<'PHP'
                $ownedCount = Channel::query()
                    ->where('team_id', $this->team()->id)
                    ->whereIn('id', $channelIds)
                    ->count();
    PHP],
]);
