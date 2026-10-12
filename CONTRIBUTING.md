# Contributing

Thanks for helping improve GuideLink. This plugin is intended to accept
small, reviewable updates from third-party contributors, especially channel
source and scraper backend updates.

## Repository Boundaries

Work inside the `guidelink` plugin repository unless a maintainer asks
otherwise. Do not change the `m3u-editor` reference checkout as part of a plugin
pull request.

Important files and directories:

- `plugin.json`: plugin manifest, UI options, actions, schema, and permissions.
- `Plugin.php`: plugin entrypoint and action routing.
- `src/Sources`: source providers, channel sources, and source support code.
- `src/Sources/Countries`: country packs, normalization, filters, regions, and provider-country support.
- `src/Scrapers`: scraper backends.
- `src/Services`: matching, cache, playlist, write-plan, and persistence logic.
- `tests/Feature`: m3u-editor integration tests for plugin behavior.
- `scripts/validate-plugin.php`: standalone plugin validation.
- `docs/INSTALL.md`: operator install, update, verify, and uninstall guide.
- `docs/CONFIGURATION.md`: operator setup, profile, action, and runtime guide.
- `docs/EXTENDING.md`: source and scraper contribution guide.
- `docs/MAINTAINING.md`: maintainer review, validation, and release checklist.

## Pull Request Scope

Prefer small pull requests with one clear purpose. Good examples:

- add one provider source,
- fix one channel source edge case,
- add one scraper backend,
- improve one matching or availability behavior,
- add focused tests for an existing behavior.

Avoid unrelated refactors in source/scraper PRs. If a cleanup is needed before a
feature, make it a separate change.

## Source And Scraper Changes

Read `docs/EXTENDING.md` before changing providers, channel sources, or scraper
clients.

Use this quick decision guide:

- New country rules only: add a country pack under
  `src/Sources/Countries/<Country>/`.
- New provider family: add a provider-country folder such as
  `src/Sources/Countries/UK/SkyUK/` and register the provider in
  `SourceRegistry`.
- New website/feed for an existing provider: add a `ChannelSource` under the
  existing provider-country `Sources/` folder, then list that source in the
  matching `provider.json`.
- New fetching mechanism: add a scraper backend only when `direct_http`,
  `scrape.do`, or `OloStep` cannot fetch the page.

Registry-driven changes must keep these in sync:

- `src/Sources/SourceRegistry.php`
- `src/Sources/Countries/<Country>/<ProviderCountry>/provider.json`
- `src/Scrapers/ScraperRegistry.php`
- dynamic option provider/default expectations in `src/Support/DynamicOptionProviders.php` and `plugin.json` when fields change
- relevant tests

Channel sources should return parsed channel rows only. Raw upstream HTML, JSON,
or markdown responses must not be persisted. Most channel source changes should
reuse an existing scraper backend.

Every registered source/country combination must have at least one small
synthetic fixture that captures the table shape or edge case being supported,
grouped by provider, country, and source. Do not commit full cached provider
pages or raw scraper responses.

Country rules live in `src/Sources/Countries/<Country>/`. Add or update a
country pack only when normalization, aliases, region abbreviations, country
tokens, or exclusions differ from the existing countries. Country-aware provider
and source dropdowns come from `SourceRegistry` plus the country provider packs,
so update `<ProviderCountry>/provider.json` when provider/source support
changes.

Provider, country, source, and scraper selections are dynamic. Do not re-add
static manifest `options` for those fields; the UI and table labels should use
the plugin option providers declared in `plugin.json`.

Scraper clients should throw clear exceptions for invalid upstream responses.
Retry behavior and parsed last-good cache fallback belong in
`SourceCacheService`, not individual scraper clients.

## Safety Expectations

Write actions must keep `"requires_confirmation": true` in `plugin.json`.

Source refresh is intentionally separate from playlist sync. Sync reads
from the parsed last-good source cache or a native source playlist. On the first
run, when no cache exists, Preview Sync and Run Sync prepare it through the
existing source refresh service. Subsequent cache refreshes remain separate.

For channel source changes, guard against suspicious source output:

- too few channels,
- large drops from the previous parsed cache,
- invalid region data,
- missing required canonical fields.

## Required Checks

Run the standalone validator from the plugin root:

```bash
php scripts/validate-plugin.php
```

Run the plugin integration test from this workspace's Podman harness:

```bash
cd ../podman
./bin/compose --profile test run --rm \
  m3u-editor-plugin-test \
  /var/www/html/plugins/guidelink/tests/Unit/Sources/ChannelSourceFixtureTest.php

./bin/compose --profile test run --rm \
  m3u-editor-plugin-test \
  /var/www/html/plugins/guidelink/tests/Feature/Contracts/ManifestRegistryTest.php
```

For scraper changes, also run:

```bash
cd ../podman
./bin/compose --profile test run --rm \
  m3u-editor-plugin-test \
  /var/www/html/plugins/guidelink/tests/Feature/Scrapers/ScraperClientTest.php
```

For broader behavior changes, run the full plugin test directory:

```bash
cd ../podman
./bin/compose --profile test build m3u-editor-plugin-test
./bin/compose --profile test run --rm \
  m3u-editor-plugin-test \
  /var/www/html/plugins/guidelink/tests
```

Rebuild the test image when the m3u-editor reference checkout changes
migrations or native schema. Otherwise tests can run against an older image and
fail on missing host columns even though the reference tree is current.

If host PHP is unavailable, run the validator inside the dev container:

```bash
cd ../podman
./bin/compose exec m3u-editor-plugin-dev \
  php /var/www/html/plugins/guidelink/scripts/validate-plugin.php
```

## Test Coverage

Focus tests on plugin behavior: source parsing, settings writes, persistence,
and user access. Avoid duplicating the host's full scan/recovery lifecycle or
testing cosmetic labels. Reuse an existing behavior test for related assertions.

Add or update tests when behavior changes. At minimum:

- registry or manifest changes should be covered by the registry contract test,
- new country/provider/source support should include a synthetic source fixture,
- channel source behavior should have fixture or sample-content coverage,
- persistence behavior should assert what is written and what is not written,
- write actions should prove dry-run and apply behavior separately.

For a source-only PR, contributors normally do not need to add a scraper test if
they reuse an existing scraper backend.

## Commit Notes

Use clear commit messages that describe the behavior changed, not only the file
edited. For example:

```text
Add TV channel source edge-case coverage
```

Release notes are generated from commit subjects. Prefer `feat:`, `fix:`, `perf:`,
`refactor:`, `docs:`, or `chore:` so changes appear in the appropriate section.
For example, `fix: Preserve timeshift matching`. Optional scopes such as
`feat(aliases): Share custom names` are supported. Mark breaking changes with `!`
before the colon or a `BREAKING CHANGE:` paragraph in the body. Other commit
subjects still appear under Other Changes. See
[release notes](docs/MAINTAINING.md#commit-messages-and-release-notes) for details.

Keep generated archives, raw scraper captures, local secrets, and environment
files out of commits.
