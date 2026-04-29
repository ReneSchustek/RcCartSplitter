<?php

declare(strict_types=1);

// Liest den Clover-XML-Report aus dem PHPUnit-Coverage-Lauf und prueft pro
// Verzeichnis-Praefix eine aggregierte Mindest-Line-Coverage. Aggregat statt
// Per-File: einzelne Helfer-Klassen mit niedriger Quote duerfen den Schnitt
// nicht reissen, wenn der Rest gut getestet ist.

$coveragePath = dirname(__DIR__) . '/coverage.xml';

$thresholds = [
    'src/Service/'    => 80.0,
    'src/Subscriber/' => 60.0,
];

if (!is_file($coveragePath)) {
    fwrite(STDERR, "Kein Coverage-Report unter {$coveragePath}; erst 'composer coverage' laufen lassen.\n");
    exit(1);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($coveragePath, \SimpleXMLElement::class, \LIBXML_NONET);
if ($xml === false) {
    fwrite(STDERR, "Coverage-Report nicht parsbar: {$coveragePath}\n");
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, '  ' . trim($error->message) . "\n");
    }
    exit(1);
}

/** @var array<string, array{statements: int, covered: int}> $aggregate */
$aggregate = [];
foreach ($thresholds as $prefix => $_threshold) {
    $aggregate[$prefix] = ['statements' => 0, 'covered' => 0];
}

foreach ($xml->xpath('//file') ?: [] as $file) {
    $path = str_replace('\\', '/', (string) $file['name']);
    $metrics = $file->metrics;
    if ($metrics === null) {
        continue;
    }
    $statements = (int) $metrics['statements'];
    $covered = (int) $metrics['coveredstatements'];

    foreach ($thresholds as $prefix => $_threshold) {
        if (str_contains($path, '/' . $prefix)) {
            $aggregate[$prefix]['statements'] += $statements;
            $aggregate[$prefix]['covered'] += $covered;
        }
    }
}

$violations = [];
foreach ($thresholds as $prefix => $threshold) {
    $statements = $aggregate[$prefix]['statements'];
    $covered = $aggregate[$prefix]['covered'];
    // 0 statements: nichts zu pruefen — als 100% behandeln, kein Verstoss
    $pct = $statements === 0 ? 100.0 : ($covered / $statements) * 100.0;

    $label = trim($prefix, '/');
    $line = sprintf('%s — %.2f%% Line-Coverage (Schwelle %.0f%%)', $label, $pct, $threshold);

    if ($pct + 1e-9 < $threshold) {
        $violations[] = $line;
        fwrite(STDERR, "Coverage-Gate Verstoss: {$line}\n");
    } else {
        fwrite(STDOUT, $line . "\n");
    }
}

exit($violations === [] ? 0 : 1);
