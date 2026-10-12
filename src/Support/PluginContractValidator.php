<?php

namespace AppLocalPlugins\GuideLink\Support;

use JsonException;

final class PluginContractValidator
{
    public function __construct(private readonly string $root) {}

    /**
     * @return array<int, string>
     */
    public function validate(): array
    {
        $errors = [];
        $manifest = $this->manifest($errors);
        if ($manifest === null) {
            return $errors;
        }

        $this->validateManifestShell($manifest, $errors);
        array_push($errors, ...(new RegistryContractValidator($this->root))->validate());
        $this->validateDynamicFields($manifest, $errors);
        $this->validateManifestDefaults($manifest, $errors);

        return $errors;
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<string, mixed>|null
     */
    private function manifest(array &$errors): ?array
    {
        $manifestPath = $this->root.'/plugin.json';
        if (! is_file($manifestPath)) {
            $errors[] = 'Missing plugin.json';

            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $errors[] = 'plugin.json must contain valid JSON: '.$exception->getMessage();

            return null;
        }

        if (! is_array($manifest)) {
            $errors[] = 'plugin.json must contain a JSON object.';

            return null;
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $errors
     */
    private function validateManifestShell(array $manifest, array &$errors): void
    {
        foreach (['id', 'name', 'entrypoint', 'class'] as $field) {
            if (! isset($manifest[$field]) || ! is_string($manifest[$field]) || trim($manifest[$field]) === '') {
                $errors[] = "Missing required manifest field: {$field}";
            }
        }

        if (($manifest['id'] ?? null) !== 'guidelink') {
            $errors[] = 'Expected plugin id guidelink, found '.($manifest['id'] ?? 'unknown');
        }

        $entrypoint = ltrim((string) ($manifest['entrypoint'] ?? ''), '/');
        if ($entrypoint === '' || ! is_file($this->root.'/'.$entrypoint)) {
            $errors[] = 'Missing entrypoint file: '.($manifest['entrypoint'] ?? 'unknown');
        }

        foreach (($manifest['actions'] ?? []) as $action) {
            if (! is_array($action) || ($action['dry_run'] ?? false) !== false) {
                continue;
            }

            if (($action['requires_confirmation'] ?? false) !== true) {
                $errors[] = "Write action [{$action['id']}] must require confirmation.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $errors
     */
    private function validateDynamicFields(array $manifest, array &$errors): void
    {
        foreach (DynamicOptionProviders::manifestRequirements() as $requirement) {
            $this->assertDynamicDefinition(
                $manifest,
                $errors,
                $requirement['key'],
                $requirement['value'],
                $requirement['label'],
                $requirement['provider'],
                $requirement['depends_on'] ?? null,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $errors
     * @param  array<int, string>|null  $dependsOn
     */
    private function assertDynamicDefinition(
        array $manifest,
        array &$errors,
        string $key,
        string $value,
        string $label,
        string $provider,
        ?array $dependsOn = null,
    ): void {
        $definition = $this->firstDefinition($manifest, $key, $value);
        if ($definition === null) {
            $errors[] = "plugin.json is missing {$label}.";

            return;
        }

        if (($definition['options_provider'] ?? null) !== $provider) {
            $errors[] = "plugin.json {$label} must use options_provider {$provider}.";
        }

        if ($dependsOn !== null && ($definition['depends_on'] ?? null) !== $dependsOn) {
            $errors[] = 'plugin.json '.$label.' must depend on '.implode(' and ', $dependsOn).'.';
        }

        if ($dependsOn === null && array_key_exists('depends_on', $definition)) {
            $errors[] = "plugin.json {$label} must not declare depends_on.";
        }

        if (array_key_exists('options', $definition)) {
            $errors[] = "plugin.json {$label} must not duplicate static options when using options_provider.";
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $errors
     */
    private function validateManifestDefaults(array $manifest, array &$errors): void
    {
        foreach (ProfileDefaults::manifestDefaults() as $key => $expected) {
            $field = $this->firstDefinition($manifest, 'id', 'settings.'.$key);
            if ($field === null) {
                $errors[] = "plugin.json is missing settings.{$key}.";

                continue;
            }

            if (! array_key_exists('default', $field)) {
                $errors[] = "plugin.json settings.{$key} is missing its runtime default.";

                continue;
            }

            if ($field['default'] !== $expected) {
                $errors[] = "plugin.json settings.{$key} default is out of sync with runtime defaults.";
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstDefinition(mixed $node, string $key, string $value): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if (($node[$key] ?? null) === $value) {
            return $node;
        }

        foreach ($node as $child) {
            $definition = $this->firstDefinition($child, $key, $value);
            if ($definition !== null) {
                return $definition;
            }
        }

        return null;
    }
}
