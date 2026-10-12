<?php

namespace AppLocalPlugins\GuideLink\Tests\Unit\Sources;

require_once dirname(__DIR__, 2).'/Feature/PluginFeatureTestCase.php';

use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support\RegionRules;
use AppLocalPlugins\GuideLink\Sources\SourceRegistry;
use AppLocalPlugins\GuideLink\Support\ChannelSourceFixtureValidator;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;

class ChannelSourceFixtureTest extends PluginFeatureTestCase
{
    private CountryRulesRepository $countries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->countries = new CountryRulesRepository;
    }

    public function test_region_restrictions_respect_inclusions_and_exclusions(): void
    {
        $regions = new RegionRules;
        $rules = $this->countries->resolve(['country' => 'uk'], []);
        foreach (['Eng', 'Scot', 'NI', 'Wales'] as $token) {
            $this->assertTrue($regions->applies('UK excl CI', [$token], $rules));
        }
        $this->assertFalse($regions->applies('UK excl CI', ['CI'], $rules));
        $this->assertTrue($regions->applies('UK', ['CI'], $rules));
        $this->assertFalse($regions->applies('Scot excl CI', ['Eng'], $rules));
        $this->assertFalse($regions->applies('UK excluding Eng, Wales', ['Wales'], $rules));
        $this->assertTrue($regions->applies('ScotSTV', ['Scot', 'ScotSTV'], $rules));
        $this->assertFalse($regions->applies('ScotSTV', ['Scot', 'ScotITVB'], $rules));
    }

    public function test_registered_channel_sources_read_fixture_cases(): void
    {
        $validator = new ChannelSourceFixtureValidator(dirname(__DIR__, 3));
        $errors = $validator->errors();

        $this->assertSame([], $errors, implode(PHP_EOL, $errors));

        $fixtures = $validator->cases();
        $this->assertNotSame([], $fixtures, 'At least one channel source fixture is expected.');

        $registry = new SourceRegistry($this->countries);
        foreach ($fixtures as $fixture) {
            $source = $registry->source($fixture['provider'], $fixture['source']);
            $actual = $source->parseChannels(
                $this->readFile($fixture['html']),
                $this->regionFor($fixture),
                $this->countries->resolve(['country' => $fixture['country']], []),
            );

            $this->assertSame($this->expectedRows($fixture['expected']), $actual, $fixture['label']);
        }
    }

    /**
     * @param  array{label: string, provider: string, country: string, source: string, case: string, html: string, expected: string, provider_pack: string}  $fixture
     * @return array<string, mixed>
     */
    private function regionFor(array $fixture): array
    {
        foreach ($this->regionPaths($fixture) as $path) {
            if (is_file($path)) {
                $region = require $path;
                if (! is_array($region)) {
                    $this->fail("Channel source fixture region [{$path}] must return an array.");
                }

                return $region;
            }
        }

        return ['country' => $fixture['country']];
    }

    /**
     * @param  array{label: string, provider: string, country: string, source: string, case: string, html: string, expected: string, provider_pack: string}  $fixture
     * @return array<int, string>
     */
    private function regionPaths(array $fixture): array
    {
        $sourceDir = dirname($fixture['html']);
        $sourcesDir = dirname($sourceDir);
        $providerDir = dirname($sourcesDir);
        $countryDir = dirname($providerDir);

        return [
            $sourceDir.'/'.$fixture['case'].'.region.php',
            $sourceDir.'/region.php',
            $providerDir.'/region.php',
            $countryDir.'/region.php',
        ];
    }

    /**
     * @return array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id: string, guide_valid: bool}>
     */
    private function expectedRows(string $path): array
    {
        $expected = require $path;
        if (! is_array($expected)) {
            $this->fail("Channel source fixture expectation [{$path}] must return an array.");
        }

        return $expected;
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            $this->fail("Channel source fixture [{$path}] could not be read.");
        }

        return $content;
    }
}
