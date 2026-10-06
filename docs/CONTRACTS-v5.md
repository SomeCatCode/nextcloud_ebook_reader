# Contracts round 5: sharing books and shelves

The earlier contracts (v1 to v4, SECURITY-FIXES) still apply. OCS base `/ocs/v2.php/apps/ebookreader/api/v1`; all endpoints `#[NoAdminRequired]`, write endpoints with a `#[UserRateLimit]`. Capabilities report `ebookreader.sharing: true`. Security notes: [SECURITY-SHARING.md](SECURITY-SHARING.md).

## 1. Model

- **Book share:** a read-only Nextcloud user share (`IShare::TYPE_USER`, permission `read`, no mail) of the book file, created through the share manager. All admin sharing policies apply.
- **Shelf share:** the owner's shelf (manual or smart) is shared **live** and read-only with a user. The app shares every book on it as above and keeps the set in line with the shelf: manual shelves right after adding/removing books, smart shelves after a query change and every 15 minutes (`SyncSharedShelvesJob`, also repairs manual shelves). At most 1000 books per shelf share, 50 recipients per shelf.
- Each user keeps their own book row: own reading progress, rating, status, annotations. The recipient's row takes the owner's library metadata (title, authors, series, description, language, publisher, ISBN, date, genres, file/sidecar tags), never rating, status, app-only tags, progress or annotations.
- Shared books belong to the recipient's library wherever Nextcloud mounts the share (the share folder, default `/`), also outside the library folders. They are indexed by a queued `ScanFileJob`, right away on `GET /shelves` and `GET /shares` (up to 25 books per request), and by every scan.
- Shares the user made in Files are reused when they exist and never deleted by the app.

**Tables (migration `Version1006Date20261005140000`):**

`ebookreader_shelf_shares`: `id`, `shelf_id`, `owner_id`, `recipient_id`, `created_at` (ms), `synced_at` (ms). Unique (`shelf_id`, `recipient_id`).

`ebookreader_file_shares` (why the app shares a file): `id`, `owner_id`, `recipient_id`, `file_id`, `shelf_share_id` (0 = direct book share), `share_id` (full Nextcloud share id like `ocinternal:42`; `null` = the user's own share or a share deleted outside the app), `created_at` (ms). Unique (`owner_id`, `recipient_id`, `file_id`, `shelf_share_id`). Several rows can point at one Nextcloud share; the app deletes it when the last row goes.

## 2. JSON

**Share** (`EbookReaderShare`):
```
{
  type: 'book' | 'shelf',
  fileId: number | null,      // book shares
  shelfId: number | null,     // shelf shares
  name: string,               // book title (file name if none) or shelf name
  owner: string, ownerDisplayName: string,
  recipient: string, recipientDisplayName: string,
  createdAt: number,          // ms
  bookCount: number           // shelf: books currently shared through it; book: 1
}
```

**Shelf** (`EbookReaderShelf`, v4 fields plus):
```
owner: string               // user id of the owner (own shelves: the current user)
ownerDisplayName: string
readOnly: boolean           // true = shared with the current user
shareCount: number          // own shelves: number of recipients; shared shelves: 0
```
For a shared shelf `query` is always `null` (the owner's filter may reveal their tags or reading status), `count`/`coverFileIds` count the shared books in the recipient's library, `createdAt` is the time it was shared, `sortOrder` is the owner's and has no meaning for the recipient.

## 3. Endpoints

| Endpoint | Behaviour |
|---|---|
| `GET /shares` | `{outgoing: Share[], incoming: Share[]}`: what the user shares (with `recipient`) and what is shared with the user (`owner`). Only shares made through the app. Book shares whose book left the owner's library are removed while listing. |
| `POST /books/{fileId}/shares` | Body `{shareWith}`. Returns `{share: Share, skipped: 0}`. Sharing again returns the existing share. 60/min. |
| `DELETE /books/{fileId}/shares` | Query/body `shareWith=<user>`: owner stops sharing with that user. Without `shareWith`: the caller removes a book shared with them (`sharedBy=<owner>` optional, default all owners). `{removed: n}`. A shelf share containing the book keeps it shared. 60/min. |
| `POST /shelves/{id}/shares` | Body `{shareWith}`. Returns `{share: Share, skipped: n}`; `skipped` = books that could not be shared (no share permission, over 1000). Up to 100 file shares are created in the request, the rest by a queued `SyncShelfShareJob`. 20/min. |
| `DELETE /shelves/{id}/shares` | `shareWith=<user>`: owner stops sharing. Without it: the recipient removes the shared shelf. The files shared for it are unshared unless another app share needs them. `{removed: 1}`. 60/min. |
| `GET /shelves` | Own shelves (sorted as before), then the shelves shared with the user. |
| `PATCH/DELETE /shelves/{id}`, `POST/DELETE /shelves/{id}/books`, `PUT /shelves/{id}/books/order` | On a shelf shared with the user: **403** `Shared shelves are read-only`. Deleting an own shelf removes its shares. |
| `GET /books?include=shelf:<id>` | Works for shared shelves: the books shared for that shelf share that are in the recipient's library; `sort=shelf` keeps the owner's order for manual shelves. |

**Errors:** `400` missing/unknown recipient, sharing with oneself, too many recipients. `403` sharing disabled on the server or for the user, no share permission on the file (resharing disabled, read-only share without reshare), view-only share, any refusal of the share manager (message = its hint, e.g. "Sharing is only allowed with group members"). `404` book not in the caller's library, shelf not found, share not found.

**User search:** clients use the Files sharing sharee API (`GET /ocs/v2.php/apps/files_sharing/api/v1/sharees?search=…&itemType=file&shareType[]=0`), which applies the admin's enumeration settings. The web UI uses it with `lookup=false`.

## 4. Events and jobs

- `ShareDeletedEvent` / `ShareDeletedFromSelfEvent` (share deleted in Files or left by the recipient): direct book rows of that share are deleted, shelf rows keep their place without a share (`share_id = null`), so the live sync does not create it again against the user's decision. The recipient's book is tombstoned by a queued `ScanFileJob`.
- `ShareAcceptedEvent` (share acceptance enabled): queues indexing for the recipient.
- `SyncSharedShelvesJob` (TimedJob, 15 min) syncs all shelf shares; a shelf share whose shelf, owner or recipient is gone is removed.
- `UserDeletedListener` removes the share records of the user (Nextcloud deletes the shares).
