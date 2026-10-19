# Architecture

## Runtime responsibilities

`index.php` remains the public front controller and CLI entrypoint. PHP handles authentication, forms, rendering, database changes, ActivityPub objects, HTTP signing/verification, sanitization, DNS-pinned requests, RSS, sitemap, and website proof validation. Keeping one protocol/network implementation avoids divergent PHP and Python signature or SSRF rules.

Python’s `worker.py` supervises bounded PHP job runs, schedules recovery polling, handles shutdown, isolates failed runs, and maintains a private heartbeat. It has no public listening socket and no pip dependencies. This is a deliberately small hybrid boundary rather than a second web framework or duplicated federation stack.

Caddy accepts HTTP on the loopback origin, marks the public tunnel side as HTTPS, and routes an explicit allowlist to PHP-FPM. The web container has only `index.php` and the Caddy configuration mounted. PHP modules, assets, worker code, and backup tooling live in the runtime image; approved assets are streamed by PHP. The private volume is mounted only in the PHP and worker containers.

## Source map

| File | Responsibility |
| --- | --- |
| `app/core.php` | Original schema, federation serialization/processing, signed HTTPS, media, auth helpers |
| `app/social.php` | Remote media normalization, preview extraction, quote enrichment, public instance polling and automatic website evidence |
| `app/features.php` | Additive migration, readable routes, feeds/sitemap, inbox queue, interactions, verification, job orchestration |
| `app/routes.php` | Stateless protocol and media dispatch |
| `app/actions.php` | Session bootstrap, CSRF checks, account and post actions |
| `app/view_helpers.php`, `app/views.php` | Components and page rendering |
| `assets/` | Responsive theme and progressive composer enhancement |
| `worker.py` | Private background supervisor |
| `bin/backup.php`, `bin/restore.py` | Consistent snapshots and validated recovery |

## Identity and persistence

Canonical actor, note, Create, activity, collection, and media URLs retain the legacy `XFEDI_URL` prefix. Human profile, post, and hashtag URLs are independent. This is essential because changing an ActivityPub actor ID creates a different federation identity.

The original `social.sqlite` and randomly named image files remain in `/var/lib/xsukax-fediverse`. The original Compose project/volume names are retained. Schema migration adds tables and columns, indexes old hashtags, pins the configured identity, and leaves original records and signing keys intact. SQLite WAL, a busy timeout, and worker locks coordinate FPM and worker processes. Failed local mutations are rolled back where they share a transaction with activity creation.

## Processing flow

1. An incoming POST is size-bounded and HTTP-signature verified against the fetched/cached actor’s public key and original request target.
2. The verified JSON and actor ID are durably inserted into `inbox_jobs`, with a unique activity ID, before returning 202.
3. A worker claims processing under a private background lock. `handleInbox` atomically records the replay ID and applies the activity, notifications, and replies.
4. Outbound activities are stored and expanded to per-inbox delivery rows. Delivery requests get a fresh date/digest/signature on every attempt.
5. Errors retry with bounded exponential backoff; failed jobs remain inspectable and can be retried explicitly. A crash before completion causes safe replay.

The background lock also covers manual runs from the Federation page. The outbound delivery lock remains compatible with the old worker. Inbox payloads are cleared after seven days when successful; their unique IDs remain. Notifications are retained until explicit product functionality is added for removal. This personal-instance implementation has no automatic disk-quota enforcement or mass-user moderation facilities.

## Trust boundary

Remote HTML and JSON are data. They cannot change configuration, run code, select local files, or substitute another actor’s cached objects. Public-only visibility is enforced on import. Remote deletion tombstones are permanent; cached deleted content cannot be restored by out-of-order Create/Update.

Outgoing federation, discovery, and website verification use a single HTTPS client. Requests reject credentials, local/IP-literal hosts, unsafe DNS answers and address ranges, disable environment proxies, pin a public address for the TLS request, cap response bytes/time, and revalidate redirects. Signatures are reconstructed per destination. Incoming RSA signatures are bound to the actor’s actual advertised key rather than an assumed `#main-key` path.

Sessions use secure, HTTP-only, SameSite cookies on the public deployment. All mutations require CSRF and authentication except guarded account setup and sign-in. Setup additionally requires a private installation token. Browser-visible profile fields never expose the private key. Remote image requests are made by the browser with no referrer; as on many small clients, the remote image host can observe a visitor’s request.

## 2.1 presentation and discovery jobs

The additive schema adds remote covers, media collections, source associations, hidden posts, link-preview cache and durable enrichment jobs. Incoming `quote`/legacy quote fields queue independent retrieval of missing originals. Outbox Announce is processed through the normal idempotent inbox logic. Images, native video/audio, and metadata thumbnails are rendered through allowlisted URLs; arbitrary embed HTML is never used.

Public instance listings are a discovery API. Their text/media payloads are not treated as signed ActivityPub originals: each candidate URI is independently retrieved, its actor/ownership is checked, and only public content is stored. Home includes followed authors and their boosts; Federated includes all known public cache and selected server samples. Private/followers-only candidates are rejected.

Website availability, profile association and strict rel-me verification have distinct labels. An ordinary backlink confirms an association, not a person’s legal identity; reachability alone never establishes ownership. All checks use the same DNS-pinned HTTPS client and redirects policy.
