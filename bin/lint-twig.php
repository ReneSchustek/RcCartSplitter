<?php

declare(strict_types=1);

// Standalone-Twig-Lint — ohne Shopware-Boot. Faengt Syntax-Fehler (Klammern,
// Quotes, unclosed-Tags) ueber den Twig-Lexer. Tag-/Filter-Existenz (sw_extends,
// sw_sanitize) wird hier NICHT geprueft — das macht `bin/console lint:twig` auf
// einer gebooteten Plattform-Instanz.

require __DIR__ . '/../vendor/autoload.php';

$root = __DIR__ . '/../src/Resources/views';
if (!is_dir($root)) {
    fwrite(STDERR, "Verzeichnis nicht gefunden: {$root}\n");
    exit(1);
}

$loader = new \Twig\Loader\FilesystemLoader($root);
$environment = new \Twig\Environment($loader);
$lexer = new \Twig\Lexer($environment);

$errors = 0;
$count = 0;

$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'twig') {
        continue;
    }

    $count++;
    $path = $file->getRealPath();
    $source = (string) file_get_contents($path);

    try {
        $lexer->tokenize(new \Twig\Source($source, $file->getFilename(), $path));
    } catch (\Twig\Error\SyntaxError $e) {
        $errors++;
        fwrite(STDERR, sprintf("Twig-Syntaxfehler in %s (Zeile %d): %s\n", $path, $e->getTemplateLine(), $e->getMessage()));
    }
}

if ($errors > 0) {
    fwrite(STDERR, sprintf("Twig-Lint: %d Datei(en) mit Syntaxfehlern (von %d geprueft).\n", $errors, $count));
    exit(1);
}

fwrite(STDOUT, sprintf("Twig-Lint: %d Datei(en) ok.\n", $count));
exit(0);
