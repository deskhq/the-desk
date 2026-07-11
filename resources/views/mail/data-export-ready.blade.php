<x-mail::message>
# {{ __('Your data export is ready') }}

{{ __("We've finished assembling a copy of your :app data. Use the button below to download the archive.", ['app' => config('app.name')]) }}

<x-mail::button :url="$url">
{{ __('Download your data') }}
</x-mail::button>

@isset($expiresAt)
{{ __('This link expires on :date. You can request a fresh export any time from your profile settings.', ['date' => $expiresAt->toFormattedDayDateString()]) }}
@endisset

{{ __("If you didn't request this export, you can safely ignore this email.") }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
