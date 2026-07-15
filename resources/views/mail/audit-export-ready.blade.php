<x-mail::message>
# {{ __('Your audit export is ready') }}

{{ __('The :log export you requested for :team (:format) is ready to download. It expires in :days days.', ['log' => $logLabel, 'team' => $teamName, 'format' => $formatLabel, 'days' => $retentionDays]) }}

<x-mail::button :url="$url">
{{ __('Download export') }}
</x-mail::button>

@isset($expiresAt)
<x-mail::panel>
{{ __('Any current admin or owner of :team can download this file from Team settings › Exports until :date.', ['team' => $teamName, 'date' => $expiresAt->toFormattedDayDateString()]) }}
</x-mail::panel>
@endisset

{{ __("If you didn't request this export, you can safely ignore this email.") }}
</x-mail::message>
