# Release validation — 19 October 2026

## Executed in the authoring environment

- PHP 8.5.11 and Python 3.14.8 syntax/runtime checks.
- **67 core/migration assertions passed**, using the original v1.2 schema and a disposable account/keypair.
- **11 HTTP scenario tests passed**, including a real locally signed inbox request through the legacy path, digest tampering rejection, durable queue processing by the Python worker, feeds, sitemaps, interactions, authentication and notifications.
- **43 social presentation and instance assertions passed**, covering remote cover formats, cache-busting local headers, media galleries, quote enrichment, outbox boosts, metadata cards, no-snippet website association checks and instance source isolation.
- **4 backup/restore tests passed**, including image preservation, explicit replacement with a retained previous snapshot, identity mismatch, traversal and symlink rejection.
- Browser sign-in/sign-out and responsive layout review at phone, tablet and desktop widths. The revised bordered composer fit at 320, 390, 768, 1024 and 1440 widths without horizontal document overflow. Guest Sign In was visible at 390 pixels and opened the correct form. Browser error logs were empty during the checked flows.
- A remote profile header loaded in the browser; fixture galleries, quote originals and featured-image cards rendered. The over-limit composer disabled publication and showed the correct count. No visible broken fixture images or browser error logs were found.
- Updated desktop, mobile, and remote-header previews saved from isolated sample content.

## Not executed here

Docker/Compose and Caddy were not installed in this environment, so no container image build, Compose startup, Caddy binary validation, or container-level path-isolation verification is claimed. These checks are included in the supplied GitHub Actions workflow and `verify.sh`.

No public hostname, Cloudflare Tunnel, production database, or remote Mastodon account was supplied for deployment. Real cross-server delivery, secure-mode peers, website ownership over live HTTPS, and full production upgrade/rollback acceptance remain to be performed using [TESTING.md](TESTING.md).

This package is prepared for GitHub source publication. It is not a claim of universal Fediverse compatibility, Mastodon API compatibility, FEP-044f quote approval support, a completed production deployment, or a third-party security audit.
