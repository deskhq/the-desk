<?php

declare(strict_types=1);

use App\Models\User;
use Symfony\Component\Finder\Finder;

/**
 * "Which channels may this user see in a team" is the authorization boundary for
 * search, the thread inbox, forwarding, the channel page and the REST channel
 * list. Before #1144 it had one name and three implementations — and two of them
 * answered a different question: `visibleChannelIds()` was membership only,
 * `ChannelPolicy::view()` was public-or-member, and `Api/V1\ChannelController`
 * was a third raw copy of the second, under a docblock claiming there was a
 * single home. The third copy had drifted: it skipped the team-membership half,
 * so a personal access token outliving its holder's membership still enumerated
 * the team's public channels.
 *
 * There are now two readings, both on {@see User} — `memberChannelIds()` and
 * `readableChannelIds()` / `readableChannels()` — and this test is what keeps a
 * fourth copy from being written: a re-derivation fails here rather than in
 * production, on whichever surface was missed. See ADR-0003.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * The forms the readable rule — "public in this team, or one I belong to" — has
 * actually taken, each keyed by the copy it came from.
 *
 * They match the *disjunction*. Filtering on visibility alone is a legitimate
 * question (`Channel::defaultsForTeam()` asks which public channels are
 * defaults), and so is asking whether one user is in one channel
 * (`ChannelPolicy::isMember()`). It is joining the two into "which channels may
 * this user see" that is this rule.
 *
 * @return array<string, string>
 */
function readableAclSpellingPatterns(): array
{
    // The enum is backed, so a query copy can name it either way — `->value` is
    // how `Channel::defaultsForTeam()` spells it, and the next copy is as likely
    // to be written from that as from this one.
    $public = 'ChannelVisibility::Public(->value)?';

    return [
        // `Api/V1\ChannelController::index`, the copy that had drifted.
        'query builder' => "/'visibility',\s*{$public}[\s\S]{0,120}?orWhereHas\(\s*'channelMembers'/",
        // `ChannelPolicy::view()`, the reading the other two answered to.
        'PHP expression' => "/visibility(->value)?\s*===\s*{$public}\s*\|\|\s*\\\$?[\w>-]*members\(\)/",
    ];
}

/**
 * The form the membership rule took before ADR-0003 collapsed its five copies:
 * a `pluck` of the user's channel ids re-derived at the call site.
 *
 * @return array<string, string>
 */
function memberAclSpellingPatterns(): array
{
    return [
        'membership pluck' => "/->channels\(\)[\s\S]{0,120}?pluck\(\s*'channels\.id'/",
    ];
}

/**
 * Every file under `app/` that spells the given rule out, as repository-relative
 * paths.
 *
 * @param  array<string, string>  $patterns
 * @return array<int, string>
 */
$spellings = function (array $patterns) use ($sourceRoot): array {
    $files = (new Finder)->files()->in($sourceRoot.'/app')->name('*.php');

    $found = [];

    foreach ($files as $file) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $file->getContents()) === 1) {
                $found[] = str_replace($sourceRoot.'/', '', $file->getPathname());

                break;
            }
        }
    }

    sort($found);

    return $found;
};

/**
 * Each reading is scanned for on its own. Merging the two pattern sets would let
 * a dead readable pattern hide behind the membership one still matching `User`,
 * which is precisely the failure this file exists to notice.
 */
test('each reading of the ACL is spelled in exactly one place', function () use ($spellings): void {
    expect($spellings(readableAclSpellingPatterns()))->toBe(['app/Models/User.php'])
        ->and($spellings(memberAclSpellingPatterns()))->toBe(['app/Models/User.php']);
});

/**
 * A guard nothing can trip proves nothing, and these patterns are the kind that
 * rot quietly — they are regular expressions over source, so a typo makes them
 * match nothing at all and the suite stays green. Each copy #1144 removed is
 * replayed here verbatim, so the tripwire is pinned against what it exists to
 * catch.
 */
test('each pattern still catches the copy it was written for', function (string $spelling, string $source): void {
    $patterns = [...readableAclSpellingPatterns(), ...memberAclSpellingPatterns()];

    expect(preg_match($patterns[$spelling], $source))->toBe(1);
})->with([
    'query builder' => ['query builder', <<<'PHP'
                $query->where('visibility', ChannelVisibility::Public)
                    ->orWhereHas('channelMembers', fn (Builder $member) => $member->where('user_id', $subject->id));
    PHP],
    'query builder, backed-enum spelling' => ['query builder', <<<'PHP'
                $query->where('visibility', ChannelVisibility::Public->value)
                    ->orWhereHas('channelMembers', fn (Builder $member) => $member->where('user_id', $subject->id));
    PHP],
    'PHP expression' => ['PHP expression', <<<'PHP'
        return $channel->visibility === ChannelVisibility::Public
            || $channel->members()->whereKey($user->id)->exists();
    PHP],
    'membership pluck' => ['membership pluck', <<<'PHP'
        return $user->channels()
            ->where('channels.team_id', $team->id)
            ->pluck('channels.id');
    PHP],
]);

/**
 * The other half of a trustworthy tripwire: what it must stay quiet about.
 *
 * Each pattern is loose enough to survive reformatting, which is what makes it
 * useful and what bounds its cost here — code asking only *one* of the two
 * halves has to stay out of the results, or the guard becomes noise someone
 * silences by widening the expected list.
 */
test('each pattern leaves a legitimate single-half question alone', function (string $source): void {
    $patterns = [...readableAclSpellingPatterns(), ...memberAclSpellingPatterns()];

    foreach ($patterns as $pattern) {
        expect(preg_match($pattern, $source))->toBe(0);
    }
})->with([
    // `Channel::defaultsForTeam()` — which public channels are defaults.
    'a visibility filter on its own' => ["->where('visibility', ChannelVisibility::Public->value)"],
    // `ChannelPolicy::isMember()` — is this one user in this one channel.
    'a membership check on its own' => ['return $channel->members()->whereKey($user->id)->exists();'],
    // `ChannelController::browse()` — public channels the user can still join,
    // which is the inverse of membership, a third and genuinely different question.
    'the browse inverse' => ["->whereDoesntHave('channelMembers', fn (\$query) => \$query->where('user_id', \$request->user()->id))"],
    // Setting a channel's visibility is not reading the rule.
    'writing the visibility' => ["'visibility' => ChannelVisibility::Public,"],
]);
