<?php
$source = file_get_contents(__DIR__ . '/../src/engine/server.php');
$start = strpos($source, 'function siteApplicationRoot(');
$end = strpos($source, 'function normalizeHtaccessOptions(', $start);
eval(substr($source, $start, $end - $start));
foreach ([
    ['/var/www/html', '/var/www/html'],
    ['/var/www/vhosts/example.test', '/var/www/vhosts/example.test'],
    ['/var/www/vhosts/example.test/public', '/var/www/vhosts/example.test'],
    ['/var/www/vhosts/example.test/project/public', '/var/www/vhosts/example.test'],
] as [$documentRoot, $expected]) {
    if (siteApplicationRoot('example.test', $documentRoot) !== $expected) throw new RuntimeException('Wrong application root');
}
foreach (['/var/www', '/var/www/vhosts', '/var/www/vhosts/another.test/public', '/var/www/vhosts/example.test/../another.test', '/var/www/vhosts/example.test//public', "/var/www/vhosts/example.test/public\n"] as $documentRoot) {
    try { siteApplicationRoot('example.test', $documentRoot); }
    catch (RuntimeException $expected) { continue; }
    throw new RuntimeException('Unsafe application root was accepted');
}
echo "Application root containment tests passed\n";
