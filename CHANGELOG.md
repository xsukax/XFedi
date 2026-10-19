# Changelog

## 2.1.0 — 2026-10-19

- Fix remote header import/display and change local profile media URLs on replacement to refresh remote caches.
- Redesign the bounded composer, visible label, focus state, footer and touch controls; support Ctrl/Cmd+Enter.
- Show incoming outbox boosts, structured quotes with fetched originals, multiple images, animated/modern image formats, and native video/audio controls.
- Add Open Graph, Twitter, JSON-LD, legacy, oEmbed-thumbnail and WordPress featured-image cards, plus outbound page metadata.
- Add separate Home/Local/Federated views and optional public instance sources independent of account follows.
- Automatically check the current website and distinguish reachability, ordinary-link association, shared-domain evidence, and strict rel-me proof; remove the required HTML snippet from Settings.
- Add direct timeline deletion for owned posts and reversible local hiding for remote posts.
- Add 43 social regression assertions and an HTTP scenario for remote presentation and instance controls.

## 2.0.0 — 2026-10-19

- Rebrand xsukax Fediverse as XFedi and split the PHP entrypoint into maintainable modules.
- Add Python job supervision, durable incoming jobs, retries, heartbeat and safe shutdown.
- Add readable human URLs while preserving old federation identifiers and query routes.
- Add RSS, sitemap pagination, hashtag indexing, timezone preferences and verified website backlinks.
- Add public boosts, private bookmarks, linked quote posts and interaction controls.
- Add reliable notifications with explicit read/unread management and conversation links.
- Fix hidden mobile sign-in, original-target signature verification and separate remote key IDs.
- Guard first-run setup, stamp authenticated sessions, pin the configured identity, and retain remote deletion tombstones.
- Add full database/media backup and validated restore, upgrade helper, tests and CI.
- Preserve the original single-account, public-post deployment model; document protocol limits.
