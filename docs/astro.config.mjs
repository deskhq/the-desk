// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// https://astro.build/config
export default defineConfig({
	// Public URL the site is served from. Used for the sitemap and canonical
	// links. Update this to your Cloudflare Pages URL (or custom domain).
	site: 'https://the-desk.pages.dev',
	integrations: [
		starlight({
			title: 'The Desk',
			description:
				'Self-hosting and operator documentation for The Desk — a real-time, self-hostable team chat application.',
			social: [
				{ icon: 'github', label: 'GitHub', href: 'https://github.com/emmpaul/the-desk' },
			],
			// "Edit this page" links point at the file on the default branch.
			editLink: {
				baseUrl: 'https://github.com/emmpaul/the-desk/edit/master/docs/',
			},
			sidebar: [
				{
					label: 'Start Here',
					items: [{ label: 'Introduction', slug: 'docs' }],
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
					],
				},
				{
					label: 'Reference',
					items: [
						{ label: 'Architecture', slug: 'docs/reference/architecture' },
						{ label: 'Feature toggles', slug: 'docs/reference/feature-toggles' },
						{ label: 'Environment variables', slug: 'docs/reference/environment-variables' },
					],
				},
			],
		}),
	],
});
