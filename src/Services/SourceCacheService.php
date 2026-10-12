<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient;
use AppLocalPlugins\GuideLink\Support\SourceSettings;
use Illuminate\Support\Facades\Storage;
use JsonException;

class SourceCacheService
{
    private const CACHE_ROOT = 'plugin-data/guidelink/source-cache';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function config(array $payload, array $settings): array
    {
        return SourceSettings::runtimeCache($payload, $settings);
    }

    /**
     * Fetch source content into memory. Raw scraper responses are never persisted.
     *
     * @param  array<string, mixed>  $config
     */
    public function fetchFresh(
        ScraperClient $scraper,
        PluginExecutionContext $context,
        array $config,
        string $url,
        string $format,
    ): string {
        $lastError = '';
        for ($attempt = 1; $attempt <= (int) $config['max_retries']; $attempt++) {
            try {
                $content = $scraper->fetch(
                    $url,
                    $format,
                    (string) $config['scraping_api_key'],
                    (int) $config['scraping_timeout_seconds'],
                );

                if (trim($content) === '') {
                    throw new \RuntimeException('Scraper returned empty content.');
                }

                return $content;
            } catch (\Throwable $exception) {
                // HTTP errors can contain the request URL or an upstream response
                // echoing credentials. Never retain the original exception chain.
                $key = (string) $config['scraping_api_key'];
                $lastError = $key === '' ? $exception->getMessage() : str_replace(
                    array_unique([$key, rawurlencode($key), urlencode($key)]),
                    '[redacted]',
                    $exception->getMessage(),
                );
                $context->warning('Source scraper attempt failed.', [
                    'attempt' => $attempt,
                    'scraper' => $config['scraping_api'],
                    'url' => $url,
                    'error' => $lastError,
                ]);
            }
        }

        throw new \RuntimeException('Source fetch failed after '.(int) $config['max_retries'].' attempt(s): '.$lastError);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $metadata
     *
     * @throws JsonException
     */
    public function writeLastGood(array $config, array $entries, array $metadata): string
    {
        $path = $this->parsedPath($config);
        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(6));
        $contents = json_encode([
            'metadata' => $metadata,
            'entries' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        $disk = Storage::disk('local');
        if (! $disk->put($temporaryPath, $contents)) {
            throw new \RuntimeException("Unable to write temporary source cache [{$temporaryPath}].");
        }

        $temporaryAbsolutePath = $disk->path($temporaryPath);
        $absolutePath = $disk->path($path);
        if (! rename($temporaryAbsolutePath, $absolutePath)) {
            $disk->delete($temporaryPath);

            throw new \RuntimeException("Unable to atomically replace source cache [{$path}].");
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function isFresh(array $config): bool
    {
        return ! (bool) $config['force_refresh']
            && $this->fresh($this->parsedPath($config), (int) $config['source_cache_days']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{metadata: array<string, mixed>, entries: array<int, array<string, mixed>>, path: string}
     *
     * @throws JsonException
     */
    public function readLastGood(array $config): array
    {
        $path = $this->parsedPath($config);
        if (! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException("No last-good source cache exists for [{$config['provider']}/{$config['provider_source']}] region [{$config['region']}].");
        }

        $data = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['entries'] ?? null)) {
            throw new \RuntimeException("Source cache [{$path}] is not in the expected format.");
        }

        return [
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            'entries' => $data['entries'],
            'path' => $path,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function previousCount(array $config): int
    {
        try {
            return count($this->readLastGood($config)['entries']);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function parsedPath(array $config): string
    {
        return self::CACHE_ROOT.'/parsed/'.$this->sourceKey($config).'.json';
    }

    private function fresh(string $path, int $cacheDays): bool
    {
        if (! Storage::disk('local')->exists($path)) {
            return false;
        }

        if ($cacheDays === 0) {
            return false;
        }

        $lastModified = Storage::disk('local')->lastModified($path);

        return (time() - $lastModified) / 86400 < $cacheDays;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceKey(array $config): string
    {
        $parts = [
            $config['provider'] ?? 'source',
            $config['country'] ?? 'country',
            $config['provider_source'] ?? 'default',
            'region',
            $config['region'] ?? '1',
        ];
        $value = implode('_', $parts);
        $key = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($value)) ?: 'source';

        // Keep simple existing paths; disambiguate names lost during slugging
        // and components containing the underscore used between fields.
        return preg_match('/^[a-z0-9-]+$/i', implode('', $parts)) === 1 ? $key : $key.'_'.substr(hash('sha256', json_encode(
            array_map(fn (string $part): string => mb_strtolower($part, 'UTF-8'), $parts),
            JSON_THROW_ON_ERROR,
        )), 0, 16);
    }
}
