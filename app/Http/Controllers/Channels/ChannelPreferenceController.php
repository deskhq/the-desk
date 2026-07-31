<?php

namespace App\Http\Controllers\Channels;

use App\Enums\NotificationLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\UpdateChannelPreferenceRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Support\ChannelMembership;
use Illuminate\Http\RedirectResponse;

class ChannelPreferenceController extends Controller
{
    /**
     * Update the current user's notification preferences for the channel.
     *
     * Redirects back and lets Inertia recompute the shared `channels` prop so
     * the sidebar badges and dimming reflect the change without a full reload.
     */
    public function update(UpdateChannelPreferenceRequest $request, Team $team, Channel $channel): RedirectResponse
    {
        new ChannelMembership($channel, $request->user())->setNotificationPreference(
            muted: $request->boolean('muted'),
            notificationLevel: NotificationLevel::from($request->validated('notification_level')),
        );

        return back();
    }
}
