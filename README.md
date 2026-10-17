# GuideLink

GuideLink is an m3u-editor plugin that matches playlist channels to a guide EPG
and keeps their names, numbers, and groups aligned with provider listings.

## What It Does

- Reuse matching profiles and channel aliases across playlists.
- Fetch provider listings and retain a cached copy when a refresh fails.
- Report failures, cache fallback and recovery through m3u-editor's native notifications and alert channels, with persistent duplicate suppression.
- Preview matches and channel changes before applying them.
- Reject ambiguous source data and stale write plans; retain partial results when a run is cancelled or fails.
- Choose EPG or provider logos while retaining custom logo overrides.
- Keep managed groups stable through imports and optionally disable excluded or unmatched channels.
- Check channel availability and failover coverage.
- Configure m3u-editor's native auto-merge and scrubber settings.
- Optionally protect guide order and channel numbers with profile-managed playlist layout settings, with per-setting previews and sorting-conflict reports.

## Requirements

- An m3u-editor installation with the trusted-local plugin system enabled.
  See [host requirements](docs/INSTALL.md#prerequisites) for compatibility details.
- A playlist and a Guide EPG containing channel numbers and stream IDs for
  validation and consistent auto-merge identifiers.
- Network access from the host/container when using scraper-backed source
  refresh.
- A Scraper API Key entered in **Data → Profiles** when using an API-backed scraper.

## Documentation

| Guide | Use |
| --- | --- |
| [Install](docs/INSTALL.md) | Install, update, verify, and uninstall the plugin. |
| [Configure](docs/CONFIGURATION.md) | First-run flow, profiles, settings, actions, and runtime behavior. |
| [Extend](docs/EXTENDING.md) | Add countries, providers, sources, fixtures, and scraper backends. |
| [Maintain](docs/MAINTAINING.md) | Development, testing, and releases. |
| [Contribute](CONTRIBUTING.md) | Pull request expectations and required checks. |

## Quick Install

Download `guidelink.zip` and `SHA256SUMS` from [GitHub Releases](https://github.com/djrarky/GuideLink/releases).
Verify the ZIP, then open **Extensions → Plugin Installs → Upload Plugin Archive**.
Upload the ZIP, scan it, approve/trust the reviewed files, and enable GuideLink.
See [Install](docs/INSTALL.md) for checksum commands and directory/CLI alternatives.

## First Run

1. Install, trust, and enable the plugin.
2. Create or edit the configuration in **Data → Profiles**.
3. Assign the profile in **Data → Playlists** and enable the assignment.
4. Optionally run **Preview Sync** and inspect **Results** for native settings and channel changes.
5. Use **Run Sync** to apply the profile's native settings and sync channel metadata.

Sync uses the saved profile and only writes changes. It configures m3u-editor's
native auto-merge and scrubber settings without starting a scan or failover rebuild.

Choose **Bundled Defaults** in **Data → Alias Sets** to use the included channel
aliases. These are read-only and update with the plugin. For your own aliases,
create a custom set and add entries under **Data → Aliases**. Each entry can belong
to multiple custom sets. Use **Actions → Preview Alias Set** to view a set's aliases.

See [Configure](docs/CONFIGURATION.md) for all settings and actions.

## License

See [LICENSE.md](LICENSE.md).
