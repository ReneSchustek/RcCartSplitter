<?php

declare(strict_types=1);

// Liegt das Plugin unter custom/plugins einer Installation, kommt der Kern aus deren vendor/.
// Im eigenständigen Checkout fehlt die Datei; dort lädt `vendor/bin/phpunit` den Autoloader des Plugins selbst.
$shopwareAutoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

// Eigener PSR-4-Lader für src/ und tests/: Der Autoloader der Installation kennt das Plugin nur,
// wenn es per Composer eingebunden ist.
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

/*
 * Shopware-Root suchen. Der Kernel-Bootstrap läuft nur, wenn das Plugin tatsächlich innerhalb
 * einer Shopware-Installation getestet wird.
 *
 * `class_exists(TestBootstrapper::class)` allein taugt nicht als Kennzeichen: `shopware/core` ist
 * eine `require`-Abhängigkeit, die Klasse existiert also auch im eigenständigen Checkout. Der
 * Bootstrap versuchte dort einen Shop zu booten, den es nicht gibt, und bräche mit
 * „Could not find plugin: RcCartSplitter" ab, noch bevor ein Unit-Test läuft.
 *
 * Kandidaten in dieser Reihenfolge:
 *   1. Aufruf-Verzeichnis — Konvention: Integration-Tests werden aus dem Shopware-Root gestartet
 *      (`vendor/bin/phpunit -c custom/plugins/…`).
 *   2. Vier Ebenen über `tests/` — greift bei `custom/plugins/<Plugin>/tests/`, solange der Pfad
 *      kein Symlink ist.
 */
$shopwareRoot = null;
foreach ([getcwd(), \dirname(__DIR__, 4)] as $candidate) {
    // `getcwd()` liefert im Fehlerfall false; die Typprüfung fängt das ab.
    if (\is_string($candidate) && is_file($candidate . '/config/bundles.php')) {
        $shopwareRoot = $candidate;
        break;
    }
}

// Kernel-Lifecycle vorbereiten. IntegrationTestBehaviour erwartet, dass
// `KernelLifecycleManager::prepare($classLoader)` gelaufen ist, bevor der erste Test startet.
// Ohne Installation entfällt der Schritt; die Unit-Tests brauchen nur den Autoloader.
if ($shopwareRoot !== null && class_exists(\Shopware\Core\TestBootstrapper::class)) {
    // Die DDEV-Installationen tragen in `.env.test` `KERNEL_CLASS=App\Kernel`. Diese Klasse gibt es
    // dort nicht, weil die Installation `KernelFactory::create()` ohne App-Kernel nutzt.
    // `Shopware\Core\Kernel` ist die konkrete Standardklasse. Sie wird vor Dotenv gesetzt, weil
    // Dotenv (override=false) einen vorhandenen Wert nicht überschreibt.
    $kernelClassFromEnv = getenv('KERNEL_CLASS');
    $currentKernelClass = $kernelClassFromEnv !== false && $kernelClassFromEnv !== ''
        ? $kernelClassFromEnv
        : ($_SERVER['KERNEL_CLASS'] ?? '');
    if ($currentKernelClass === '' || !class_exists($currentKernelClass)) {
        putenv('KERNEL_CLASS=Shopware\\Core\\Kernel');
        $_SERVER['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
        $_ENV['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
    }

    // `addCallingPlugin()` registriert RcCartSplitter im Test-Kernel. Kein
    // `setForceInstallPlugins(true)`: Das löst bei jedem Lauf einen Zyklus aus Deinstallation und
    // Installation aus, der nicht idempotent ist. Installiert und aktiviert sein muss das Plugin in
    // der Testdatenbank deshalb schon vor dem Lauf.
    $bootstrapper = (new \Shopware\Core\TestBootstrapper())
        ->setPlatformEmbedded(false)
        ->addCallingPlugin();

    // ProjectDir ausdrücklich auf das gefundene Shopware-Root setzen.
    // `KernelFactory::getProjectDir()` liest `$_SERVER['PROJECT_ROOT']` vor dem Reflection-Rückfall.
    // Der Rückfall nähme den Pfad der KernelFactory-Klasse selbst und zeigte bei einem vendor/ im
    // Plugin auf das Plugin statt auf die Installation.
    $bootstrapper->setProjectDir($shopwareRoot);
    $_SERVER['PROJECT_ROOT'] = $shopwareRoot;
    $_ENV['PROJECT_ROOT'] = $shopwareRoot;
    putenv('PROJECT_ROOT=' . $shopwareRoot);

    $bootstrapper->bootstrap();
}
