# XFedi federation profile

XFedi 2.1 is a single-actor, public-post ActivityPub server. It interoperates through server-to-server ActivityPub, not the Mastodon REST API.

| Area | Behavior |
| --- | --- |
| Discovery | WebFinger with a self link and profile alias; NodeInfo 2.1 |
| Actor | Person, RSA public key, inbox/outbox/follow collections, profile images, website PropertyValue |
| Object | Public Note for local authored posts; incoming Note, Article, Page, Image, Video, Audio and readonly Question content; Mention and Hashtag tags; inReplyTo; published/updated |
| Incoming | Follow, Accept, Reject, Create, Update, Delete, Like, Announce, Undo |
| Outgoing | Follow, Accept, Create, Update, Delete, Like, Announce, Undo |
| Signatures | Cavage `(request-target) host date digest`, RSA-SHA256; signed GET requests for secure-mode peers; separate `/main-key` IDs accepted when bound to the fetched actor |
| Replay/delivery | Durable unique inbox IDs, transactional application, retry queues, per-destination activity deduplication |
| Visibility | Public/unlisted-public objects only; private and followers-only objects are discarded |
| Quotes | `quoteUrl` and a plain HTML link fallback; incoming structured and legacy quote references are fetched for previews; no FEP-044f approval handshake |
| Bookmarks | Private local records, never federated |
| Deletion | Local objects return HTTP 410 Tombstone; incoming deletion tombstones retained; only author-bound cached objects can be changed |

Publishing, replying, favourites, boosts, edits, and deletion are delivered to appropriate known actors; public posts go to followers plus resolved mentions/reply/quote authors. Only cached/discoverable mentions can be addressed. Up to three previously unknown handles are resolved during a publication to bound request time; unresolved handles remain plain text on remote servers. Delivery failure is visible in the Federation page.

Outbox import fetches at most 15 recent public objects per sampled account. It is a recovery/convenience path, not a full history crawler. Incoming public Announce from a followed actor may fetch the original directly from its canonical server, rather than trusting a third party’s embedded post.

Remote IDs, signing actors, keys, ownership, and original HTTP request targets are validated. Inbound signing timestamps must be within 15 minutes. RFC 9421, Linked Data Signatures, relays, follower synchronization, Move, Question/polls, OAuth, remote reports, and restricted-audience messaging are not implemented. HTTPS remote requests permit default port 443 and unprivileged custom ports. Public origins with IP literals, localhost, private/reserved addresses, or unsafe DNS answers are rejected.

## Standards and research

Implementation and interface decisions were checked against primary documentation on 19 October 2026:

- [W3C ActivityPub](https://www.w3.org/TR/activitypub/): actors, inbox/outbox, delivery, and activity side effects.
- [Mastodon ActivityPub profile](https://docs.joinmastodon.org/spec/activitypub/): Note fields, activities, profile metadata, and quote extensions.
- [Mastodon HTTP security](https://docs.joinmastodon.org/spec/security/): signed targets, digests, actor/key ownership, and signed GETs.
- [Mastodon WebFinger](https://docs.joinmastodon.org/spec/webfinger/): account discovery and canonical actor links.
- [Mastodon network interactions](https://docs.joinmastodon.org/user/network/): boosts, private bookmarks, conversations, notifications, and RSS navigation.
- [Mastodon website verification](https://docs.joinmastodon.org/user/profile/#link-verification): HTTPS backlinks and `rel="me"` proof.

Readable human URLs are separate from immutable federation IDs. Keeping this distinction avoids breaking existing followers during a UI/branding upgrade. Mastodon’s current approval-based quotes are more extensive than XFedi’s linked quotes; XFedi deliberately labels that boundary in the interface and documentation.

## Public instance sources and media

The optional public-timeline importer targets Mastodon-compatible public APIs. It does not create an ActivityPub Follow to a whole server, subscribe to a relay, or bypass public-preview access controls. Only a recent bounded sample is imported every polling cycle. API visibility is checked, and canonical objects are independently retrieved before storage. Public boosts retain attribution. Browser media supports image galleries and native video/audio; codecs and remote hosting policies may affect playback.

See [Mastodon public timelines](https://docs.joinmastodon.org/methods/timelines/) and [Open Graph](https://ogp.me/) for the discovery and social-card formats. XFedi’s optional ordinary-link association status is distinct from [Mastodon’s strict link verification](https://docs.joinmastodon.org/user/profile/#link-verification); foreign servers apply their own rules.
