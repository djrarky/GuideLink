## Summary

-

## Checklist

- [ ] Channel source changes include tiny synthetic fixtures, not cached upstream pages.
- [ ] New or changed countries update `src/Sources/Countries/<Country>/` and mirrored fixtures under `tests/Fixtures/Sources/Countries/<Country>/` where needed.
- [ ] Provider/source compatibility is represented in `src/Sources/Countries/<Country>/<ProviderCountry>/provider.json`.
- [ ] Registry changes are covered by dynamic option providers and contract validation.
- [ ] New scraper backends include focused fake-response tests; source-only changes reuse existing scrapers where possible.
- [ ] Install/configuration/extension docs are updated when behavior or operator workflow changes.
- [ ] `php scripts/validate-plugin.php` passes.
- [ ] Plugin-scoped Pint passes with the Laravel preset used by `CI / Plugin Code Quality` (without the host's Blade formatter).
- [ ] GuideLink plugin tests pass inside an m3u-editor checkout: `php artisan test plugins/guidelink/tests`.
