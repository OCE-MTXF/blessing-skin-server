- [简体中文](./README-zh.md)
- **English**

# Blessing Skin 6.0.4 · PHP 8.6

A downstream PHP 8.6 security maintenance release based on [Blessing Skin](https://github.com/bs-community/blessing-skin-server) 6.0.2, not an upstream release.

Blessing Skin is a web application where you can upload, manage and share your custom skins & capes! Unlike modifying a resource pack, everyone in the game will see the different skins of each other (of course they should register at the same website too).

Blessing Skin is an open-source project written in PHP, which means you can deploy it freely on your own web server!

## Features

- A fully functional skin hosting service
- Multiple player names can be owned by one user on the website
- Share your skins and capes online with skin library!
- Easy-to-use
  - Visual page for user/player/texture management
  - Detailed option pages
  - Many tweaks for a better UI/UX
- Security
  - Support many secure password hash algorithms
  - Email verification for registration
  - Score system for preventing evil requests
- Incredibly extensible
  - Plenty of plugins available
  - Integration with Authme/Discuz (available as plugin)
  - Support custom Yggdrasil API authentication (available as plugin)

## Requirements

Use PHP 8.6 (verified patch: 8.6.0), GD with PNG/WebP, OpenSSL, Argon2id and a supported PDO driver. Serve only `public/` over HTTPS. The complete extension, database and directory requirements are in the [deployment guide](docs/deployment/README.md). Imagick is not used.

## Install and upgrade

Install with `php artisan bs:install` and upgrade with `php artisan bs:upgrade` from a verified local release. The Web installer and automatic core updater have been retired. Back up the database, keys, private configuration and texture files together before any upgrade. See the [deployment guide](docs/deployment/README.md) for first install, 6.0.2 migration and rollback by restore.

## Plugin System

Plugins are disabled by default. Deploy reviewed code and a digest-pinned approval manifest outside the public and writable runtime directories; do not install remote code through the Web interface.

## Build From Source

Use the [locked release builder](docs/deployment/release.md) for production-only dependencies and an allowlisted archive.

## Internationalization

Blessing Skin supports multiple languages, while currently supporting English, Simplified Chinese and Spanish.

If you are willing to contribute your translation, welcome to join [our Crowdin project](https://crowdin.com/project/blessing-skin).

## Documentation

- [Deployment, migration and rollback](docs/deployment/README.md)
- [Plugin policy](docs/plugin-policy.md)
- [Texture policy](docs/texture-policy.md)

When reporting a problem, include the version, deployment topology and a minimal redacted error excerpt. Do not upload full logs, configuration, databases, tokens or keys. The upstream Web installer, live updates and old plugin instructions do not apply to this release.

## Copyright & License

MIT License. See [LICENSE](LICENSE) for the full text.

Copyright (c) 2016-present The Blessing Skin Team — upstream Blessing Skin Server.

Copyright (c) 2026 OCE_MTXF — PHP 8.6 downstream maintenance changes.

This is a downstream maintenance release, not an upstream release. It is not
affiliated with or endorsed by the upstream team.

Thanks to the Blessing Skin Server team.
Thank you for your source code.
