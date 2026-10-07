# Security model: sharing books and shelves

Sharing (see [CONTRACTS-v5.md](CONTRACTS-v5.md)) never grants access by itself. Every shared book is a regular read-only Nextcloud user share created through `OCP\Share\IManager`, so Nextcloud decides who can read the file and the app only remembers why it shared it.

## 1. Who may share what

| Check | Where |
|---|---|
| The caller owns the shelf (`findByUserAndId(owner, id)`); otherwise 404. Recipients get 403 for every change of a shared shelf (`ShelfService::get`). | ShareService, ShelfService |
| The book is a non-deleted row of the caller's library and the file is reachable through the caller's user folder; otherwise 404. Shelf syncs only share the owner's own books (manual: assignment ∩ own rows; smart: the owner's `findBooks`). | ShareService::ownBook/ownerFile/desiredFileIds |
| Sharing is enabled (`shareApiEnabled`) and not disabled for the caller (`sharingDisabledForUser`, excluded groups); otherwise 403. | ShareService::assertCanShare |
| The file is shareable (`isShareable()` and `PERMISSION_SHARE`). This covers "allow resharing" off and incoming shares without reshare permission; otherwise 403. Files from view-only shares (download disabled) are refused as well (`canReadContent`). | ShareService::assertShareable |
| Recipient: non-empty, not the caller, an existing user (`IUserManager::userExists`); otherwise 400. | ShareService::validateRecipient |
| Everything else is enforced by `IManager::createShare` (e.g. "share only with group members", "already shared"); its hint is returned as the 403 message. | ShareService::ensureFileShare |
| The share has permission `read` only (no update, delete or reshare) and no mail notification. | ShareService::ensureFileShare |

## 2. What the recipient sees

- Only the shared files, through Nextcloud's own share mount. The recipient gets their own book rows; reading progress, rating, status, annotations and app-only tags of the owner are never read for or sent to the recipient.
- From the owner's library row the recipient's copy takes descriptive metadata (title, authors, series, description, language, publisher, ISBN, date, genres and tags from the file or sidecar). These describe the shared book, not the owner. Genres and tags that exist only in the owner's library (app-only, `source = app`) are never copied, and the owner's personal tags are never written into the sidecar or the book file either (only genres and file tags are).
- Metadata edits of the recipient never touch the owner's files: for a book shared through the app they are always stored as per-user overrides in the recipient's library (target "library", whatever the recipient's metadata target is), they win over the owner's values when the book is indexed again, and "write metadata into the book file" is refused (403).
- A native Nextcloud folder share with edit permission is different: owner and recipient then really share the book file and its sidecar. Edits check the sidecar's change marker (etag/mtime) before writing; if the sidecar changed since the user's library row was built, the row is refreshed first (fields the user did not edit keep the other user's values), a change during the write is retried, and after every sidecar or file write the book is indexed again for all users who have it.
- A shared smart shelf is listed with `query: null`: the owner's saved filter (which may contain private tags or the reading status) is not exposed. `shelf:<id>` for a shared shelf resolves to the file share rows of that recipient (`recipient_id` = caller), never to the owner's query or assignments, so it cannot be used to probe the owner's library.
- `GET /shares` lists only rows where the caller is owner or recipient; display names come from `IUserManager`.

## 3. Deleting shares

- The app deletes a Nextcloud share only if it created it (`share_id` stored) and no other reason (direct share or another shelf share) still needs it.
- Shares the user created in Files are reused (`share_id = null`) and never deleted.
- A share deleted outside the app (Files, recipient leaving it) is not re-created by the live sync.
- Deleting a shelf, stopping a shelf share, the recipient removing it, or deleting the owner/recipient (user deletion, background sync) removes the app's records; the recipient's book rows are tombstoned by `ScanFileJob` once the file is no longer reachable.

## 4. Limits

- 60 book shares/min, 20 shelf shares/min, 60 removals/min per user (`UserRateLimit`).
- At most 50 recipients per shelf, 1000 books per shelf share; up to 100 file shares are created inline, the rest by a queued job. Inline indexing for recipients: 25 books / 5 s per request, larger files go to background jobs.

## 5. Known limitations

- Group recipients are not supported (only users).
- Nextcloud may create a "shared with you" notification per file share, so sharing a shelf with many books can create many notifications.
- With share acceptance enabled (`sharing.enable_share_accept`), books appear in the recipient's library only after the recipient accepted the shares in Files.
