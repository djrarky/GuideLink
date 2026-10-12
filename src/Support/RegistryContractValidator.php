<?php

namespace AppLocalPlugins\GuideLink\Support;

use AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient;
use AppLocalPlugins\GuideLink\Scrapers\ScraperRegistry;
use AppLocalPlugins\GuideLink\Sources\Contracts\ChannelSource;
use AppLocalPlugins\GuideLink\Sources\Contracts\ProviderSource;
use AppLocalPlugins\GuideLink\Sources\CountrySourceSupport;
use AppLocalPlugins\GuideLink\Sources\SourceRegistry;
use Throwable;

final class RegistryContractValidator
{
    public function __construct(private readonly string $root) {}

    /**
     * @return array<int, string>
     */
    public function validate(): array
    {
        $errors = [];
        $this->validateSourceRegistry($errors);
        $this->validateScraperRegistry($errors);

        return $errors;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validateSourceRegistry(array &$errors): void
    {
        $this->validateCountryPacks($errors);
        try {
            $countryOptions = CountryRulesRepository::options();
        } catch (Throwable $exception) {
            $errors[] = "Country options could not be loaded: {$exception->getMessage()}";
            $countryOptions = [];
        }

        $sourceRegistry = new SourceRegistry(new CountryRulesRepository);

        foreach (SourceRegistry::definitions() as $provider => $definition) {
            $adapterClass = $definition['adapter'] ?? null;
            if (! is_string($adapterClass) || ! class_exists($adapterClass) || ! is_a($adapterClass, ProviderSource::class, true)) {
                $errors[] = "Source provider {$provider} must point to a ProviderSource adapter.";
            } else {
                try {
                    $sourceRegistry->provider($provider);
                } catch (Throwable $exception) {
                    $errors[] = "Source provider {$provider} could not be constructed: {$exception->getMessage()}";
                }
            }

            $providerCountries = SourceRegistry::providerCountries($provider);
            if ($providerCountries === []) {
                $errors[] = "Source provider {$provider} must be supported by at least one country provider pack.";
            }

            foreach ($providerCountries as $country) {
                if (! array_key_exists($country, $countryOptions)) {
                    $errors[] = "Source provider {$provider} references missing country rules: {$country}";
                }
            }

            foreach ($definition['sources'] as $source => $sourceDefinition) {
                $class = $sourceDefinition['source'];
                if (! class_exists($class) || ! is_a($class, ChannelSource::class, true)) {
                    $errors[] = "Source registry entry {$provider}/{$source} must point to a ChannelSource class: {$class}";
                } else {
                    try {
                        $sourceRegistry->source($provider, $source);
                    } catch (Throwable $exception) {
                        $errors[] = "Source registry entry {$provider}/{$source} could not be constructed: {$exception->getMessage()}";
                    }
                }

                $sourceCountries = SourceRegistry::sourceCountries($provider, $source);
                if ($sourceCountries === []) {
                    $errors[] = "Source registry entry {$provider}/{$source} must be supported by at least one country provider pack.";
                }

                foreach ($sourceCountries as $country) {
                    if (! array_key_exists($country, $countryOptions)) {
                        $errors[] = "Source registry entry {$provider}/{$source} references missing country rules: {$country}";
                    }

                    if (! SourceRegistry::supportsCountry($provider, $country, $source)) {
                        $errors[] = "Source registry entry {$provider}/{$source} does not support configured country: {$country}";
                    }
                }
            }
        }

        array_push($errors, ...(new ChannelSourceFixtureValidator($this->root))->errors());
        $this->validateCountrySourcePacks($errors, $countryOptions);
    }

    /**
     * @param  array<int, string>  $errors
     * @param  array<string, string>  $countryOptions
     */
    private function validateCountrySourcePacks(array &$errors, array $countryOptions): void
    {
        $definitions = SourceRegistry::definitions();
        $seen = [];

        try {
            $packs = CountrySourceSupport::packs();
        } catch (Throwable $exception) {
            $errors[] = "Country provider packs could not be loaded: {$exception->getMessage()}";

            return;
        }

        foreach ($packs as $pack) {
            $packKey = "{$pack['country']}/{$pack['provider']}";
            if (isset($seen[$packKey])) {
                $errors[] = "Country provider pack [{$pack['path']}] duplicates {$packKey} already declared by [{$seen[$packKey]}].";
            }
            $seen[$packKey] = $pack['path'];

            if (! array_key_exists($pack['country'], $countryOptions)) {
                $errors[] = "Country provider pack [{$pack['path']}] references missing country rules: {$pack['country']}";
            }

            if (! isset($definitions[$pack['provider']])) {
                $errors[] = "Country provider pack [{$pack['path']}] references unknown source provider: {$pack['provider']}";

                continue;
            }

            if ($pack['sources'] === []) {
                $errors[] = "Country provider pack [{$pack['path']}] must declare at least one source.";
            }

            foreach ($pack['sources'] as $source) {
                if (! isset($definitions[$pack['provider']]['sources'][$source])) {
                    $errors[] = "Country provider pack [{$pack['path']}] references unknown source: {$pack['provider']}/{$source}";
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validateCountryPacks(array &$errors): void
    {
        $root = $this->root.'/src/Sources/Countries';
        if (! is_dir($root)) {
            $errors[] = "Country pack directory [{$root}] was not found.";

            return;
        }

        $countryKeys = [];
        foreach ($this->directories($root) as $countryDirectory) {
            $directoryName = basename($countryDirectory);
            if ($directoryName !== strtoupper($directoryName)) {
                $errors[] = "Country pack directory [{$countryDirectory}] must use an uppercase country code.";
            }

            $metadata = $this->readJson($countryDirectory.'/country.json', "Country metadata [{$directoryName}]", $errors);
            if ($metadata === null) {
                continue;
            }

            $key = $this->validateStringKey($metadata['key'] ?? null, "Country metadata [{$directoryName}] key", $errors);
            if ($key !== null) {
                if (isset($countryKeys[$key])) {
                    $errors[] = "Country metadata [{$countryDirectory}/country.json] duplicates country key [{$key}] already declared by [{$countryKeys[$key]}].";
                }
                $countryKeys[$key] = $countryDirectory.'/country.json';
            }

            if (! is_string($metadata['name'] ?? null) || trim((string) $metadata['name']) === '') {
                $errors[] = "Country metadata [{$countryDirectory}/country.json] must declare a non-empty name.";
            }

            foreach (['normalization', 'regions', 'filters'] as $file) {
                $path = $countryDirectory."/{$file}.json";
                if (is_file($path)) {
                    $this->readJson($path, "Country pack [{$directoryName}] {$file} rules", $errors);
                }
            }

            $this->validateProviderPackFiles($countryDirectory, $key ?? strtolower($directoryName), $errors);
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validateProviderPackFiles(string $countryDirectory, string $country, array &$errors): void
    {
        $providerDirectories = $this->directories($countryDirectory);
        if ($providerDirectories === []) {
            $errors[] = "Country pack [{$countryDirectory}] must include at least one provider-country directory.";

            return;
        }

        foreach ($providerDirectories as $providerDirectory) {
            $path = $providerDirectory.'/provider.json';
            if (! is_file($path)) {
                $errors[] = "Country provider directory [{$providerDirectory}] must include provider.json.";

                continue;
            }

            $metadata = $this->readJson($path, "Country provider pack [{$path}]", $errors);
            if ($metadata === null) {
                continue;
            }

            $this->validateStringKey($metadata['provider'] ?? null, "Country provider pack [{$path}] provider", $errors);

            if (! is_string($metadata['label'] ?? null) || trim((string) $metadata['label']) === '') {
                $errors[] = "Country provider pack [{$path}] must declare a non-empty label.";
            }

            if (! is_array($metadata['sources'] ?? null) || $metadata['sources'] === []) {
                $errors[] = "Country provider pack [{$path}] must declare at least one source.";

                continue;
            }

            $sources = [];
            foreach ($metadata['sources'] as $source) {
                $sourceKey = $this->validateStringKey($source, "Country provider pack [{$path}] source", $errors);
                if ($sourceKey === null) {
                    continue;
                }

                if (isset($sources[$sourceKey])) {
                    $errors[] = "Country provider pack [{$path}] declares duplicate source [{$sourceKey}] for country [{$country}].";
                }
                $sources[$sourceKey] = true;
            }
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validateStringKey(mixed $value, string $label, array &$errors): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            $errors[] = "{$label} must be a non-empty string.";

            return null;
        }

        $key = trim($value);
        if ($key !== strtolower($key)) {
            $errors[] = "{$label} must be lowercase.";
        }

        if (preg_match('/^[a-z0-9_-]+$/', $key) !== 1) {
            $errors[] = "{$label} must contain only lowercase letters, numbers, underscores, or hyphens.";
        }

        return strtolower($key);
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<string, mixed>|null
     */
    private function readJson(string $path, string $label, array &$errors): ?array
    {
        try {
            return JsonFile::read($path, $label);
        } catch (\RuntimeException $exception) {
            $errors[] = $exception->getMessage();

            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function directories(string $path): array
    {
        $directories = array_values(array_filter(glob($path.'/*') ?: [], 'is_dir'));
        sort($directories);

        return $directories;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validateScraperRegistry(array &$errors): void
    {
        $scraperRegistry = new ScraperRegistry;

        foreach (ScraperRegistry::definitions() as $scraper => $definition) {
            $class = $definition['client'];
            if (! class_exists($class) || ! is_a($class, ScraperClient::class, true)) {
                $errors[] = "Scraper registry entry {$scraper} must point to a ScraperClient class: {$class}";
            } else {
                try {
                    $scraperRegistry->make($scraper);
                } catch (Throwable $exception) {
                    $errors[] = "Scraper registry entry {$scraper} could not be constructed: {$exception->getMessage()}";
                }
            }

            if (! is_bool($definition['requires_api_key'] ?? null)) {
                $errors[] = "Scraper registry entry {$scraper} must declare a boolean requires_api_key flag.";

                continue;
            }

            if ($scraperRegistry->requiresApiKey($scraper) !== $definition['requires_api_key']) {
                $errors[] = "Scraper registry entry {$scraper} has inconsistent requires_api_key metadata.";
            }
        }
    }
}
