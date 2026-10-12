# GuideLink

`guidelink` is a trusted-local plugin for `m3u-editor`.

It keeps canonical IPTV channels aligned with provider source data, reports
availability, refreshes parsed source caches, and audits/applies native
m3u-editor auto-merge and scrubber setup.

## What It Does

- Maintains reusable profiles for playlist matching and source config.
- Links profiles to playlists through the Playlists table.
- Shares database-backed alias sets across profiles through the Data tab.
- Reads bundled alias defaults directly from JSON, alongside editable custom sets.
- Refreshes parsed last-good provider source caches without persisting raw
  scraper responses.
- Builds sync previews before any channel metadata or group writes.
- Applies confirmed diff-only playlist sync writes.
- Reports source coverage, disabled primaries, failover coverage, and scrubber
  context.
- Audits and applies native auto-merge and scrubber setup with confirmation.

## Requirements

- An m3u-editor host with the trusted-local plugin system enabled.
- Host support for plugin-backed dynamic select options.
- Current native scrubber/failover support, including
  `channels.last_scrubber_live`,
  `channel_scrubbers.protect_failover_channels`, and
  `channel_scrubbers.rebuild_failovers_after_scan`.
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
| [Maintain](docs/MAINTAINING.md) | Review, validation, CI boundary, and release checklist. |
| [Contribute](CONTRIBUTING.md) | Pull request expectations and required checks. |

## Quick Install

Download `guidelink.zip` and `SHA256SUMS` from a published GitHub release.
Verify the ZIP, then open **Extensions → Plugin Installs → Upload Plugin Archive**.
Upload the ZIP, scan it, approve/trust the reviewed files, and enable GuideLink.
See [Install](docs/INSTALL.md) for checksum commands and directory/CLI alternatives.

## First Run

1. Install, trust, and enable the plugin.
2. Create or edit the configuration in **Data → Profiles**.
3. Assign the profile in **Data → Playlists** and enable the assignment.
4. Optionally run **Preview Sync** and inspect **Results** for native settings and channel changes.
5. Use **Run Sync** to apply the profile's native settings and sync channel metadata.

Profiles are configured only in **Data → Profiles**. Sync reads the saved
assignment, applies only changed native settings, and preserves unrelated native
options. It uses m3u-editor's native auto-merge and scrubber functionality without
starting an extra scan or failover rebuild.

Choose read-only **Bundled Defaults** in **Data → Alias Sets**, or leave that field
empty for an editable custom set. Bundled entries are read directly from the plugin
JSON and are replaced with the ZIP. **Data → Aliases** contains only custom entries,
which can belong to multiple custom sets. Use **Actions → Preview Alias Set** to
inspect either source. No alias import, reset or background update is needed.

See [Configure](docs/CONFIGURATION.md) for the full operator flow.

## Validation

Standalone validation from the plugin root:

```bash
php scripts/validate-plugin.php
```

From this workspace's Podman harness:

```bash
cd ../podman
./bin/compose exec m3u-editor-plugin-dev \
  php /var/www/html/plugins/guidelink/scripts/validate-plugin.php
```

Full plugin test suite from the Podman harness:

```bash
cd ../podman
./bin/compose --profile test build m3u-editor-plugin-test
./bin/compose --profile test run --rm \
  m3u-editor-plugin-test \
  /var/www/html/plugins/guidelink/tests
```

Latest workspace run on 2026-10-12: 88 tests, 553 assertions. Rebuild the test
image after m3u-editor reference migrations change; otherwise the suite may run
against an older host schema.

## Release Packaging

Push ordinary changes to GitHub to run validation. Push a version tag such as
`v0.1.0` to run all checks and create a **draft release** with `guidelink.zip`,
`SHA256SUMS`, and a source file inventory attached. Review the draft in GitHub
Releases, review the notes generated from commit messages, and publish when ready.
The tag must match `plugin.json`. Release notes live on GitHub; there is no separate
changelog file to maintain.

Local packaging is optional:

```bash
python3 scripts/package-release.py
```

Generated files in `dist/` are ignored by Git. See
[Maintain](docs/MAINTAINING.md#github-release-workflow) for first-push and release
commands. This first release is for clean installs.

## Source Layout

- `plugin.json`
- `Plugin.php`
- `src/`
- `docs/`
- `tests/`
- `scripts/` — validation and optional local packaging.
- `.github/` — CI setup, validation, and draft-release workflows; excluded from ZIPs.

## License

See [LICENSE.md](LICENSE.md).
