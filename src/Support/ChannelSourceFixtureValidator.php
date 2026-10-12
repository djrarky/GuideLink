<?php

namespace AppLocalPlugins\GuideLink\Support;

use AppLocalPlugins\GuideLink\Sources\SourceRegistry;

final class ChannelSourceFixtureValidator
{
    public function __construct(private readonly string $root) {}

    /**
     * @return array<int, array{label: string, provider: string, country: string, source: string, case: string, html: string, expected: string, provider_pack: string}>
     */
    public function cases(): array
    {
        $fixtures = [];
        foreach ($this->directories($this->root.'/tests/Fixtures/Sources/Countries') as $countryDir) {
            $country = $this->countryKey($countryDir);
            foreach ($this->directories($countryDir) as $providerDir) {
                $provider = $this->providerKey($providerDir);
                foreach ($this->directories($providerDir.'/Sources') as $sourceDir) {
                    foreach (glob($sourceDir.'/*.html') ?: [] as $htmlPath) {
                        $case = basename($htmlPath, '.html');
                        $source = strtolower(basename($sourceDir));
                        $label = implode('/', [$provider, $country, $source, $case]);

                        $fixtures[] = [
                            'label' => $label,
                            'provider' => $provider,
                            'country' => $country,
                            'source' => $source,
                            'case' => $case,
                            'html' => $htmlPath,
                            'expected' => $sourceDir.'/'.$case.'.expected.php',
                            'provider_pack' => $providerDir,
                        ];
                    }
                }
            }
        }

        usort($fixtures, fn (array $left, array $right): int => $left['label'] <=> $right['label']);

        return $fixtures;
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        $errors = $this->layoutErrors();
        $covered = [];

        try {
            $cases = $this->cases();
        } catch (\RuntimeException $exception) {
            $errors[] = $exception->getMessage();

            return $errors;
        }

        foreach ($cases as $fixture) {
            if (! is_file($fixture['expected'])) {
                $errors[] = "Expected rows missing for channel source fixture [{$fixture['label']}].";

                continue;
            }

            $expected = require $fixture['expected'];
            if (! is_array($expected)) {
                $errors[] = "Channel source fixture expectation [{$fixture['expected']}] must return an array.";

                continue;
            }

            if (! SourceRegistry::supportsCountry($fixture['provider'], $fixture['country'], $fixture['source'])) {
                $errors[] = "Channel source fixture [{$fixture['label']}] targets an unsupported provider/country/source combination.";

                continue;
            }

            $covered[$fixture['provider']][$fixture['country']][$fixture['source']] = true;
        }

        foreach (SourceRegistry::definitions() as $provider => $definition) {
            foreach (array_keys($definition['sources']) as $source) {
                foreach (SourceRegistry::sourceCountries($provider, $source) as $country) {
                    if (! ($covered[$provider][$country][$source] ?? false)) {
                        $errors[] = "Registered source [{$provider}/{$country}/{$source}] must have at least one synthetic channel source fixture.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function layoutErrors(): array
    {
        $errors = [];
        $root = $this->root.'/tests/Fixtures/Sources';

        foreach ($this->directories($root) as $directory) {
            if (basename($directory) !== 'Countries') {
                $errors[] = "Channel source fixtures must live under tests/Fixtures/Sources/Countries; found legacy fixture directory [{$directory}].";
            }
        }

        return $errors;
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

    private function countryKey(string $countryDirectory): string
    {
        $metadata = JsonFile::read($this->sourceCountryDirectory($countryDirectory).'/country.json', 'Country fixture metadata');

        return strtolower((string) ($metadata['key'] ?? basename($countryDirectory)));
    }

    private function providerKey(string $providerDirectory): string
    {
        $metadata = JsonFile::read($this->sourceProviderDirectory($providerDirectory).'/provider.json', 'Country provider fixture metadata');

        return strtolower((string) ($metadata['provider'] ?? basename($providerDirectory)));
    }

    private function sourceCountryDirectory(string $fixtureCountryDirectory): string
    {
        return $this->root.'/src/Sources/Countries/'.basename($fixtureCountryDirectory);
    }

    private function sourceProviderDirectory(string $fixtureProviderDirectory): string
    {
        return $this->sourceCountryDirectory(dirname($fixtureProviderDirectory)).'/'.basename($fixtureProviderDirectory);
    }
}
