<?php

declare(strict_types=1);

// Shopware-Autoloader laden (stellt Framework-Klassen bereit)
$shopwareAutoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

// Plugin-eigenen Autoloader registrieren (src/ und tests/)
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Ruhrcoder\\RcCartSplitter\\Tests\\' => __DIR__ . '/',
        'Ruhrcoder\\RcCartSplitter\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
