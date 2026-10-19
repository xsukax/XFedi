CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY CHECK(id=1), username TEXT NOT NULL UNIQUE,
 name TEXT NOT NULL, bio TEXT NOT NULL DEFAULT '', password_hash TEXT NOT NULL,
 private_key TEXT NOT NULL, public_key TEXT NOT NULL, avatar TEXT NOT NULL DEFAULT '',
 cover TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS posts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, content TEXT NOT NULL, image TEXT NOT NULL DEFAULT '',
 alt TEXT NOT NULL DEFAULT '', in_reply_to TEXT NOT NULL DEFAULT '',
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, deleted INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS remote_actors (
 id TEXT PRIMARY KEY, handle TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '',
 name TEXT NOT NULL DEFAULT '', bio TEXT NOT NULL DEFAULT '', inbox TEXT NOT NULL,
 outbox TEXT NOT NULL DEFAULT '', followers TEXT NOT NULL DEFAULT '', shared_inbox TEXT NOT NULL DEFAULT '',
 avatar TEXT NOT NULL DEFAULT '', key_id TEXT NOT NULL, public_key TEXT NOT NULL,
 fetched_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS follows (
 actor_id TEXT PRIMARY KEY, follow_id TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS followers (
 actor_id TEXT PRIMARY KEY, follow_id TEXT NOT NULL, created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS remote_notes (
 id TEXT PRIMARY KEY, author_id TEXT NOT NULL, content TEXT NOT NULL DEFAULT '',
 content_html TEXT NOT NULL DEFAULT '', summary TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '',
 in_reply_to TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 deleted INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_remote_notes_created ON remote_notes(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_remote_notes_author ON remote_notes(author_id);
CREATE TABLE IF NOT EXISTS likes_remote (
 actor_id TEXT NOT NULL, post_id INTEGER NOT NULL, activity_id TEXT NOT NULL,
 PRIMARY KEY (actor_id,post_id));
CREATE TABLE IF NOT EXISTS likes_local (
 note_id TEXT PRIMARY KEY, activity_id TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS activities (ref TEXT PRIMARY KEY, json TEXT NOT NULL, created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS deliveries (
 id INTEGER PRIMARY KEY AUTOINCREMENT, destination TEXT NOT NULL, activity_id TEXT NOT NULL,
 json TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, run_after INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'pending', last_error TEXT NOT NULL DEFAULT '',
 UNIQUE(destination, activity_id));
CREATE INDEX IF NOT EXISTS idx_deliveries_next ON deliveries(status,run_after);
CREATE TABLE IF NOT EXISTS seen_activities (id TEXT PRIMARY KEY, received_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS login_attempts (ip_hash TEXT PRIMARY KEY, attempts INTEGER NOT NULL, window_start INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, actor_id TEXT NOT NULL,
 object_id TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, is_read INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications(is_read);