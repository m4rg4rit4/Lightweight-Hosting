<?php
// Test the real migration helper without loading root services or a database.
$source = file_get_contents(__DIR__ . '/../src/engine/server.php');
$start = strpos($source, 'function normalizeHtaccessOptions(');
$end = strpos($source, 'function migrateHtaccessOptions(', $start);
eval(substr($source, $start, $end - $start));
$cases = [
    ["Options -Indexes +FollowSymLinks\n", "Options -Indexes +SymLinksIfOwnerMatch\n"],
    ["Options FollowSymLinks\r\n", "Options SymLinksIfOwnerMatch\r\n"],
    ["  options +followsymlinks -Indexes\n", "  options +SymLinksIfOwnerMatch -Indexes\n"],
    ["Options -FollowSymLinks +SymLinksIfOwnerMatch\n", "Options -FollowSymLinks +SymLinksIfOwnerMatch\n"],
    ["# Options +FollowSymLinks\nRewriteRule ^live$ target.php [L]\n", "# Options +FollowSymLinks\nRewriteRule ^live$ target.php [L]\n"],
    ["Header set X-Example FollowSymLinks\n", "Header set X-Example FollowSymLinks\n"],
];
foreach ($cases as [$input, $expected]) {
    if (normalizeHtaccessOptions($input) !== $expected) throw new RuntimeException('Unexpected migration result');
    if (normalizeHtaccessOptions($expected) !== $expected) throw new RuntimeException('Migration is not idempotent');
}
echo "Htaccess option migration tests passed\n";
