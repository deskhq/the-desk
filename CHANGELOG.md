# Changelog

## [1.7.1](https://github.com/emmpaul/the-desk/compare/v1.7.0...v1.7.1) (2026-07-17)


### Dependencies

* update composer and npm dependencies ([#496](https://github.com/emmpaul/the-desk/issues/496)) ([aa1dd89](https://github.com/emmpaul/the-desk/commit/aa1dd89844ff6d4289846e52d47365da323578d1))

## [1.7.0](https://github.com/emmpaul/the-desk/compare/v1.6.1...v1.7.0) (2026-07-17)


### Features

* pin the app image from .env so upgrading needs no git checkout ([#492](https://github.com/emmpaul/the-desk/issues/492)) ([fd493a7](https://github.com/emmpaul/the-desk/commit/fd493a7b64e02e1461a68e8a0fcfd7b872eafd18))

## [1.6.1](https://github.com/emmpaul/the-desk/compare/v1.6.0...v1.6.1) (2026-07-17)


### Bug Fixes

* **docker:** stop queue, reverb and scheduler reporting unhealthy in the prod stack ([#485](https://github.com/emmpaul/the-desk/issues/485)) ([8d02cae](https://github.com/emmpaul/the-desk/commit/8d02caebce4c9f9cb45885028af954d68f023d9f))

## [1.6.0](https://github.com/emmpaul/the-desk/compare/v1.5.2...v1.6.0) (2026-07-17)


### Features

* one-command upgrades and backups for self-hosters ([#479](https://github.com/emmpaul/the-desk/issues/479)) ([af7b050](https://github.com/emmpaul/the-desk/commit/af7b05047cd3828c6e1d332c47a339bfa8857eb2))

## [1.5.2](https://github.com/emmpaul/the-desk/compare/v1.5.1...v1.5.2) (2026-07-17)


### Bug Fixes

* ship faker as a production dependency so demo:seed runs under --no-dev ([#465](https://github.com/emmpaul/the-desk/issues/465)) ([ffcbdba](https://github.com/emmpaul/the-desk/commit/ffcbdba2005dfd4f84855f1ab3cc21c1687d4d27))

## [1.5.1](https://github.com/emmpaul/the-desk/compare/v1.5.0...v1.5.1) (2026-07-16)


### Bug Fixes

* default the prod compose stack to the prebuilt image so up -d never builds ([#462](https://github.com/emmpaul/the-desk/issues/462)) ([229c236](https://github.com/emmpaul/the-desk/commit/229c2365db9caaa39e818c6f674c8d35031480df))

## [1.5.0](https://github.com/emmpaul/the-desk/compare/v1.4.2...v1.5.0) (2026-07-16)


### Features

* add admin audit-log and security-event export (CSV / JSON) ([#438](https://github.com/emmpaul/the-desk/issues/438)) ([7c21cd4](https://github.com/emmpaul/the-desk/commit/7c21cd4eccc389a20fc78df9fdb1234b91230b3d))
* add admin-visible, immutable security-event log ([#436](https://github.com/emmpaul/the-desk/issues/436)) ([87d9152](https://github.com/emmpaul/the-desk/commit/87d9152ed7e1f05cf67eb7c9ac4255a022106940))
* add env-toggleable passkey (WebAuthn) passwordless sign-in ([#435](https://github.com/emmpaul/the-desk/issues/435)) ([151e9ef](https://github.com/emmpaul/the-desk/commit/151e9ef0f64bbae7a2d2ec8ce292364cdd5579b5))
* add env-toggleable two-factor authentication (TOTP) with recovery codes ([#434](https://github.com/emmpaul/the-desk/issues/434)) ([35e33fd](https://github.com/emmpaul/the-desk/commit/35e33fd39640ec5ea08ce253dc2f3850a4dbdefa))
* add per-user sidebar position setting (left / right) ([#455](https://github.com/emmpaul/the-desk/issues/455)) ([86c911b](https://github.com/emmpaul/the-desk/commit/86c911b3c72b49b32ded90bd042f990bdf131e87))
* collapse the confirmation modals into a ConfirmDialog module ([#315](https://github.com/emmpaul/the-desk/issues/315)) ([#397](https://github.com/emmpaul/the-desk/issues/397)) ([009d7dd](https://github.com/emmpaul/the-desk/commit/009d7ddb21e60023748ac2f08890f44b95392049))
* expand audit and security-event coverage (invitations, SSO/SCIM, exports, sessions) ([#443](https://github.com/emmpaul/the-desk/issues/443)) ([8c6c743](https://github.com/emmpaul/the-desk/commit/8c6c74318a41de5e76c88067aebe62208a821f3a))
* make the emoji picker and onboarding tour keyboard/screen-reader accessible ([#429](https://github.com/emmpaul/the-desk/issues/429)) ([f29a386](https://github.com/emmpaul/the-desk/commit/f29a386836a75f3206e00cc05e42909fb9cba905))
* **navigation:** reskin the sidebar user menu for "The Desk" ([#416](https://github.com/emmpaul/the-desk/issues/416)) ([49d806f](https://github.com/emmpaul/the-desk/commit/49d806fc0dff3b28618325aa379732bfcedaf720))
* redesign message search (facets, highlighting, date grouping, cross-team scope) ([#452](https://github.com/emmpaul/the-desk/issues/452)) ([c7422c8](https://github.com/emmpaul/the-desk/commit/c7422c80d2faeef99802ecf38b7eb023324a5b1c))
* reskin scheduled messages onto "The Desk" ([#431](https://github.com/emmpaul/the-desk/issues/431)) ([c0c5e57](https://github.com/emmpaul/the-desk/commit/c0c5e5733211e936343a6313e0781a9bfcd3193f))
* seed a public demo workspace via demo:seed ([#459](https://github.com/emmpaul/the-desk/issues/459)) ([b18c70b](https://github.com/emmpaul/the-desk/commit/b18c70ba53fabc840a6ca89797a0781849e82399))
* **settings:** show session location and data-export file size ([#449](https://github.com/emmpaul/the-desk/issues/449)) ([363c8d7](https://github.com/emmpaul/the-desk/commit/363c8d7a39c61b08f44eca37b4f76dea1c541e2a))
* share the channel/thread scroll container in a ScrollableMessageList module ([#317](https://github.com/emmpaul/the-desk/issues/317)) ([#398](https://github.com/emmpaul/the-desk/issues/398)) ([ddd466e](https://github.com/emmpaul/the-desk/commit/ddd466e68c603b77502c95aff89ef0133865a8f6))
* show an update-available indicator when the instance is behind ([#415](https://github.com/emmpaul/the-desk/issues/415)) ([3b7852a](https://github.com/emmpaul/the-desk/commit/3b7852a4faeb690e21975c768e1102ff5290f65f))


### Bug Fixes

* add team-admin evidence group to the settings sidebar ([#446](https://github.com/emmpaul/the-desk/issues/446)) ([71caf0a](https://github.com/emmpaul/the-desk/commit/71caf0ae358603ebd036173276df185661e1c60d))
* bind prod app/reverb ports to loopback so a reverse proxy can own 80/443 ([#457](https://github.com/emmpaul/the-desk/issues/457)) ([db35474](https://github.com/emmpaul/the-desk/commit/db35474706bdc1fe15eca898c38bc601949322e8))
* derive seeded message ids from created_at so the timeline never flakes ([#450](https://github.com/emmpaul/the-desk/issues/450)) ([e6a27f1](https://github.com/emmpaul/the-desk/commit/e6a27f104d4d1193c551bcc26ecd753437e951d0))
* keep the "New messages" pill dismissed after it has been seen ([#412](https://github.com/emmpaul/the-desk/issues/412)) ([0a3f5e5](https://github.com/emmpaul/the-desk/commit/0a3f5e575a44f8e9eff43b653707c1f67cf3e142)), closes [#411](https://github.com/emmpaul/the-desk/issues/411) [#409](https://github.com/emmpaul/the-desk/issues/409)
* move toast notifications to top-center so they don't obstruct the composer ([#432](https://github.com/emmpaul/the-desk/issues/432)) ([ec2bc81](https://github.com/emmpaul/the-desk/commit/ec2bc81d818bd503bf0e0a0b43389816b462bb5f)), closes [#430](https://github.com/emmpaul/the-desk/issues/430)
* preserve composer attachments when an online send fails ([#454](https://github.com/emmpaul/the-desk/issues/454)) ([c53d614](https://github.com/emmpaul/the-desk/commit/c53d6140657a21b9a7e0f485f4aadb917da4edfb))


### Code Refactoring

* migrate remaining raw buttons onto the Button primitive ([#410](https://github.com/emmpaul/the-desk/issues/410)) ([23187c6](https://github.com/emmpaul/the-desk/commit/23187c65722bebad3c946a9e457a73a349a7f1c5))


### Dependencies

* bump the github-actions group with 4 updates ([#442](https://github.com/emmpaul/the-desk/issues/442)) ([cba98c3](https://github.com/emmpaul/the-desk/commit/cba98c37a4ec3dd4b9619f21413d12166cd8c21c))

## [1.4.2](https://github.com/emmpaul/the-desk/compare/v1.4.1...v1.4.2) (2026-07-14)


### Bug Fixes

* render the login page immediately after logout ([#380](https://github.com/emmpaul/the-desk/issues/380)) ([3dba29f](https://github.com/emmpaul/the-desk/commit/3dba29f448fb36318b9f06f6e8bf44b3ec8efb09))

## [1.4.1](https://github.com/emmpaul/the-desk/compare/v1.4.0...v1.4.1) (2026-07-14)


### Bug Fixes

* close button overlaps DM unread badge on hover ([#369](https://github.com/emmpaul/the-desk/issues/369)) ([88eab09](https://github.com/emmpaul/the-desk/commit/88eab092232e2e721f683f7025bc7c23d3469074)), closes [#355](https://github.com/emmpaul/the-desk/issues/355)

## [1.4.0](https://github.com/emmpaul/the-desk/compare/v1.3.0...v1.4.0) (2026-07-14)


### Features

* add LDAP/AD authentication and directory sync ([c8c377e](https://github.com/emmpaul/the-desk/commit/c8c377e98499e82925519bef3a86221dc79e7373))
* add SCIM 2.0 directory provisioning and deprovisioning ([52ba9f1](https://github.com/emmpaul/the-desk/commit/52ba9f1678951a38fcfefba008f1ab2a2e441ae8))
* add SSO foundation with OIDC login and JIT provisioning ([748f24e](https://github.com/emmpaul/the-desk/commit/748f24e51566596ab76d490ad2196036f1e3f276))

## [1.3.0](https://github.com/emmpaul/the-desk/compare/v1.2.2...v1.3.0) (2026-07-14)


### Features

* group direct messages (3+ participants) ([#353](https://github.com/emmpaul/the-desk/issues/353)) ([282aaad](https://github.com/emmpaul/the-desk/commit/282aaad80bfaa4b21ea7e35ad6a21df38b667461))
* inline rich-text formatting for messages (bold, italic, strike, code) ([#352](https://github.com/emmpaul/the-desk/issues/352)) ([abe079f](https://github.com/emmpaul/the-desk/commit/abe079f4b4230d2fd5a1072e93bfe58c517f73c2))

## [1.2.2](https://github.com/emmpaul/the-desk/compare/v1.2.1...v1.2.2) (2026-07-14)


### Bug Fixes

* jump to present reliably reaches the newest message on the virtualized timeline ([#350](https://github.com/emmpaul/the-desk/issues/350)) ([35012b8](https://github.com/emmpaul/the-desk/commit/35012b82a7b7c07beeff1790c15099d56c4f46cc))

## [1.2.1](https://github.com/emmpaul/the-desk/compare/v1.2.0...v1.2.1) (2026-07-14)


### Bug Fixes

* lead message hover no longer duplicates the group timestamp ([#348](https://github.com/emmpaul/the-desk/issues/348)) ([fc1bff0](https://github.com/emmpaul/the-desk/commit/fc1bff091abe2b181ce56d1711c4af5536e6a85d)), closes [#339](https://github.com/emmpaul/the-desk/issues/339)

## [1.2.0](https://github.com/emmpaul/the-desk/compare/v1.1.0...v1.2.0) (2026-07-13)


### Features

* attachments foundation (schema, upload, serve, claim-on-send, GC) ([#341](https://github.com/emmpaul/the-desk/issues/341)) ([6118fd4](https://github.com/emmpaul/the-desk/commit/6118fd446677ab9b3554a2a235affd0f1f4231da))
* derive user avatars from Gravatar ([#329](https://github.com/emmpaul/the-desk/issues/329)) ([1527170](https://github.com/emmpaul/the-desk/commit/1527170e610eac111db4e7fa82aaf29ab21e9da1))


### Dependencies

* update npm dependencies to latest majors ([#338](https://github.com/emmpaul/the-desk/issues/338)) ([02aee28](https://github.com/emmpaul/the-desk/commit/02aee2849273dc9ef4043328eb3ce4733822c203))

## [1.1.0](https://github.com/emmpaul/the-desk/compare/v1.0.0...v1.1.0) (2026-07-13)


### Features

* add a FormField module and migrate hand-built field clusters onto it ([#325](https://github.com/emmpaul/the-desk/issues/325)) ([0b10ecb](https://github.com/emmpaul/the-desk/commit/0b10ecbada9740a1f4780d067c01d48a3129ffa5)), closes [#314](https://github.com/emmpaul/the-desk/issues/314)
* add a loading prop to Button and migrate raw buttons onto it ([#324](https://github.com/emmpaul/the-desk/issues/324)) ([2414322](https://github.com/emmpaul/the-desk/commit/2414322b56f35b05544a1a1452706416adf4c95f))
* **admin:** reskin team settings page for "The Desk" ([#328](https://github.com/emmpaul/the-desk/issues/328)) ([746a8b8](https://github.com/emmpaul/the-desk/commit/746a8b8f8f3d26bd598372eedad2436ab233ef2d)), closes [#227](https://github.com/emmpaul/the-desk/issues/227)
* **docs:** marketing landing page + docs under /docs ([#297](https://github.com/emmpaul/the-desk/issues/297)) ([919bf47](https://github.com/emmpaul/the-desk/commit/919bf477b11294e761363e294dbd36eaf6f82aa1))
* pin messages to a channel with a pins popover ([#321](https://github.com/emmpaul/the-desk/issues/321)) ([721d0bd](https://github.com/emmpaul/the-desk/commit/721d0bd49781b4d78b8513caba0b2cd924592d0f))

## [1.0.0](https://github.com/emmpaul/the-desk/compare/v0.7.0...v1.0.0) (2026-07-12)


### chore

* trigger 1.0.0 release ([#296](https://github.com/emmpaul/the-desk/issues/296)) ([c9acba0](https://github.com/emmpaul/the-desk/commit/c9acba080dded4545e95335c09b63f96093bd16b))


### Bug Fixes

* **self-hosting:** make prod use Redis for cache/session/queue ([#293](https://github.com/emmpaul/the-desk/issues/293)) ([7d7d3fe](https://github.com/emmpaul/the-desk/commit/7d7d3fe471be438ef2bd817b33dfc5d014c65dbb)), closes [#290](https://github.com/emmpaul/the-desk/issues/290)

## [0.7.0](https://github.com/emmpaul/the-desk/compare/v0.6.0...v0.7.0) (2026-07-12)


### Features

* **settings:** redesign account panes to "The Desk" ([#231](https://github.com/emmpaul/the-desk/issues/231)) ([#288](https://github.com/emmpaul/the-desk/issues/288)) ([1a91e61](https://github.com/emmpaul/the-desk/commit/1a91e61c36288a09122a0fc4d31670f90e0e6d74))
* **teams:** resend a pending invitation ([#230](https://github.com/emmpaul/the-desk/issues/230)) ([#286](https://github.com/emmpaul/the-desk/issues/286)) ([1c8d70a](https://github.com/emmpaul/the-desk/commit/1c8d70ae243c867a125835bfc3b36d25bf438ac0))


### Bug Fixes

* **a11y:** blocker fixes + automated-a11y tooling foundation ([#266](https://github.com/emmpaul/the-desk/issues/266)) ([#270](https://github.com/emmpaul/the-desk/issues/270)) ([188a05b](https://github.com/emmpaul/the-desk/commit/188a05b433857065facd8a37d06203443b5b897e))
* **a11y:** color contrast & focus indicators meet WCAG AA ([#269](https://github.com/emmpaul/the-desk/issues/269)) ([#275](https://github.com/emmpaul/the-desk/issues/275)) ([306935b](https://github.com/emmpaul/the-desk/commit/306935b7cf9eb8e748e0d4e94a81881e461c0a94))
* **a11y:** message timeline & composer semantics (roles, live regions, combobox) ([#279](https://github.com/emmpaul/the-desk/issues/279)) ([23c6159](https://github.com/emmpaul/the-desk/commit/23c6159e12efdc9726e1d7c2f6bb66c959fe5b68))
* **a11y:** shell & sidebar — skip link, landmarks, aria-current + shadcn controls ([#267](https://github.com/emmpaul/the-desk/issues/267)) ([#273](https://github.com/emmpaul/the-desk/issues/273)) ([21e7fe4](https://github.com/emmpaul/the-desk/commit/21e7fe4a3817ba79cd0a492d7ffba7b1df93120c))
* **a11y:** unread affordance & marketing-page contrast meet WCAG AA ([#278](https://github.com/emmpaul/the-desk/issues/278), [#274](https://github.com/emmpaul/the-desk/issues/274)) ([#284](https://github.com/emmpaul/the-desk/issues/284)) ([f620789](https://github.com/emmpaul/the-desk/commit/f620789c9cfe176a82d7048016265653dfdf6c83))
* **security:** back active sessions with a driver-agnostic index ([#287](https://github.com/emmpaul/the-desk/issues/287)) ([#289](https://github.com/emmpaul/the-desk/issues/289)) ([2748e42](https://github.com/emmpaul/the-desk/commit/2748e42f3ed41ae335cbb5ba054c870d42d34874))

## [0.6.0](https://github.com/emmpaul/the-desk/compare/v0.5.1...v0.6.0) (2026-07-12)


### Features

* **auth:** EMAIL_VERIFICATION_ENABLED flag to require email confirmation ([#202](https://github.com/emmpaul/the-desk/issues/202)) ([#255](https://github.com/emmpaul/the-desk/issues/255)) ([34af35e](https://github.com/emmpaul/the-desk/commit/34af35e1976d59cc2f10951926ccada8b25796a3))
* **channels:** virtualized message timeline ([#42](https://github.com/emmpaul/the-desk/issues/42)) ([#264](https://github.com/emmpaul/the-desk/issues/264)) ([4b5e42c](https://github.com/emmpaul/the-desk/commit/4b5e42cf2abe03b25518d0dd56ffdd82abe9fb44))
* **messaging:** message reminders ("remind me about this") ([#33](https://github.com/emmpaul/the-desk/issues/33)) ([#236](https://github.com/emmpaul/the-desk/issues/236)) ([334207e](https://github.com/emmpaul/the-desk/commit/334207efc05a7d92478986498cf5273d5935fa7f))
* **realtime:** offline/reconnect resilience for the message stream ([#55](https://github.com/emmpaul/the-desk/issues/55)) ([#244](https://github.com/emmpaul/the-desk/issues/244)) ([f636005](https://github.com/emmpaul/the-desk/commit/f636005c7507290f4cf65d66023c33b1a9a7d716))
* **skills:** add design-to-issue skill ([#233](https://github.com/emmpaul/the-desk/issues/233)) ([0f6274e](https://github.com/emmpaul/the-desk/commit/0f6274e70841984b624bec9e7eabdbf6ebdca904))
* **teams:** workspace custom emoji ([#38](https://github.com/emmpaul/the-desk/issues/38)) ([#251](https://github.com/emmpaul/the-desk/issues/251)) ([fdd0875](https://github.com/emmpaul/the-desk/commit/fdd087533c0182890b7c397f5623c6e394c5129a))


### Bug Fixes

* **channels:** secure-context-safe UUID helper for message sends ([#226](https://github.com/emmpaul/the-desk/issues/226)) ([#259](https://github.com/emmpaul/the-desk/issues/259)) ([99a4f45](https://github.com/emmpaul/the-desk/commit/99a4f45897e477096db49dcba5a16e53314eba63))
* **composer:** focus textarea when clicking anywhere in the input card ([#169](https://github.com/emmpaul/the-desk/issues/169)) ([#241](https://github.com/emmpaul/the-desk/issues/241)) ([d2572a4](https://github.com/emmpaul/the-desk/commit/d2572a463a9dcbb9c3a522843f7a6f13a959ee9e))
* **messaging:** scope [@mention](https://github.com/mention) autocomplete to DM participants ([#216](https://github.com/emmpaul/the-desk/issues/216)) ([#240](https://github.com/emmpaul/the-desk/issues/240)) ([44d3868](https://github.com/emmpaul/the-desk/commit/44d38686b5a6807043fdfd358b17d8ffde4e7460))
* **navigation:** populate the sidebar on the message search page ([#243](https://github.com/emmpaul/the-desk/issues/243)) ([#245](https://github.com/emmpaul/the-desk/issues/245)) ([96402c4](https://github.com/emmpaul/the-desk/commit/96402c43e8801a41387376a52442488a56df072e))
* **seeder:** use time-ordered UUIDv7 message ids in channel backfill ([#247](https://github.com/emmpaul/the-desk/issues/247)) ([0fcb0fe](https://github.com/emmpaul/the-desk/commit/0fcb0fea32229c942643f56cfb90f0f85a532339))


### Code Refactoring

* **channels:** decompose Show.vue into composables and components ([#238](https://github.com/emmpaul/the-desk/issues/238)) ([da71b5a](https://github.com/emmpaul/the-desk/commit/da71b5a5af93948282e9c7051821ca26abe83d3d))

## [0.5.1](https://github.com/emmpaul/the-desk/compare/v0.5.0...v0.5.1) (2026-07-11)


### Bug Fixes

* **docker:** mount pgsql volume at /var/lib/postgresql for Postgres 18 ([#224](https://github.com/emmpaul/the-desk/issues/224)) ([0d5828c](https://github.com/emmpaul/the-desk/commit/0d5828c8046afff05fbf0160f42975b04751d9e2)), closes [#223](https://github.com/emmpaul/the-desk/issues/223)

## [0.5.0](https://github.com/emmpaul/the-desk/compare/v0.4.0...v0.5.0) (2026-07-11)


### Features

* **analytics:** admin workspace analytics dashboard ([#51](https://github.com/emmpaul/the-desk/issues/51)) ([#220](https://github.com/emmpaul/the-desk/issues/220)) ([898055b](https://github.com/emmpaul/the-desk/commit/898055ba92544885ff77d5aa29f9dd78777fb2d2))
* **messaging:** forward a message into a direct message ([#219](https://github.com/emmpaul/the-desk/issues/219)) ([b2a9734](https://github.com/emmpaul/the-desk/commit/b2a9734616d622679438e9facfac8bba1ba2bb7f))
* **navigation:** close (hide) a direct message from the sidebar ([#218](https://github.com/emmpaul/the-desk/issues/218)) ([7d7c00e](https://github.com/emmpaul/the-desk/commit/7d7c00e090cdcbb9b1e0f892e59591380a115af9))
* **navigation:** surface notification-preference indicator in sidebar rows ([#217](https://github.com/emmpaul/the-desk/issues/217)) ([c90bd74](https://github.com/emmpaul/the-desk/commit/c90bd749f152f7d49097728bca9660c8a2bd6166))
* **onboarding:** first-run tour and empty-state welcome ([#43](https://github.com/emmpaul/the-desk/issues/43)) ([#221](https://github.com/emmpaul/the-desk/issues/221)) ([ca8b26d](https://github.com/emmpaul/the-desk/commit/ca8b26d8b3e9134ea21352e947015fd60bd1ce7f))
* **platform:** runtime-configurable Reverb settings for a reusable image ([#208](https://github.com/emmpaul/the-desk/issues/208)) ([b89c8a6](https://github.com/emmpaul/the-desk/commit/b89c8a6e9b10cf91b79b2eb46c116e50f20128f7))

## [0.4.0](https://github.com/emmpaul/the-desk/compare/v0.3.0...v0.4.0) (2026-07-11)


### Features

* **i18n:** internationalization scaffolding with French locale ([#56](https://github.com/emmpaul/the-desk/issues/56)) ([#199](https://github.com/emmpaul/the-desk/issues/199)) ([2b4ed53](https://github.com/emmpaul/the-desk/commit/2b4ed537dbb0760fd2e1fcd1665a4b01c5e749e0))
* **mail:** reskin transactional emails to "The Desk" ([#197](https://github.com/emmpaul/the-desk/issues/197)) ([#201](https://github.com/emmpaul/the-desk/issues/201)) ([6307a3c](https://github.com/emmpaul/the-desk/commit/6307a3c3ded1216aeb127b010f4d73f04ec1cf43))
* **platform:** branded "The Desk" error pages ([#198](https://github.com/emmpaul/the-desk/issues/198)) ([#200](https://github.com/emmpaul/the-desk/issues/200)) ([905e8d9](https://github.com/emmpaul/the-desk/commit/905e8d936a7988986601d66bb88708b983a5a031))
* **platform:** publish production image to GHCR ([#203](https://github.com/emmpaul/the-desk/issues/203)) ([#206](https://github.com/emmpaul/the-desk/issues/206)) ([fdc8bff](https://github.com/emmpaul/the-desk/commit/fdc8bff2105fd8bee1a23b759633dbe2d238aead))
* **settings:** move settings into the main workspace shell ([#194](https://github.com/emmpaul/the-desk/issues/194)) ([#195](https://github.com/emmpaul/the-desk/issues/195)) ([c4cbdc6](https://github.com/emmpaul/the-desk/commit/c4cbdc63bf93c2f13ad3b242b84ef18867cf4f1c))

## [0.3.0](https://github.com/emmpaul/laravel-slack-clone/compare/v0.2.0...v0.3.0) (2026-07-10)


### Features

* add cron service to compose.yaml for schedule:work command ([#142](https://github.com/emmpaul/laravel-slack-clone/issues/142)) ([d2cbe9d](https://github.com/emmpaul/laravel-slack-clone/commit/d2cbe9d4fa1124db407e9ff948185aaaa3dbf309))
* add cron service to compose.yaml for schedule:work command ([#144](https://github.com/emmpaul/laravel-slack-clone/issues/144)) ([cde4b46](https://github.com/emmpaul/laravel-slack-clone/commit/cde4b46d66d53e80c6d3ac25f6fb1939bf4e7c77))
* **admin:** reskin teams pages for "The Desk" ([#159](https://github.com/emmpaul/laravel-slack-clone/issues/159)) ([#190](https://github.com/emmpaul/laravel-slack-clone/issues/190)) ([dc167e3](https://github.com/emmpaul/laravel-slack-clone/commit/dc167e3402fe3be1911c7234cf31dfe4dd5ffbfd))
* **auth:** add REGISTRATION_ENABLED flag to toggle public registration ([#122](https://github.com/emmpaul/laravel-slack-clone/issues/122)) ([#187](https://github.com/emmpaul/laravel-slack-clone/issues/187)) ([9d0f2c0](https://github.com/emmpaul/laravel-slack-clone/commit/9d0f2c0a750de426005898887025f80b54358e81))
* **brand:** replace the Laravel mark with "The Desk" stack logo ([#193](https://github.com/emmpaul/laravel-slack-clone/issues/193)) ([6339a20](https://github.com/emmpaul/laravel-slack-clone/commit/6339a2021da45954c3f9efc92ef8fcb275081181))
* **design:** "The Desk" tokens + Newsreader serif foundation ([#146](https://github.com/emmpaul/laravel-slack-clone/issues/146)) ([#162](https://github.com/emmpaul/laravel-slack-clone/issues/162)) ([9cb36e9](https://github.com/emmpaul/laravel-slack-clone/commit/9cb36e9b841a3e7561fe2caeac9577b3f714b01e))
* **identity:** reskin settings pages for "The Desk" ([#157](https://github.com/emmpaul/laravel-slack-clone/issues/157)) ([#188](https://github.com/emmpaul/laravel-slack-clone/issues/188)) ([bc2891a](https://github.com/emmpaul/laravel-slack-clone/commit/bc2891a369bd52890c375fc7dfb208ad82d67a93))
* **messaging:** avatar-gutter message timeline ([#149](https://github.com/emmpaul/laravel-slack-clone/issues/149)) ([#166](https://github.com/emmpaul/laravel-slack-clone/issues/166)) ([18cf36c](https://github.com/emmpaul/laravel-slack-clone/commit/18cf36c759301b9e50a235e8878c8f89dbc46b54))
* **messaging:** floating pill composer + typing indicator ([#151](https://github.com/emmpaul/laravel-slack-clone/issues/151)) ([#170](https://github.com/emmpaul/laravel-slack-clone/issues/170)) ([a8807d8](https://github.com/emmpaul/laravel-slack-clone/commit/a8807d87383351c2f95a2ab0c92007702fc5032b))
* **messaging:** reskin message micro-components ([#150](https://github.com/emmpaul/laravel-slack-clone/issues/150)) ([#167](https://github.com/emmpaul/laravel-slack-clone/issues/167)) ([c2eb47d](https://github.com/emmpaul/laravel-slack-clone/commit/c2eb47d42291e37131ddf9c2149d815de342821c))
* **messaging:** serif channel masthead + member avatar stack ([#148](https://github.com/emmpaul/laravel-slack-clone/issues/148)) ([#164](https://github.com/emmpaul/laravel-slack-clone/issues/164)) ([a6e0892](https://github.com/emmpaul/laravel-slack-clone/commit/a6e089254d3b1430b92c27915c714668b12b8f8c))
* **messaging:** serif empty channel state ([#153](https://github.com/emmpaul/laravel-slack-clone/issues/153)) ([#174](https://github.com/emmpaul/laravel-slack-clone/issues/174)) ([9bd7024](https://github.com/emmpaul/laravel-slack-clone/commit/9bd702458581ae5203dd5a009bef7c2f4bfacb20))
* **messaging:** thread card + serif header ([#152](https://github.com/emmpaul/laravel-slack-clone/issues/152)) ([#173](https://github.com/emmpaul/laravel-slack-clone/issues/173)) ([9267cc6](https://github.com/emmpaul/laravel-slack-clone/commit/9267cc638f8791544f3c8185309032b48c639442))
* **navigation:** reskin browse channels ([#154](https://github.com/emmpaul/laravel-slack-clone/issues/154)) ([#175](https://github.com/emmpaul/laravel-slack-clone/issues/175)) ([7486e04](https://github.com/emmpaul/laravel-slack-clone/commit/7486e0492d885c2b15baa236933930db4ecb39e0))
* **navigation:** reskin overlays for "The Desk" ([#156](https://github.com/emmpaul/laravel-slack-clone/issues/156)) ([#186](https://github.com/emmpaul/laravel-slack-clone/issues/186)) ([23bf016](https://github.com/emmpaul/laravel-slack-clone/commit/23bf016dc908d699cd850b794ba53506f9f65057))
* **platform:** reskin welcome/landing page for "The Desk" ([#160](https://github.com/emmpaul/laravel-slack-clone/issues/160)) ([#191](https://github.com/emmpaul/laravel-slack-clone/issues/191)) ([e75c0e4](https://github.com/emmpaul/laravel-slack-clone/commit/e75c0e43907585810f7deddc95d977db5301d584))
* **security:** reskin auth pages for "The Desk" ([#158](https://github.com/emmpaul/laravel-slack-clone/issues/158)) ([#189](https://github.com/emmpaul/laravel-slack-clone/issues/189)) ([565332c](https://github.com/emmpaul/laravel-slack-clone/commit/565332ca2823ff5a6371d379b80d1140fda312de))
* **security:** rework auth pages to the single-card "The Desk" design ([#192](https://github.com/emmpaul/laravel-slack-clone/issues/192)) ([cd07fbb](https://github.com/emmpaul/laravel-slack-clone/commit/cd07fbbdd57c0ba4e0e5f5c01336177edd540914))
* **shell:** floating-card canvas + dock reskin ([#147](https://github.com/emmpaul/laravel-slack-clone/issues/147)) ([#163](https://github.com/emmpaul/laravel-slack-clone/issues/163)) ([487cd11](https://github.com/emmpaul/laravel-slack-clone/commit/487cd1168982ca3d7c4330c999c05af59984ba11))
* **ui:** serif/cream dialog reskin ([#155](https://github.com/emmpaul/laravel-slack-clone/issues/155)) ([#184](https://github.com/emmpaul/laravel-slack-clone/issues/184)) ([341ca1b](https://github.com/emmpaul/laravel-slack-clone/commit/341ca1ba188f7401902a6016890755644f31d8cb))


### Bug Fixes

* **ssr:** guard useUnreadDivider DOM access on the server ([#165](https://github.com/emmpaul/laravel-slack-clone/issues/165)) ([#168](https://github.com/emmpaul/laravel-slack-clone/issues/168)) ([71397dd](https://github.com/emmpaul/laravel-slack-clone/commit/71397ddf9e5293b4118c0470607e24b74d579be9))

## [0.2.0](https://github.com/emmpaul/laravel-slack-clone/compare/v0.1.0...v0.2.0) (2026-07-10)


### Features

* **messaging:** scheduled / send-later messages ([#115](https://github.com/emmpaul/laravel-slack-clone/issues/115)) ([c2f2728](https://github.com/emmpaul/laravel-slack-clone/commit/c2f27283b9c8c410c064d166a0233104d0bb081c))
* self-hosting with Docker (prod image, compose, CI build check) ([#117](https://github.com/emmpaul/laravel-slack-clone/issues/117)) ([f4fbbf7](https://github.com/emmpaul/laravel-slack-clone/commit/f4fbbf7262725a3976261d427fa372897d78be0b))


### Code Refactoring

* **architecture:** land the architecture-hardening epic ([#131](https://github.com/emmpaul/laravel-slack-clone/issues/131)) ([211c3c0](https://github.com/emmpaul/laravel-slack-clone/commit/211c3c0731ef89b4b029ef64c0ead6779e136c96))

## 0.1.0 (2026-07-10)

Initial release — the first tagged, self-hostable cut of the app.

### Features

**Channels**

- Create & join channels; archive channels
- Unread & mention badges; new-messages divider with jump-to-unread
- Per-member notification preferences (mute & level)
- Window the initial message load around the unread boundary

**Messaging**

- Post & read channel messages over HTTP; realtime delivery over Reverb
- Edit & delete messages; inline quoted replies; @mentions; typing indicators
- Emoji reactions; forward a message to another channel
- Link unfurling / Open Graph previews
- Per-channel unsent composer drafts; read receipts ("seen by")

**Threads**

- Slack-style threaded replies; per-thread read state & unread dots
- Threads inbox; paginated thread-panel replies

**Navigation & workspace**

- Default 3-pane workspace shell & navigation
- Quick switcher command palette; keyboard shortcuts + help modal
- Star channels; collapsible and custom drag-ordered sidebar sections
- Live unread & mention badges in the sidebar

**Identity & presence**

- User profile pages and hover cards with quick actions
- Extended profile fields (pronouns, title, phone); per-user timezone
- Online presence dots on member avatars

**Search, notifications & admin**

- Full-text message search (Scout + Meilisearch)
- Audible chimes for incoming messages
- Workspace audit log for moderation & admin actions

**Security & account**

- Active session / device management; login & security activity history
- Account deletion policy & GDPR data export
- Team ownership transfer

### Bug Fixes

- Never land on a 404 after a team switch or login
- Align message-list presence dots with the avatar rhythm
- Opt the message composer out of password-manager autofill
