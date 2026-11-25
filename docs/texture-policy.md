# Texture access and migration

## What “private” means

**Public** textures may appear in the catalog and may be downloaded by anyone, including Minecraft clients, by content hash. A valid public skin or cape in the player owner's closet can be published in the game profile. Public avatars also require a valid public skin in the account's closet. The site setting for manual downloads applies to `/raw/{tid}`; it does **not** make public game textures private or prevent saving an image displayed by the browser.

**Private** textures are visible on this site to their uploader and authorized staff. The uploader can keep them in their own closet, but private textures are **not** sent to game clients and cannot be used as public avatars. There is deliberately no “unlisted but downloadable by hash” mode. Knowing a private hash does not authorize access. An independently published copy with identical bytes is public in its own right; making one record private cannot revoke that copy or bytes previously downloaded. On changing a texture to private or removing it, player slots and public avatars are detached and affected closet entries are removed. Only the uploader can publish a private texture, explicitly changing its visibility to public. Staff can hide a public texture but cannot publish it on the uploader's behalf. Making a texture public again does not restore past assignments.

| Path | Access |
| --- | --- |
| `/textures/{hash}`, `/csl/textures/{hash}` | Valid source on a current public record with a non-banned uploader; stateless |
| `/{player}.json`, `/csl/{player}.json` | Public game profile; unavailable attachments are `null`, including old private, wrong-slot or no-longer-in-closet attachments |
| `/preview/hash/{hash}`, `/avatar/hash/{hash}` | Public-only; hash avatar additionally requires a skin |
| `/avatar/player/{name}`, `/avatar/user/{uid}` | Public-only player/account source, with a default avatar when no compatible public source is available |
| `/asset/{tid}`, `/preview/{tid}`, `/avatar/{tid}` | Browser display; public records or the uploader/staff with a current session |
| `/raw/{tid}` | Same record-level authorization as `/asset/{tid}` plus `allow_downloading_texture` |

The session-aware responses are `private, no-store`. Stateless responses revalidate against current authorization and source before matching an ETag; date-only validators do not produce a 304. Do not configure a CDN or reverse proxy to override these directives, serve the texture directory directly, cache session-aware routes, or serve stale game responses on error. Purge any old game/preview cache at rollout and after policy changes if an upstream proxy did not previously honor revalidation.

## Compatibility preflight

Back up the database and `TEXTURES_DIR` together. Verify GD with PNG and WebP support in the PHP runtime used by both web and CLI. Configure a **private local filesystem** for textures (default `FS_DRIVER=local`, `TEXTURES_DIR=storage/textures`); atomic replacement requires the `.staging` directory and final files on the same filesystem. Do not map or symlink either directory under the web root. Use a cache supporting atomic locks; in a multi-node deployment all workers serving the same site need a shared lock store and consistent blob storage. File cache coordinates only workers sharing that lock directory. Do not run old and new texture writers against the same storage during a rolling upgrade.

On a staging copy, with the candidate release and database configuration:

```sh
php artisan textures:prune --preflight
```

The command reads only and prints aggregate counts; it never lists hashes, names, IDs, paths, or contents, and never deletes or republishes textures. Run off peak for large catalogs: it checks every referenced blob with the same per-image validation as HTTP reads. Repeat before switching traffic. Interpret the counters as follows:

- `texture_records`, `private_records`, `public_with_unavailable_owner`: inventory and visibility impact. Review unavailable owners through authorized administration; do not bulk-publish private rows.
- `supported_png`, `supported_webp`: existing records with a matching digest, valid source and compatible layout. Their original format and legacy URL identifier are retained. Ancillary metadata may be stripped from delivery, so the hash in a legacy URL identifies the original stored bytes, not necessarily the HTTP response body.
- `invalid_digest`, `missing_or_unreadable`, `digest_mismatch`: restore the original verified blob and its lowercase SHA-256 key from the matching backup or have its uploader re-upload. Plugin-defined non-digest aliases need a new canonical upload; never copy arbitrary bytes under a known key.
- `invalid_image`, `incompatible_layout`: inspect the original offline and have its uploader export a supported PNG/WebP with the intended layout, then re-upload/reselect; do not change the hash or type directly to force a match.
- `unavailable_skin_references`, `unavailable_cape_references`, `unavailable_avatar_references`: existing references that the application will not serve. Notify affected players to pick a compatible public texture in their closet, or leave the default. If an owner wants a formerly private texture used in game, explain that publishing makes it available to anyone and let the owner explicitly publish or upload it.

The runtime accepts only still PNG or WebP up to 4 MiB encoded, 2,048 pixels per edge and 1,048,576 pixels total. Site upload caps may be stricter. Classic skins accept square or 2:1 layouts; slim skins are square and capes 2:1, using the Minecraft 64-pixel-wide atlas (integer scale). New uploads are normalized to lossless PNG before SHA-256 deduplication; old PNG/WebP files are not rewritten in place. Standard render requests use fixed sizes; unsupported avatar sizes/cape heights return 422 instead of creating new cache variants. PHP 8.6 GD replaces Imagick/Intervention: the upload hooks named `texture.uploading`, `texture.uploaded` and `uploaded_texture_hash` now receive `GdImage`, and a plugin cannot override the canonical hash. Check such plugins before enabling them.

## Reclamation

An authorized ordinary or moderation deletion removes the record and its references, not the physical blob. A digest remains available to surviving public records that share it. The blob table reserves a digest before a write; files are staged privately, checked and renamed before a transaction creates a reference. Aborted writes may leave an unreferenced blob but must not leave a new record pointing at an incomplete file. A GET never deletes missing data; the legacy `auto_del_invalid_texture` option has no effect.

After an operator reviews the backup and preflight, review up to 500 registered candidates per invocation. Commit only when intended:

```sh
php artisan textures:prune
php artisan textures:prune --commit
```

Only registered blobs unreferenced for more than seven days qualify; commit rechecks live references under the digest lock. The command neither scans arbitrary files nor removes unregistered legacy anomalies. Review those manually after backup with application writers stopped. Old `.staging` files left by a process crash can be removed manually while writers are stopped; they are not public assets. This is a disk reclamation tool, not a substitute for an upload quota or backups.
