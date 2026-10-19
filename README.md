# XFedi

**A personal home on the Fediverse.** XFedi is a single-account ActivityPub server built with PHP, a Python background-job supervisor, SQLite, and Docker. It upgrades xsukax Fediverse v1.2 while retaining its account, signing keys, canonical ActivityPub IDs, and private data volume.

![XFedi desktop interface](docs/preview-desktop.jpg)

## What is included

- Responsive light/dark interface with visible mobile **Sign In**, accessible forms, a public timeline, profiles, conversations, search, and pagination.
- Public posts, images with descriptions, replies, mentions, hashtags, edits, and deletion directly from authored timeline cards. Remote posts can be hidden locally and restored from Settings.
- Remote profile headers, galleries, GIF/WebP/AVIF images, video/audio controls, structured incoming quote previews, and remote boosts imported from inboxes or outboxes.
- Website preview cards using Open Graph, Twitter cards, JSON-LD, legacy image links, oEmbed thumbnails, and WordPress featured images. Own profile/post pages expose Open Graph and Twitter metadata too.
- Home, Local, and Federated timelines, plus selected public instance feeds managed under Instances.
- WebFinger and NodeInfo discovery, RSA HTTP signatures, Follow/Accept/Reject/Undo, Like/Undo, Announce/Undo, and queued delivery with retry and failure inspection.
- Incoming signed activities are acknowledged only after durable storage. Processing is idempotent; deletion tombstones prevent delayed deliveries from resurrecting posts.
- Public boosts, private bookmarks, and **linked quote posts** with a `quoteUrl` extension and a readable link fallback. Full FEP-044f quote approval/revocation is **not implemented**; Mastodon may display the fallback link instead of a rich quote.
- Follow, mention, reply, favourite, boost, linked-quote, and follow-response notifications, unread counts, per-item read/unread controls, and a mark-all action that does not mark newly arriving notifications.
- Per-account IANA timezone preference; federation and feed timestamps remain UTC.
- Automatic HTTPS website checks after saving and daily, without requiring a pasted HTML snippet. Existing ordinary profile backlinks or the same HTTPS domain can confirm an association. Strict `rel="me"` proof remains supported. Reachability and ownership/association are labeled separately; an arbitrary reachable site never receives an ownership badge. Old checks stop displaying confirmation after 48 hours.
- RSS for local published posts and hashtags, an automatically paginated XML sitemap, and robots discovery.
- Consistent database **and upload** backups, validated restore tooling, migration tests, HTTP integration tests, and GitHub Actions.

XFedi keeps the original **one account per installation** model. It is a personal public-post server, not a full Mastodon server or a replacement for Mastodon’s client API. It does not provide OAuth/mobile-client APIs, direct/followers-only posts, polls, multi-user registration, email/push notifications, account moves, or a moderation dashboard. See [federation support](FEDERATION.md) for the exact protocol boundary.

## Fresh installation

Requirements: Docker Engine/Desktop with Compose v2, a permanent public HTTPS hostname, and an existing Cloudflare Tunnel running on the Docker host. Outbound HTTPS and public DNS must work from the containers.

```sh
chmod +x install.sh upgrade.sh backup.sh restore.sh verify.sh
./install.sh social.example.com
```

The installer generates a private `.env` file and a random `XFEDI_SETUP_TOKEN`. Open your public hostname, enter that token from `.env`, and create your account using a password of at least 12 characters. The token protects first-run setup from someone else claiming the instance. Keep `.env` out of Git.

Fresh installations bind **127.0.0.1:80**, so your Cloudflare Tunnel HTTP service can remain **`http://localhost`**. If port 80 is occupied, choose another port:

```sh
./install.sh social.example.com 8080
```

Then use `http://localhost:8080` as the tunnel service. Keep Cloudflare SSL enabled on the public hostname. Allow unauthenticated access to ActivityPub, discovery, RSS, sitemap, and media endpoints; Cloudflare Access login challenges or bot challenges on these endpoints prevent remote servers from connecting. Use Cloudflare’s normal caching rules without caching signed-in application pages.

For manual setup, copy `.env.example` to `.env`, set `DOMAIN`, `LOCAL_PORT`, and a random `XFEDI_SETUP_TOKEN` of at least 24 characters, then run `docker compose up -d --build`.

**The public identity is always HTTPS.** The tunnel origin stays HTTP on loopback. `XFEDI_URL` is formed from `DOMAIN` and `FEDERATION_PATH`; it is never set to `http://localhost`.

## Upgrade from XFedi 2.0 or xsukax Fediverse v1.2

Use the **same installation directory, `.env`, Docker context, Compose project name, and `app_data` volume**. The Compose name remains `xsukax-fediverse` intentionally. If you previously used `docker compose -p NAME` or `COMPOSE_PROJECT_NAME`, keep that exact override for every command. Do not create a second empty volume by renaming the project, and never use `docker compose down -v` during an upgrade.

1. With the old release still in place, run its `backup.sh` and retain that database snapshot, the old source, `.env`, and a volume snapshot that includes uploaded images.
2. Stop web writes before replacing the entrypoint: `docker compose stop php worker`.
3. Copy the XFedi release files into that installation directory. Keep the existing `.env` and `backups/` directory.
4. Preserve `DOMAIN` and **the exact existing `FEDERATION_PATH`**. If it was absent in v1.2, leave it absent: the Compose fallback remains `/xsukax_fediverse.php`. An explicit `/index.php` stays `/index.php`.
5. Run `./upgrade.sh`. It builds the new runtime, takes a consistent pre-migration database-and-media backup using the new image, runs additive migrations, restarts services, and checks origin isolation.
6. Visit your profile and compare post/follow counts, images, and the actor ID. Run the live acceptance steps in [TESTING.md](docs/TESTING.md).

The first migration stores the configured identity URL and rejects subsequent changes. For a legacy database that has never stored that value, the operator must supply the original identity accurately on its first upgrade. The code cannot infer a historical hostname from a database that never recorded it.

Migrations add columns, indexes, and tables; they do not regenerate keys, renumber posts, rename the account, or drop original records. Human URLs become `/@username` and `/@username/123`; old query URLs and old ActivityPub object IDs continue working. Renaming the public domain or changing `FEDERATION_PATH` after federation begins is not an account migration.

## URLs

| Purpose | URL |
| --- | --- |
| Public timeline | `/` |
| Profile / post | `/@alice`, `/@alice/123` |
| Hashtag | `/tags/SmallWeb` |
| Sign in | `/auth/sign_in` |
| Search remote account or post | `/search` |
| Local / Federated timelines | `/public/local`, `/public` |
| Public instance sources | `/instances` |
| Notifications / saved posts | `/notifications`, `/bookmarks` |
| Your boosts / settings | `/boosts`, `/settings` |
| Public posts RSS | `/feed.rss`, `/@alice.rss` |
| Local hashtag RSS | `/tags/SmallWeb.rss` |
| Sitemap / robots | `/sitemap.xml`, `/robots.txt` |
| WebFinger | `/.well-known/webfinger?resource=acct:alice@social.example.com` |
| Existing actor ID | `/index.php?ap=actor` or the preserved legacy script path |
| Health | `/healthz` |

RSS contains the newest 50 **local authored public posts**, including replies and linked quotes, without remote cached posts or bookmarks. Sitemaps list local profiles and undeleted posts in pages of 1,000. Updates appear on the next request; public cache lifetimes are 60 seconds. A feed reader may retain older versions according to its own policy.

Remote account/post navigation uses local `/accounts?actor=…` and `/statuses?id=…` views. Search resolves real remote identifiers rather than assuming every server uses Mastodon’s profile paths.

## Everyday operations

```sh
docker compose ps
docker compose logs -f php worker web
./verify.sh
./backup.sh
```

Python automatically runs the private PHP job processor every 10 seconds and refreshes a small sample of followed accounts every 10 minutes. The signed-in Federation page shows delivery/inbox status and supports processing and retrying jobs. Retries use exponential backoff, with failed jobs retained after eight attempts. Delivery is eventually consistent, not instantaneous.

Website checking runs automatically for the current stored website after upgrade, after profile changes, on demand, and daily. There is no required code snippet. XFedi checks the HTTPS response and looks for an existing link to the exact public profile (an ordinary link is enough for the **Profile link confirmed** association label), recognizes the same HTTPS domain, and continues to support strict `rel="me"` verification. A site with no proof is labeled **HTTPS checked · Ownership unconfirmed**. It is impossible to prove ownership of an arbitrary URL from reachability alone. Other servers perform their own verification and may still require their own proof rules.

Instance feeds: open **Instances**, add a public Mastodon-compatible server hostname, then view **Federated**. Following an account also adds its server as an instance source. This polls `/api/v1/timelines/public?local=true` about every five minutes, imports a bounded recent sample, and independently retrieves canonical public ActivityPub objects. It does not follow every user, bypass login requirements, or promise a complete global stream. Servers with public preview disabled show an error in Instances; account-level federation continues normally. Removing a source stops polling; cached public posts can remain visible as known federated content.

Profile headers and website cards are fetched by background jobs. Use **Refresh profile and header** on a remote profile to recheck it. Existing remote profiles and recent cached posts are queued for backfill during the 2.1 upgrade. Link previews and quote originals appear after the background job runs and the page reloads. Native image formats depend on browser decoding support; inaccessible CDNs or unsupported codecs retain source links. No remote script, iframe, or oEmbed HTML is executed.

Backups contain login hashes and private federation keys. Keep them private, store a second copy outside the server, and keep `.env` separately. Restore only with `php` and `worker` stopped:

```sh
./restore.sh /absolute/path/to/xfedi-backup.tar.gz
# To intentionally replace an existing stopped installation:
./restore.sh /absolute/path/to/xfedi-backup.tar.gz --replace
docker compose up -d
```

Restore validates archive paths/types, SQLite integrity, and stored identity before replacement. `--replace` retains a private snapshot of the previous database. Never run it against services using the volume from another Compose project. For rollback across application versions, restore the pre-upgrade snapshot and matching old source/runtime together; do not point the old release at a live newer database.

## Security and deployment

Caddy has **no file-serving fallback**. It can forward only approved routes to `index.php`. It sees only the PHP entrypoint, not the private volume, `.env`, source modules, backup scripts, SQLite files, keys, or uploaded files. PHP serves validated images by database reference. Containers run without application root privileges, use read-only filesystems, and expose only a loopback HTTP port.

All mutations require a signed-in session and CSRF token. Password changes invalidate other stamped sessions. Public setup requires a random installation token. Remote HTML is sanitized; browser links and uploads are validated. Federation/verification requests use bounded HTTPS, certificate validation, public-only DNS pinning, no environment proxies, and independently validated redirects. Incoming HTTP signatures verify the original request target, body digest, actor/key binding, and timestamp.

`TRUST_PROXY=1` enables trusting Cloudflare’s `CF-Connecting-IP` for login throttling; use it only when the loopback origin is reachable exclusively through your trusted tunnel/proxy. Otherwise the proxy address is used. `ALLOW_INSECURE_HTTP=1` is a local development option that removes secure-cookie enforcement; keep it off for a public deployment. The default Caddy configuration marks the tunnel’s public side as HTTPS.

## Development and verification

PHP 8.3+ with `pdo_sqlite`, `curl`, `openssl`, `mbstring`, `fileinfo`, and `dom`; Python 3.10+. No Composer or pip dependencies.

```sh
./tests/run.sh
```

The suite includes 67 core/migration checks, 43 social/media/instance checks, 11 HTTP scenarios, and 4 backup/restore tests. It creates temporary databases and starts a loopback PHP server. It never uses your production volume or posts to an external account. [Architecture](docs/ARCHITECTURE.md), [test coverage and live acceptance](docs/TESTING.md), and the [release validation report](docs/VALIDATION.md) explain the implementation and remaining checks.

For an isolated local preview, set a permanent-looking test `XFEDI_URL`, a separate `XFEDI_DATA_DIR`, `XFEDI_ALLOW_INSECURE_HTTP=1`, `XFEDI_LOCAL_ORIGIN=http://127.0.0.1:8765`, and a test setup token, then run `php -S 127.0.0.1:8765 tests/router.php`. The router blocks access to source files. Never use a production data directory for this preview.

## Publishing this repository

Commit this source directory, including the Docker files, `app/`, `assets/`, `bin/`, tests, docs, and `.github/workflows/ci.yml`. Exclude `.env`, backups, databases, private keys, and generated cache directories. The supplied `.gitignore` and `.dockerignore` cover those files. Review the GitHub Actions results before tagging a release; the container job performs checks that were unavailable in the authoring environment.

The package is prepared for source publication. **Live Mastodon interoperability and production deployment acceptance must still be performed against your public hostname.** No remote account or production server was changed while preparing this release.

MIT licensed. Builds on the supplied xsukax Fediverse v1.2 source; see [LICENSE](LICENSE).
