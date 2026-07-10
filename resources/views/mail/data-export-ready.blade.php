<x-mail::message>
# Your data export is ready

We've finished assembling a copy of your {{ config('app.name') }} data. Use the button below to download the archive.

<x-mail::button :url="$url">
Download your data
</x-mail::button>

@isset($expiresAt)
This link expires on {{ $expiresAt->toFormattedDayDateString() }}. You can request a fresh export any time from your profile settings.
@endisset

If you didn't request this export, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
