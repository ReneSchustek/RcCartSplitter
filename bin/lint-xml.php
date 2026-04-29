<?php

declare(strict_types=1);

// Well-formed-Check fuer alle Plugin-XML-Dateien. Validiert nicht gegen XSDs
// (Schema-Lookup ueber URLs ist im CI nicht garantiert verfuegbar) — der volle
// Schema-Check laeuft via `bin/console lint:xml` auf einer gebooteten Plattform.

$root = __DIR__ . '/../src';
if (!is_dir($root)) {
    fwrite(STDERR, "Verzeichnis nicht gefunden: {$root}\n");
    exit(1);
}

$errors = 0;
$count = 0;

$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
);

libxml_use_internal_errors(true);

foreach ($iterator as $file) {
    if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'xml') {
        continue;
    }

    $count++;
    $path = $file->getRealPath();

    libxml_clear_errors();
    $doc = new \DOMDocument();
    if ($doc->load($path, \LIBXML_NONET) === false) {
        $errors++;
        fwrite(STDERR, "XML nicht wohlgeformt: {$path}\n");
        foreach (libxml_get_errors() as $error) {
            fwrite(STDERR, sprintf("  Zeile %d: %s", $error->line, trim($error->message)) . "\n");
        }
        libxml_clear_errors();
    }
}

if ($errors > 0) {
    fwrite(STDERR, sprintf("XML-Lint: %d Datei(en) mit Fehlern (von %d geprueft).\n", $errors, $count));
    exit(1);
}

fwrite(STDOUT, sprintf("XML-Lint: %d Datei(en) ok.\n", $count));
exit(0);
