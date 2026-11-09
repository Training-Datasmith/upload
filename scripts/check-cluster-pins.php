<?php

declare(strict_types=1);

/**
 * Verify installed cluster packages match Training-Datasmith composer-index SHAs and root aliases.
 *
 * Usage:
 *   php scripts/check-cluster-pins.php
 *   php scripts/check-cluster-pins.php --demo-wrong-sha
 */

$expected = [
    'codeception/codeception' => [
        'alias' => '5.3.5',
        'reference' => 'a172f822d84b615da3dea2849741ea10781c666b',
    ],
    'codeception/module-asserts' => [
        'alias' => '3.3.0',
        'reference' => 'f5a860690a3066d4bb38eca991b3540f68e88e68',
    ],
    'nikic/php-parser' => [
        'alias' => '5.7.0',
        'reference' => '50f0d9c9d0e3cff1163c959c50aaaaa4a7115f08',
    ],
    'psr/container' => [
        'alias' => '2.0.2',
        'reference' => '57d8cac2ff3db652075e2d6e44e0ede70720e8e5',
    ],
    'psr/event-dispatcher' => [
        'alias' => '1.0.0',
        'reference' => '2b4e85d5a882c9a0312aec7be4e8f9774544f230',
    ],
    'symfony/polyfill-ctype' => [
        'alias' => '1.33.0',
        'reference' => '178d8180c34087830dbcdb5e67fa8b61d28016ac',
    ],
    'symfony/polyfill-mbstring' => [
        'alias' => '1.33.0',
        'reference' => '2cecae254e4ffc2c20ac379c5cd23d91c82109df',
    ],
    'symfony/polyfill-intl-grapheme' => [
        'alias' => '1.33.0',
        'reference' => '137f223b5cb3ba256d8c52adea5e0e8594708e71',
    ],
    'symfony/polyfill-intl-normalizer' => [
        'alias' => '1.33.0',
        'reference' => 'c6839534bfcaf6a8b566e44e5bb971e672a678cd',
    ],
];

/**
 * @param array<string, array{alias: string, reference: string}> $map
 */
function checkClusterPins(array $map, string $installedPhp): int
{
    if (!is_readable($installedPhp)) {
        fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.php not found; run composer update first.\n");
        return 1;
    }

    /** @var array{versions?: array<string, array{reference?: string, aliases?: list<string>, source?: array{reference?: string}}>} $installed */
    $installed = require $installedPhp;
    $versions = $installed['versions'] ?? [];

    $failed = false;
    foreach ($map as $name => $want) {
        if (!isset($versions[$name])) {
            fwrite(STDERR, sprintf("check-cluster-pins: %s is not installed.\n", $name));
            $failed = true;
            continue;
        }

        $pkg = $versions[$name];
        $aliases = $pkg['aliases'] ?? [];
        if (!in_array($want['alias'], $aliases, true)) {
            fwrite(STDERR, sprintf(
                "check-cluster-pins: %s alias mismatch (expected %s in aliases %s).\n",
                $name,
                $want['alias'],
                json_encode($aliases)
            ));
            $failed = true;
        }

        $reference = null;
        if (isset($pkg['source']['reference']) && is_string($pkg['source']['reference']) && $pkg['source']['reference'] !== '') {
            $reference = $pkg['source']['reference'];
        } elseif (isset($pkg['reference']) && is_string($pkg['reference']) && $pkg['reference'] !== '') {
            $reference = $pkg['reference'];
        }

        if (!is_string($reference) || strlen($reference) !== 40) {
            fwrite(STDERR, sprintf("check-cluster-pins: %s has no 40-char reference.\n", $name));
            $failed = true;
            continue;
        }

        $reference = strtolower($reference);
        $wantRef = strtolower($want['reference']);
        if (!hash_equals($wantRef, $reference)) {
            fwrite(STDERR, sprintf(
                "check-cluster-pins: %s reference mismatch (expected %s, got %s).\n",
                $name,
                $wantRef,
                $reference
            ));
            $failed = true;
        }
    }

    if ($failed) {
        return 1;
    }

    fwrite(STDOUT, "check-cluster-pins: all cluster packages match expected SHAs and aliases.\n");

    return 0;
}

$installedPhp = dirname(__DIR__) . '/vendor/composer/installed.php';
$demoWrongSha = in_array('--demo-wrong-sha', $argv ?? [], true);

if ($demoWrongSha) {
    $mutated = $expected;
    $mutated['codeception/codeception']['reference'] = str_repeat('0', 40);

    $demoExit = checkClusterPins($mutated, $installedPhp);
    if ($demoExit === 0) {
        fwrite(STDERR, "check-cluster-pins: --demo-wrong-sha expected a reference mismatch failure.\n");
        exit(1);
    }

    fwrite(STDOUT, "check-cluster-pins: --demo-wrong-sha correctly rejected wrong SHA for codeception/codeception.\n");
    exit(checkClusterPins($expected, $installedPhp));
}

exit(checkClusterPins($expected, $installedPhp));
