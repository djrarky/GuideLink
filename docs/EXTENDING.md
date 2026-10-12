# Extending GuideLink

GuideLink keeps source and scraper wiring deliberately small and explicit.
Third-party changes should normally add one channel source or one scraper client
at a time, then update the registry and focused tests in the same pull request.

For installing or configuring the plugin, see [Install](INSTALL.md) and
[Configure](CONFIGURATION.md).

## Mental Model

There are separate extension points:

- Channel sources turn provider pages into canonical channel rows.
- Scraper clients fetch the source page content.
- Country rules describe country-specific normalization, aliases, and regions.

A provider source chooses which `ChannelSource` understands the fetched content.
A scraper backend chooses how the content is fetched. Most source changes can
reuse an existing scraper. Profile provider, country, source, and scraper
selects are backed by plugin option providers so the UI follows the registries.

The installed scraper adapters currently cover Sky UK. Additional countries and
providers need compatible adapters and pack metadata before they become selectable.
Matching still requires a numbered guide whose slots correspond to the canonical
source; adding a country pack does not infer that relationship for an arbitrary EPG.

Use this rule of thumb:

- Add a country when channel naming, aliases, filters, or region rules differ.
- Add a provider when a new provider family needs its own adapter or support
  code.
- Add a source when the provider already exists but another website/feed needs
  parsing.
- Add a scraper backend only when existing fetchers cannot retrieve the source
  page.

## Source Tree Layout

Country and provider-specific source code lives together:

```text
src/Sources/
  Contracts/
    ChannelSource.php
    ProviderSource.php
    SourceRequest.php
  Countries/
    UK/
      country.json
      normalization.json
      regions.json
      filters.json
      SkyUK/
        provider.json
        SkyUKSource.php
        Sources/
          ViewersVaultSource.php
          TvChannelListsSource.php
        Support/
          RegionParser.php
          RegionRules.php
  SourceRegistry.php
```

Use uppercase country directory names such as `UK`, `USA`, or `CA`. Use a
provider-country directory name that describes the actual support pack, such as
`SkyUK`. That keeps Sky UK code, metadata, parsers, and support helpers near the
country rules they rely on.

The standalone validator enforces the convention: country directories are
uppercase, country/provider/source keys are lowercase, required country metadata
and provider packs exist, optional country rule JSON files are valid when
present, and provider-country packs do not duplicate a provider for one country.

## Dynamic UI Support

Do not add static `options` for provider, country, source, or scraper profile
fields in `plugin.json`.

The host UI asks the plugin for options at render time:

- `guidelink_countries` comes from `SourceRegistry::countryOptions()`.
- `guidelink_providers` comes from
  `SourceRegistry::providerOptions($country)`.
- `guidelink_sources` comes from
  `SourceRegistry::sourceOptions($provider, $country)`.
- `guidelink_scrapers` comes from `ScraperRegistry::options()`.

That means the dropdown chain is:

```text
country -> supported providers -> supported provider sources
```

The runtime path also validates the same combination through
`SourceRequest::assertSupported()`, so stale saved rows or manually crafted
payloads cannot run unsupported provider/country/source combinations.

## Add A Provider Source

1. Add a source under
   `src/Sources/Countries/<Country>/<ProviderCountry>/Sources/`.
2. Implement `AppLocalPlugins\GuideLink\Sources\Contracts\ChannelSource`.
3. Return source URLs and formats from `definition()`.
4. Return canonical channel rows from `parseChannels()`.
5. Register the source in `src/Sources/SourceRegistry.php`.
6. Add the source key to each supported country provider pack, such as
   `src/Sources/Countries/UK/SkyUK/provider.json`.
7. Keep registry metadata in sync. The editable profile field and profile table
   display column are backed by the `guidelink_sources` options provider
   and must not duplicate static `options`.
8. Add at least one synthetic fixture for every registered source/country
   combination.
9. Run validation and the plugin feature test.

For example, a new Sky UK source would usually touch:

```text
src/Sources/Countries/UK/SkyUK/Sources/NewSource.php
src/Sources/Countries/UK/SkyUK/provider.json
src/Sources/SourceRegistry.php
tests/Fixtures/Sources/Countries/UK/SkyUK/Sources/NewSource/basic.html
tests/Fixtures/Sources/Countries/UK/SkyUK/Sources/NewSource/basic.expected.php
```

Canonical channel rows must include:

```php
[
    'number' => 101,
    'name' => 'Canonical Channel Name',
    'category' => 'UK| Entertainment',
    'aliases' => ['Optional Alias'],
    'stream_id' => '',
    'guide_valid' => true,
]
```

Use stable lowercase keys in the registry and manifest. Existing keys use
`viewersvault` and `tvchannellists`.

Sky UK HTML sources share `AbstractChannelSource`. Set protected `CHANNEL_URL`
and `REGION_URL` constants to inherit its feed definition. The section category
map is the allowlist for parsed tables; unlisted sections are skipped automatically.
Use the shared `$names` extractor and `$postProcessor->finish()` directly.
Keep table-layout differences in each source parser.

Sources without a separate region feed can omit `region_url` and `region_format`
from their definition. Their provider adapter receives empty content in
`resolveRegion()` and can return an empty region array. Small JSON or text feeds
are accepted; parsed channel counts and the configured drop limit determine
whether a refresh may replace the last-good cache.

Channel source tests should use tiny synthetic fixtures that represent the table
shapes the source supports. Do not commit full upstream pages, scraper cache
files, or raw fetched responses. Live upstream smoke tests can be added
separately, but they must be opt-in and skipped by default.

For a channel-source-only change, add or update a pair of fixture files:

```text
tests/Fixtures/Sources/Countries/<Country>/<ProviderCountry>/Sources/<Source>/<case>.html
tests/Fixtures/Sources/Countries/<Country>/<ProviderCountry>/Sources/<Source>/<case>.expected.php
```

Add `tests/Fixtures/Sources/Countries/<Country>/<ProviderCountry>/region.php`
when the source needs representative region data for that provider-country pack.
A specific source or case can override it with `region.php` or
`<case>.region.php` in the source fixture folder.

## Add A Country

Country packs live in `src/Sources/Countries/<Country>/`. Add or update one
when a country needs its own display option, provider/source compatibility, or
country-specific normalization and filtering rules.

The PHP port splits the Python reference model into smaller files. Python keeps
all country rules in one `countries/<name>.yaml` file. GuideLink keeps
the required country identity in `country.json`, keeps optional rule sections in
separate JSON files, and adds `provider.json` files for the plugin UI
compatibility matrix.

### File Layout

Use an uppercase country directory name such as `UK`, `USA`, or `CA`:

```text
src/Sources/Countries/USA/
  country.json
  normalization.json      optional
  regions.json            optional
  filters.json            optional
  <ProviderCountry>/
    provider.json
```

File roles:

| File | Required | Purpose |
| --- | --- | --- |
| `country.json` | Yes | Defines the stable country key and UI label. |
| `<ProviderCountry>/provider.json` | Yes, one per supported provider | Defines which registered provider and sources support this country. |
| `normalization.json` | No | Adds language words, canonical substitutions, and noise tokens. |
| `regions.json` | No | Adds region abbreviations, directions, territory groups/tokens, and exclusion suffix cleanup for source restrictions. |
| `filters.json` | No | Adds non-linear group and title exclusion regexes. |

Optional rule files should be omitted when the country does not need those
rules. If present, each file must contain a valid JSON object.

### Country Key, Name, And Folder

`country.json` owns the country option exposed to users:

```json
{
  "key": "us",
  "name": "United States"
}
```

The fields mean:

- `key` is the stable saved/config value, such as `us`. It must be lowercase and
  unique across country packs.
- `name` is the display label shown in the UI, such as `United States`.
- The folder name, such as `USA`, is the filesystem location. It should be an
  uppercase country code or short country identifier.

In a valid country pack, the UI name is not derived from the folder. It comes
from `country.json.name`. The runtime loader has defensive fallbacks for older
or partial data, but the validator requires both `key` and `name`, so new packs
should not rely on those fallbacks.

With the example above:

- the country folder is `USA`,
- the saved profile/action value is `us`,
- the UI displays `United States`.

### Optional Rule Files

Missing optional rule files behave like empty sections in Python's country YAML.
For example, omitting `regions.json` is equivalent to having no
`region_abbrevs` rules.

Use `normalization.json` for language conventions, semantic exceptions, and country-specific noise.
Optional language fields are:

| Field | Shape | Purpose |
| --- | --- | --- |
| `number_words` | Word-to-digit string map | Normalize written numbers, such as `"DEUX": "2"`. |
| `separator_words` | Word list | Treat conjunctions such as `AND` or `ET` as separators. |
| `channel_labels` | Word list | Allow a label such as `TV` or `CHAÎNE` to be added or omitted, while retaining label-conflict checks. |
| `presentation_suffixes` | Word list | Allow a trailing presentation label after an otherwise complete multiword name. |
| `plural_suffixes` | Suffix list | Recognize short plural extensions without weakening unrelated-word checks. |

Omitted fields are empty: new country packs do not inherit English conventions.
The UK pack explicitly supplies its English words. Lists compile once when rules
are loaded and do not belong in provider-specific matcher branches.
Letters, combining marks, and decimal digits are retained across scripts;
matching lengths and edit distances count Unicode characters. NFC normalization
also unifies composed/decomposed accents when the host provides `Normalizer`.

Channel-specific mappings belong in guide-targeted alias sets. Read-only bundled
JSON lives in `src/AliasSets/`; each dataset has an ID, informational revision,
name, country and entries with `target_stream_id`, `target_name`, and `aliases`.
Optional `guide_names` lists verified guide spellings such as abbreviations.
`bundle_id` on the saved set selects a dataset; no bundled entries are copied to
the aliases table. Sets without a bundle ID load editable database aliases.

Matching and Preview Alias Set read the installed JSON directly, without caching
or revision-gated installation. Change the dataset and package the ZIP to deliver
new defaults; there is no alias maintenance action or polling. Bump the revision
to identify dataset changes in previews. Register new dataset IDs in
`BundledAliasService::BUNDLE_IDS`; the GUI choices come from that registry.

Bundled target identities are checked against actual guide names using normalized,
spacing-insensitive equality, not fuzzy matching or source names. Custom sets
shared across guides require matching target names as well as IDs. Missing runtime
targets warn and skip, while ambiguity and conflicts remain errors. Explicit aliases
can contain different digits from their targets; automatic matching retains number
checks. Include +1 names explicitly against their +1 guide IDs.

Use `canonical_subs` only when a family-wide transformation needs a pattern.
Spacing, punctuation, configured number words, quality tags, guide-name aliases,
composite labels, and source-derived initials are handled automatically. Avoid adding a per-channel regex when current source or
guide names already establish the identity. The matcher also considers numbered
guide-only entries in scraper mode, so a missing scraped row does not need a
channel-specific alias. Guide-only matches preserve their existing groups.

Regional fallback normalizes the remaining base name again, allowing a verified
old name or a guide alias to resolve without removing regional distinctions from
the catalogue. Short alphabetic identifiers are not fuzzy spelling variants.

```json
{
  "canonical_subs": [],
  "noise_tokens": ["US"]
}
```

Keep source territory parsing keys (`country_tokens`, `excl_suffix_cleanup`, and
`region_groups`) in `regions.json` alongside the aliases. They describe which
provider rows apply to a region and are independent of channel-name matching.

Use `regions.json` for region aliases. `regional_alternate_categories` optionally
lists source categories that explicitly identify alternative regional feeds (the
Sky pack uses `["Regional"]`). A generic name can select a unique main-lineup feed
only when every other regional candidate is in one of those categories. Omit this
field when the source categories do not carry that meaning; ambiguity then requires
review. This rule never picks the lowest channel number.

Example region aliases:

Only list distinct abbreviations. Whitespace, symbols, and configured separator words are
normalized consistently for matching and source-region selection. Canonical
region names are recognized automatically. Regional replacements are compiled
once and applied in one pass, longest phrase first, so JSON ordering cannot
split a longer region or expand a canonical name twice.
Optional `region_directions` lists the compass words used to derive broad
regional fallbacks from compound region names; the UK pack supplies
`NORTH`, `SOUTH`, `EAST`, and `WEST`.

```json
{
  "region_abbrevs": {
    "NEW_YORK": [
      "NY"
    ]
  }
}
```

Use `filters.json` for non-linear exclusions:

```json
{
  "non_linear_group_regex": [
    "\\b24/7\\b"
  ],
  "non_linear_title_regex": [
    "\\bEVENT\\s+\\d"
  ]
}
```

### Provider Compatibility

Provider-country folders such as `SkyUK` or `SkyUSA` declare which registered
provider/source keys support that country:

```json
{
  "provider": "sky",
  "label": "Sky UK",
  "sources": [
    "viewersvault",
    "tvchannellists"
  ]
}
```

`provider` must match a provider key from `SourceRegistry::definitions()`.
`sources` must list source keys registered under that provider. If a source is
not listed, it will not appear for that provider/country in the UI and runtime
support checks will reject that combination.

`label` names the provider-country support pack. It is separate from
`country.json.name`; the country dropdown uses `country.json.name`.

Country changes should include source fixtures under the matching country path
and provider/source support metadata in a provider-country pack when support
changes. For example, Sky UK fixtures live under
`tests/Fixtures/Sources/Countries/UK/SkyUK/...`.

### Minimal New Country

For a new country that reuses an existing provider and an existing parser, the
smallest PR usually adds:

```text
src/Sources/Countries/USA/country.json
src/Sources/Countries/USA/<ProviderCountry>/provider.json
tests/Fixtures/Sources/Countries/USA/<ProviderCountry>/Sources/<Source>/basic.html
tests/Fixtures/Sources/Countries/USA/<ProviderCountry>/Sources/<Source>/basic.expected.php
```

Add `normalization.json`, `regions.json`, or `filters.json` only when that
country needs matching rules.

Only do this when the registered `ChannelSource` can parse that country's source
page shape. If parsing, region handling, or post-processing differs, update the
provider/source implementation as part of the same PR.

## Add A Provider

Adding a new provider is a larger change than adding a source. Put
country-specific provider logic under
`src/Sources/Countries/<Country>/<ProviderCountry>/`, with shared helpers under
`Support/` and individual channel sources under `Sources/`, then add the
provider to `SourceRegistry::definitions()` with a provider adapter that
implements `AppLocalPlugins\GuideLink\Sources\Contracts\ProviderSource`.
The `settings.provider` select is backed by `guidelink_providers`, so do
not duplicate provider options in `plugin.json`.

If the provider needs country-specific aliases or exclusions, add the rules to
the matching country pack or introduce a new country pack in the same shape.

For example, adding a new provider for the UK usually touches:

```text
src/Sources/Countries/UK/NewProviderUK/provider.json
src/Sources/Countries/UK/NewProviderUK/NewProviderUKSource.php
src/Sources/Countries/UK/NewProviderUK/Sources/<SourceName>Source.php
src/Sources/Countries/UK/NewProviderUK/Support/
src/Sources/SourceRegistry.php
tests/Fixtures/Sources/Countries/UK/NewProviderUK/Sources/<SourceName>/basic.html
tests/Fixtures/Sources/Countries/UK/NewProviderUK/Sources/<SourceName>/basic.expected.php
```

Register the provider label, adapter class, source labels, and source classes in
`SourceRegistry::definitions()`. Then list only the country-supported source
keys in that country's `<ProviderCountry>/provider.json`.

## Add A Scraper Backend

1. Add a client under `src/Scrapers/Backends/`.
2. Implement `AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient`.
3. Register it in `src/Scrapers/ScraperRegistry.php`.
4. Set `requires_api_key` correctly.
5. Keep `settings.scraping_api` backed by `guidelink_scrapers`; do not
   duplicate scraper options in `plugin.json`.
6. Add fetch behavior coverage when the client has non-trivial response parsing.
7. Run validation and the plugin feature test.

Scraper clients should throw a clear `RuntimeException` when the upstream
response is structurally invalid. `SourceCacheService` handles retries and
last-good cache fallback around that failure.

Users enter API keys in **Data → Profiles → Scraper API Key**. Scraper clients
receive the resolved profile/action key as an argument; they must not read
credentials from environment variables.

## Compatibility Checklist

Before opening a provider/source/country PR, check:

- The provider exists in `SourceRegistry::definitions()`.
- The country exists in `src/Sources/Countries/<Country>/country.json`.
- The provider-country support pack exists at
  `src/Sources/Countries/<Country>/<ProviderCountry>/provider.json`.
- Every source listed in `provider.json` exists under that provider in
  `SourceRegistry`.
- Every registered source/country pair has at least one synthetic fixture.
- `plugin.json` still uses `options_provider` for provider, country, source,
  and scraper fields.

## Required Checks

Run these before submitting a source or scraper PR:

```bash
php scripts/validate-plugin.php
```

From the workspace Podman harness:

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

Rebuild the test image first when the m3u-editor reference checkout changes
migrations or native schema.

The validator and feature tests assert that:

- registry classes implement the required contracts,
- registry-backed options are exposed through plugin option providers,
- runtime defaults match `plugin.json`,
- country options match `src/Sources/Countries/*/country.json`,
- dynamic country/source option providers match `SourceRegistry`,
- every registered source/country has at least one synthetic fixture,
- write actions still require explicit confirmation.

## Review Checklist

- The new registry key is stable and lowercase.
- Existing manifest fields stay backed by their dynamic `options_provider`;
  static `options` are not reintroduced for provider, country, source, or
  scraper choices.
- Prefilled playlist assignment rows remain clearable, not deletable, and run
  result exports continue to include diagnostic details.
- The channel source emits parsed channel rows only, never raw upstream payloads.
- The scraper does not persist raw responses.
- API-key requirements are represented by `requires_api_key`.
- Validation passes without needing external network access.
- Source refresh can still fall back to the parsed last-good cache.
