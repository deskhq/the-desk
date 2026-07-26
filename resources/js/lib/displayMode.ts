/**
 * Whether the page is running as an installed app rather than a browser tab —
 * the standard display-mode query, plus the non-standard flag iOS sets on a
 * home-screen launch.
 *
 * The distinction carries weight beyond presentation: an installed app sits on
 * someone's own device, where a browser tab may be a shared or public machine.
 */
export function isStandaloneDisplay(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}
