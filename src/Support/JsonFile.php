<?php

namespace AppLocalPlugins\GuideLink\Support;

use JsonException;

final class JsonFile
{
    /**
     * @return array<string, mixed>
     */
    public static function read(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("{$label} [{$path}] was not found.");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("{$label} [{$path}] could not be read.");
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException("{$label} [{$path}] contains invalid JSON: {$exception->getMessage()}");
        }

        if (! is_array($data)) {
            throw new \RuntimeException("{$label} [{$path}] must be a JSON object.");
        }

        return $data;
    }
}
