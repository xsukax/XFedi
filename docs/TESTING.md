# Test process

## Repeatable local suite

Run `./tests/run.sh` with PHP 8.3+ and Python 3.10+. It checks PHP and shell syntax, compiles Python, executes 67 core/migration assertions, 43 social/media/instance assertions, 11 HTTP scenario tests, and 4 backup/restore tests. Temporary state is isolated from a real installation.

Coverage includes:

- v1.2 database compatibility, idempotent migrations, signing-key/account/post preservation, old IDs, and refusal of identity drift;
- Follow/Accept and Undo, public Create/Update/Delete, Like/Undo and Announce/Undo, replay and notification deduplication;
- original signed target preservation, separate key IDs, valid signed inbox storage, tampered digest rejection, and Python-supervised inbox processing;
- private-object rejection, stale updates, deletion resurrection protection, unsafe URL/IP rejection, HTML sanitization, and valid/invalid `rel="me"` proofs;
- pretty/legacy routes, WebFinger, content negotiation, actor/public key, RSS/XML escaping, hashtag feeds, sitemap and robots;
- posting/editing/deleting, bookmarks, boosts, favourites, linked quotes, notification read/unread, timezone, website validation, login/logout/CSRF and protected pages;
- database-plus-image snapshots, validated restore, explicit replacement, identity mismatch, path traversal, and symlink rejection.

## Container checks

Run in an isolated installation directory/volume first:

```sh
docker compose config --quiet
docker compose build php
docker compose up -d --wait
./verify.sh
docker compose exec -T web caddy validate --config /etc/caddy/Caddyfile
docker compose exec -T php php index.php doctor
```

Verify PHP, worker and web are healthy; worker logs show completed cycles; the bound host address is 127.0.0.1. Probe `.env`, source modules, worker/backup scripts, SQLite/WAL files, `.git/config`, and backups: they must all return 404. A successful PHP response must not contain source code or private keys.

The GitHub Actions container job performs the build, start, health, Caddy validation and origin-isolation checks. The application job tests PHP 8.3, 8.4 and 8.5 with Python 3.12.

## Responsive browser acceptance

Check 320, 390, 768, 1024 and 1440 pixel widths. Review both light and dark system themes, keyboard focus, zoom, long handles, emoji, very long URLs and real remote HTML. Confirm:

1. Guest Sign In remains visible; it opens the correct form at every width.
2. Signing in reveals the composer; empty and over-500-character drafts cannot submit with JavaScript; server limits also reject invalid posts without JavaScript.
3. Attach/remove a valid image, add a description, publish, and open the thread. Non-image and oversize files must fail.
4. All action controls are reachable, the document does not horizontally overflow, settings forms stay within the viewport, and bottom navigation does not prevent reaching content.
5. Bookmark privately, boost/unboost, favourite/unfavourite, quote, edit, and delete. Check conversation links and back navigation.
6. Change timezone and compare an absolute timestamp across profile, thread, notifications and Settings.

Saved screenshots use disposable sample content, not a production database.

## Live federation acceptance before a production release

Use a disposable second Mastodon account and your actual public HTTPS hostname. Local automated tests cannot prove another server has accepted a delivery.

1. Resolve `@yourname@yourdomain` in Mastodon search. Compare its actor URL with the pre-upgrade actor URL and compare the public key fingerprint.
2. Follow in each direction. Confirm Accept reaches the requester and follower/following counts converge. Unfollow in each direction; repeat after a worker/container restart.
3. Publish local and remote public Notes containing a hashtag, link, image and mention. Confirm content, accessible descriptions and links. Private/followers-only posts must not appear in XFedi.
4. Reply in both directions. Open the notification and verify it links to the originating reply, not just the parent or raw JSON.
5. Favourite/unfavourite and boost/unboost in both directions. Repeat delivery of the same activity and verify no duplicated state/count/notification. A new follower’s outbox import should include authored posts and available boosts.
6. Edit and delete from each author’s server. Test out-of-order updates and restart between receiving and processing. Deleted Notes return 410 and remain deleted.
7. Create linked quotes of local and remote posts. Expect a normal commentary/link on peers without a compatible legacy preview; do not claim an approval-based quote state or FEP-044f support.
8. Confirm bookmark changes are visible only after local sign-in and create no external delivery or notification.
9. Open `/feed.rss`, `/@yourname.rss` and `/tags/tag.rss` in a feed reader; parse the sitemap index and every child page. Edit/delete a post and recheck after cache expiry.
10. Save a real HTTPS website with an existing ordinary profile backlink and check **Profile link confirmed**; repeat with strict `rel="me"` proof and a same-domain URL. Remove proof and confirm the label returns to ownership unconfirmed. A reachable unrelated site must never receive a confirmed ownership label. Also test redirecting and unreachable sites.
11. Stop the worker, publish, restart it and confirm queued delivery. Make a remote inbox temporarily unavailable and inspect retry/backoff/failure and manual retry behavior.
12. Back up, restore into an isolated project that uses the same identity configuration without connecting both instances to the network, then compare posts, follows, image bytes and private/public key fingerprints. Start only one instance for a given identity.

Record server versions, domains, dates, expected/actual results, and relevant delivery IDs. Do not include private keys, login credentials or full database files in public issues.

## 2.1 regression checklist

- Open a previously cached remote profile. Confirm its header is backfilled, and changing a local header changes the federated image URL without changing the actor ID.
- Import a followed author’s outbox containing Announce; the original, booster attribution and actions must appear. Receive structured and legacy quote references; fetch their public originals and show a bounded nested preview.
- Test single/multiple attachment forms, URL strings/Link objects, GIF/WebP/AVIF, video/audio, image descriptions, sensitive media, WordPress Article objects and a failed CDN.
- Test Open Graph, Twitter image, JSON-LD image, microdata image, image_src, oEmbed thumbnail and WordPress featured-image markup. Include malicious URL schemes and embed HTML; those must never execute.
- Add an instance without following its users, import public candidates, and verify they appear under Federated but do not flood Home. Test login-required public previews and private candidates.
- Delete an authored post from a timeline card, verify return to that timeline and federated tombstone delivery. Hide a remote post locally and restore it through Settings without changing the author’s object.
- At 320/390/768/1024/1440 widths, test empty, short, multiline and over-limit drafts; check visible editor boundaries, keyboard submission, navigation, media, and action reachability.
