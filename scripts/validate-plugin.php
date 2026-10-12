<?php

declare(strict_types=1);

use AppLocalPlugins\GuideLink\Support\PluginContractValidator;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'AppLocalPlugins\\GuideLink\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = $root.'/src/'.str_replace('\\', '/', $relativeClass).'.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$errors = (new PluginContractValidator($root))->validate();
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error."\n");
    }

    exit(1);
}

echo "Validated guidelink plugin files.\n";
