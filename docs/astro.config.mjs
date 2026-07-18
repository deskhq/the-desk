// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import sitemap from '@astrojs/sitemap';
import caddyfileGrammar from './src/grammars/caddyfile.tmLanguage.json' with { type: 'json' };
import crontabGrammar from './src/grammars/crontab.tmLanguage.json' with { type: 'json' };

// Public URL the site is served from. Used for canonical links, the sitemap,
// and social-card (Open Graph / Twitter) URLs. Update this if the site moves.
const site = 'https://the-desk.emmanuelpaul.com';
const ogImage = `${site}/og-image.png`;

// https://astro.build/config
export default defineConfig({
	site,
	integrations: [
		sitemap(),
		starlight({
			title: 'The Desk',
			description:
				'Self-hosting and operator documentation for The Desk — a real-time, self-hostable team chat application.',
			social: [
				{ icon: 'github', label: 'GitHub', href: 'https://github.com/emmpaul/the-desk' },
			],
			// Brand theme override (The Desk): Newsreader/Instrument Sans + brass palette.
			customCss: ['./src/styles/custom.css'],
			// Code blocks stay a dark terminal frame in BOTH light and dark site
			// themes (per the design). A single dark syntax theme keeps the token
			// colours readable in both modes; the frame background follows the
			// brand ink via the --td-code-bg custom property (set in custom.css).
			expressiveCode: {
				themes: ['github-dark'],
				// Register the Caddyfile and crontab TextMate grammars that Shiki
				// does not ship in its default bundle, so the `caddy` and `cron`
				// code fences render highlighted instead of plain text.
				shiki: {
					langs: [caddyfileGrammar, crontabGrammar],
				},
				styleOverrides: {
					borderRadius: '12px',
					codeBackground: 'var(--td-code-bg)',
					frames: {
						editorBackground: 'var(--td-code-bg)',
						editorTabBarBackground: 'var(--td-code-bg)',
						editorActiveTabBackground: 'var(--td-code-bg)',
						terminalBackground: 'var(--td-code-bg)',
						terminalTitlebarBackground: 'var(--td-code-bg)',
					},
				},
			},
			// Site-wide social-card + canonical tags for every documentation page.
			head: [
				{ tag: 'link', attrs: { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' } },
				{ tag: 'meta', attrs: { property: 'og:type', content: 'website' } },
				{ tag: 'meta', attrs: { property: 'og:image', content: ogImage } },
				{ tag: 'meta', attrs: { property: 'og:image:width', content: '1200' } },
				{ tag: 'meta', attrs: { property: 'og:image:height', content: '630' } },
				{ tag: 'meta', attrs: { name: 'twitter:card', content: 'summary_large_image' } },
				{ tag: 'meta', attrs: { name: 'twitter:image', content: ogImage } },
				// Brand fonts, loaded the same way as the marketing landing page
				// (preconnect + stylesheet) rather than a render-blocking CSS @import.
				{ tag: 'link', attrs: { rel: 'preconnect', href: 'https://fonts.googleapis.com' } },
				{
					tag: 'link',
					attrs: {
						rel: 'preconnect',
						href: 'https://fonts.gstatic.com',
						crossorigin: 'anonymous',
					},
				},
				{
					tag: 'link',
					attrs: {
						rel: 'stylesheet',
						href: 'https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&family=Newsreader:ital,opsz,wght@0,6..72,400..700;1,6..72,400..700&display=swap',
					},
				},
			],
			// "Edit this page" links point at the file on the default branch.
			editLink: {
				baseUrl: 'https://github.com/emmpaul/the-desk/edit/master/docs/',
			},
			sidebar: [
				{
					label: 'Start Here',
					items: [
						{ label: 'Introduction', slug: 'docs' },
						{ label: 'The Desk vs Slack, Mattermost & Rocket.Chat', slug: 'docs/comparison' },
						{ label: 'FAQ', slug: 'docs/faq' },
					],
				},
				{
					label: 'Self-Hosting',
					items: [
						{ label: 'Requirements', slug: 'docs/self-hosting/requirements' },
						{ label: 'Installation', slug: 'docs/self-hosting/installation' },
						{ label: 'Configuration', slug: 'docs/self-hosting/configuration' },
						{ label: 'Reverse proxy & TLS', slug: 'docs/self-hosting/reverse-proxy' },
						{ label: 'First user & workspace', slug: 'docs/self-hosting/first-user' },
						{ label: 'Upgrading', slug: 'docs/self-hosting/upgrading' },
						{ label: 'Troubleshooting', slug: 'docs/self-hosting/troubleshooting' },
					],
				},
				{
					label: 'Reference',
					items: [
						{ label: 'Architecture', slug: 'docs/reference/architecture' },
						{ label: 'Feature toggles', slug: 'docs/reference/feature-toggles' },
						{ label: 'Environment variables', slug: 'docs/reference/environment-variables' },
						{ label: 'REST API', slug: 'docs/reference/api' },
						{ label: 'Incoming webhooks', slug: 'docs/reference/incoming-webhooks' },
						{ label: 'Outgoing webhooks', slug: 'docs/reference/webhooks' },
						{ label: 'Security & compliance', slug: 'docs/reference/security' },
						{ label: 'SOC 2 & ISO 27001 control mapping', slug: 'docs/reference/security-and-compliance' },
					],
				},
			],
		}),
	],
});
