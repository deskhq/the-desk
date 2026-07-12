<?php

namespace App\Support;

class UserAgentParser
{
    /**
     * Browser display names keyed by the pattern that identifies them.
     *
     * Order matters: derivative browsers (Edge, Opera) embed the tokens of the
     * engines they are built on, so they must be matched before Chrome/Safari.
     *
     * @var array<string, string>
     */
    private const array BROWSERS = [
        'Edge' => '/\bEdg/i',
        'Opera' => '/\bOPR\b|\bOpera\b/i',
        'Firefox' => '/\bFirefox\b|\bFxiOS\b/i',
        'Chrome' => '/\bChrome\b|\bCriOS\b/i',
        'Safari' => '/\bSafari\b/i',
    ];

    /**
     * Platform display names keyed by the pattern that identifies them.
     *
     * Order matters: Android user agents also contain "Linux", and iOS user
     * agents can contain "Mac OS X", so the more specific tokens come first.
     *
     * @var array<string, string>
     */
    private const array PLATFORMS = [
        'Windows' => '/\bWindows\b/i',
        'iOS' => '/\biPhone\b|\biPad\b|\biPod\b/i',
        'Android' => '/\bAndroid\b/i',
        'macOS' => '/\bMacintosh\b|\bMac OS X\b/i',
        'Linux' => '/\bLinux\b/i',
    ];

    /**
     * Parse a raw User-Agent header into a human-readable browser and platform.
     *
     * The matching is intentionally lightweight — it recognises the mainstream
     * browsers and operating systems without pulling in a third-party dependency.
     *
     * @return array{browser: string, platform: string}
     */
    public static function parse(?string $userAgent): array
    {
        return [
            'browser' => self::match($userAgent, self::BROWSERS, 'Unknown browser'),
            'platform' => self::match($userAgent, self::PLATFORMS, 'Unknown platform'),
        ];
    }

    /**
     * Return the display name of the first pattern that matches the user agent.
     *
     * @param  array<string, string>  $patterns
     */
    private static function match(?string $userAgent, array $patterns, string $fallback): string
    {
        if ($userAgent === null || $userAgent === '') {
            return $fallback;
        }

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return $fallback;
    }
}
