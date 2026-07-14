import { readFileSync } from 'node:fs';
import { defineCollection, z } from 'astro:content';
import { docsLoader } from '@astrojs/starlight/loaders';
import { docsSchema } from '@astrojs/starlight/schema';

const repo = 'https://github.com/emmpaul/the-desk';

// The site deploys from `master` (Cloudflare Pages) on every push, so it always
// describes trunk — but the released Docker image self-hosters run only ships
// when release-please cuts a `v*` tag. That leaves a window where the docs
// describe a feature no released image contains yet. `.release-please-manifest.json`
// is release-please's canonical record of the latest *released* version, so we
// read it at build time and default every page's banner to name it: operators
// can tell the docs track the in-development version and cross-check the
// changelog for what has actually shipped. It updates itself on every release —
// no manual upkeep. A page may still override `banner` in its own frontmatter.
const releasedVersion = JSON.parse(
	readFileSync(new URL('../../.release-please-manifest.json', import.meta.url), 'utf8'),
)['.'];
const releaseBannerContent = `📖 These docs track the <strong>in-development</strong> version. The latest released version is <a href="${repo}/releases/tag/v${releasedVersion}"><strong>v${releasedVersion}</strong></a>. Newly documented features may not be in the image you run yet; see the <a href="${repo}/blob/master/CHANGELOG.md">changelog</a> for what has shipped.`;

export const collections = {
	docs: defineCollection({
		loader: docsLoader(),
		schema: docsSchema({
			extend: z.object({
				banner: z.object({ content: z.string() }).default({ content: releaseBannerContent }),
			}),
		}),
	}),
};
