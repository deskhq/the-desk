<?php

namespace App\Enums;

enum SecurityEventType: string
{
    case LoggedIn = 'logged_in';
    case LoggedOut = 'logged_out';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case TwoFactorConfirmed = 'two_factor_confirmed';
    case RecoveryCodesGenerated = 'recovery_codes_generated';
    case PasskeyRegistered = 'passkey_registered';
    case PasskeyRemoved = 'passkey_removed';
    case SessionRevoked = 'session_revoked';
    case OtherSessionsRevoked = 'other_sessions_revoked';
    case DataExportRequested = 'data_export_requested';
    case DataExportDownloaded = 'data_export_downloaded';
    case AccountProvisioned = 'account_provisioned';
    case AccountDeactivated = 'account_deactivated';
    case AccountReactivated = 'account_reactivated';
    case TeamDeleted = 'team_deleted';

    /**
     * Get the human-readable label shown in the activity log.
     */
    public function label(): string
    {
        return match ($this) {
            self::LoggedIn => __('Signed in'),
            self::LoggedOut => __('Signed out'),
            self::PasswordChanged => __('Password changed'),
            self::PasswordReset => __('Password reset'),
            self::TwoFactorEnabled => __('Two-factor authentication enabled'),
            self::TwoFactorDisabled => __('Two-factor authentication disabled'),
            self::TwoFactorConfirmed => __('Two-factor authentication confirmed'),
            self::RecoveryCodesGenerated => __('Recovery codes generated'),
            self::PasskeyRegistered => __('Passkey added'),
            self::PasskeyRemoved => __('Passkey removed'),
            self::SessionRevoked => __('Session revoked'),
            self::OtherSessionsRevoked => __('Signed out of other devices'),
            self::DataExportRequested => __('Data export requested'),
            self::DataExportDownloaded => __('Data export downloaded'),
            self::AccountProvisioned => __('Account provisioned via SSO'),
            self::AccountDeactivated => __('Account deactivated'),
            self::AccountReactivated => __('Account reactivated'),
            self::TeamDeleted => __('Workspace deleted'),
        };
    }

    /**
     * Get the selectable type options for the admin log's filter dropdown.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
