<?php

declare(strict_types=1);

/**
 * The workspace audit-log retention window an operator inherits when they never
 * set `AUDIT_LOG_RETENTION_DAYS`. One year, matching the account-activity log so
 * one control family answers "how long do you keep it" with a single number.
 * See issue #913.
 */
const DEFAULT_AUDIT_LOG_RETENTION_DAYS = 365;

/**
 * The default is stated in five places an operator can read — the config
 * fallback, both env templates, the env-variable table, and the compliance
 * control mapping — and a change to one of them that misses the others hands out
 * contradictory answers about how long the log is kept.
 */
function statedAuditLogRetention(string $relativePath, string $pattern): ?int
{
    $contents = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

    if (preg_match($pattern, $contents, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1];
}

test('the config fallback is one year', function (): void {
    expect(statedAuditLogRetention('config/activitylog.php', "/env\('AUDIT_LOG_RETENTION_DAYS',\s*(\d+)\)/"))
        ->toBe(DEFAULT_AUDIT_LOG_RETENTION_DAYS);
});

test('both env templates ship the config fallback', function (string $template): void {
    expect(statedAuditLogRetention($template, '/^#\s*AUDIT_LOG_RETENTION_DAYS=(\d+)$/m'))
        ->toBe(DEFAULT_AUDIT_LOG_RETENTION_DAYS);
})->with(['.env.example', '.env.prod.example']);

test('the documented default matches the shipped one', function (): void {
    expect(statedAuditLogRetention(
        'docs/src/content/docs/reference/environment-variables.md',
        '/\|\s*`AUDIT_LOG_RETENTION_DAYS`\s*\|\s*`(\d+)`/',
    ))->toBe(DEFAULT_AUDIT_LOG_RETENTION_DAYS);
});

test('the compliance control mapping states the shipped default', function (): void {
    expect(statedAuditLogRetention(
        'docs/src/content/docs/reference/security-and-compliance.md',
        '/`AUDIT_LOG_RETENTION_DAYS`\s*\(default\s*(\d+)/',
    ))->toBe(DEFAULT_AUDIT_LOG_RETENTION_DAYS);
});
