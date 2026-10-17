<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use AppLocalPlugins\GuideLink\Support\SourceSettings;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
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
        $attempts = 0;
        for ($attempt = 1; $attempt <= (int) $config['max_retries']; $attempt++) {
            try {
                RunProgress::checkCancellation($context);
                $attempts = $attempt;
                $content = $scraper->fetch(
                    $url,
                    $format,
                    (string) $config['scraping_api_key'],
                    (int) $config['scraping_timeout_seconds'],
                );
                RunProgress::checkCancellation($context);

                if (trim($content) === '') {
                    throw new \RuntimeException('Scraper returned empty content.');
                }

                return $content;
            } catch (RunCancelled $exception) {
                throw $exception;
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
                if ($exception instanceof RequestException) {
                    $status = $exception->response->status();
                    // A rate limit cannot recover from an immediate burst of retries.
                    // Leave it to a later refresh instead of spending more quota now.
                    if ($status >= 400 && $status < 500 && ! in_array($status, [408, 425], true)) {
                        break;
                    }
                }
            }
        }

        throw new \RuntimeException('Source fetch failed after '.$attempts.' attempt(s): '.$lastError);
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
        $this->validateEntries($entries);
        $path = $this->parsedPath($config);
        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(6));
        $contents = json_encode([
            'metadata' => $metadata,
            'entries' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        $disk = Storage::disk('local');
        try {
            if (! $disk->put($temporaryPath, $contents)) {
                throw new \RuntimeException("Unable to write temporary source cache [{$temporaryPath}].");
            }

            if (! rename($disk->path($temporaryPath), $disk->path($path))) {
                throw new \RuntimeException("Unable to atomically replace source cache [{$path}].");
            }
        } finally {
            // Laravel can turn filesystem warnings into exceptions; clean up
            // partial files on either failure path without masking the cause.
            try {
                if ($disk->exists($temporaryPath)) {
                    $disk->delete($temporaryPath);
                }
            } catch (\Throwable) {
                // Preserve the original write failure if storage is unavailable.
            }
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function isFresh(array $config): bool
    {
        if ((bool) $config['force_refresh'] || (int) $config['source_cache_days'] <= 0) {
            return false;
        }

        try {
            $cache = $this->readLastGood($config);
            $fetchedAt = $cache['metadata']['fetched_at'] ?? null;
            if ($fetchedAt !== null && (! is_string($fetchedAt) || trim($fetchedAt) === '')) {
                return false;
            }
            // Legacy files without fetch metadata retain their original mtime
            // behaviour. Copying a dated cache must not renew its freshness.
            $timestamp = $fetchedAt === null
                ? Storage::disk('local')->lastModified($cache['path'])
                : Carbon::parse($fetchedAt)->getTimestamp();
            $age = now()->getTimestamp() - $timestamp;

            return $age >= 0 && $age < (int) $config['source_cache_days'] * 86400;
        } catch (\Throwable) {
            return false;
        }
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

        $this->validateEntries($data['entries']);
        $minimum = max(1, (int) ($config['min_channels'] ?? 1));
        if (count($data['entries']) < $minimum) {
            throw new \RuntimeException('Source cache contains only '.count($data['entries'])." channel(s); this profile requires a minimum of {$minimum}. Refresh the source before syncing.");
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

    /** Compare complete snapshots, including metadata, across overlapping fetches. */
    public function revision(array $config): ?string
    {
        $disk = Storage::disk('local');
        $path = $this->parsedPath($config);

        return $disk->exists($path) ? hash('sha256', $disk->get($path)) : null;
    }

    /** Refuse incomplete canonical data instead of silently dropping damaged rows. */
    private function validateEntries(array $entries): void
    {
        if ($entries === [] || ! array_is_list($entries)) {
            throw new \RuntimeException('Source cache must contain a non-empty list of canonical channels. Refresh the source before syncing.');
        }
        $numbers = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_int($entry['number'] ?? null) || $entry['number'] <= 0
                || ! is_string($entry['name'] ?? null) || trim($entry['name']) === ''
                || ! is_string($entry['category'] ?? null)
                || ! is_array($entry['aliases'] ?? null)
                || array_filter($entry['aliases'], fn (mixed $alias): bool => ! is_string($alias)) !== []) {
                throw new \RuntimeException('Source cache contains a malformed channel. Refresh the source before syncing.');
            }
            if (isset($numbers[$entry['number']])) {
                throw new \RuntimeException("Source cache contains duplicate channel number [{$entry['number']}]. Refresh the source before syncing.");
            }
            $numbers[$entry['number']] = true;
        }
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
