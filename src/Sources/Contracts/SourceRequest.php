<?php

namespace AppLocalPlugins\GuideLink\Sources\Contracts;

use AppLocalPlugins\GuideLink\Sources\SourceRegistry;
use AppLocalPlugins\GuideLink\Support\SourceSettings;

final readonly class SourceRequest
{
    public function __construct(
        public string $provider,
        public string $country,
        public string $source,
        public string $region,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $source = SourceSettings::identity($config);

        return new self(
            $source['provider'],
            $source['country'],
            $source['provider_source'],
            $source['region'],
        );
    }

    public function assertSupported(): void
    {
        if (! SourceRegistry::supportsProvider($this->provider)) {
            throw new \RuntimeException("Unsupported source provider [{$this->provider}].");
        }

        if (! SourceRegistry::supportsCountry($this->provider, $this->country, $this->source)) {
            throw new \RuntimeException("Unsupported source combination [{$this->provider}/{$this->country}/{$this->source}].");
        }
    }
}
