<?php
// Execute the real backup dispatcher with fake external services and database.
namespace BackupRegression;
$source = file_get_contents(__DIR__ . '/../src/engine/server.php');
$start = strpos($source, "        case 'SITE_BACKUP':");
$end = strpos($source, "        case 'SITE_RESTORE':", $start);
$branch = substr($source, $start, $end - $start);
function shell_exec($cmd) { return ''; }
function exec($cmd, &$output = null, &$code = null) {
    global $scenario, $uploads;
    $output = [];
    $code = 0;
    if (strpos($cmd, 'mega-put') !== false) { $uploads++; $code = $scenario === 'upload_failure' ? 1 : 0; }
    if (strpos($cmd, 'mega-rm') !== false) $code = 1;
}
function checkedCommand($cmd) {
    global $scenario;
    if ($scenario === 'archive_failure') throw new \RuntimeException('simulated archive failure');
}
function siteApplicationRoot($domain, $path) { return $path; }
function copyApplicationTree($source, $destination) { checkedCommand('copy application'); }
function privateWorkdir() {
    global $workdirs;
    $temporary = __DIR__ . '/../temp';
    if (!is_dir($temporary)) mkdir($temporary, 0700);
    $path = $temporary . '/lwh-backup-test-' . bin2hex(random_bytes(8));
    mkdir($path, 0700);
    $workdirs[] = $path;
    return $path;
}
class Statement {
    private $sql;
    function __construct($sql) { $this->sql = $sql; }
    function execute($args = []) {
        global $deletes;
        if (strpos($this->sql, 'DELETE FROM sys_backups') !== false) $deletes++;
    }
    function fetch() { return ['domain'=>'example.test','document_root'=>'/unused']; }
    function fetchAll() {
        if (strpos($this->sql, 'sys_databases') !== false) return [];
        return [['id'=>1,'mega_path'=>'/Backups/example.test','filename'=>'old.tar.gz']];
    }
    function fetchColumn() { return 7; }
}
class Database {
    function prepare($sql) { return new Statement($sql); }
    function query($sql) {
        global $retentionQueries;
        if (strpos($sql, 'backup_retention_days') !== false) $retentionQueries++;
        return new Statement($sql);
    }
}
foreach (['upload_failure', 'archive_failure', 'retention_delete_failure'] as $scenario) {
    $uploads = $deletes = $retentionQueries = 0;
    $workdirs = [];
    $pdo = new Database;
    $payload = ['site_id'=>1];
    $success = false;
    try {
        eval("namespace BackupRegression; switch ('SITE_BACKUP') {" . $branch . '}');
    } catch (\RuntimeException $e) {
        if ($scenario !== 'archive_failure') throw $e;
    } finally {
        foreach ($workdirs as $dir) {
            foreach (glob($dir . '/*') as $file) if (is_file($file)) unlink($file);
            @rmdir($dir);
        }
    }
    if ($deletes !== 0) throw new \RuntimeException('Failed remote deletion lost backup metadata');
    if (basename($tmpTar) !== $backupFile || dirname($tmpTar) !== $tmpDir) throw new \RuntimeException('Backup transport name or private directory differs from metadata');
    if ($scenario !== 'retention_delete_failure' && $retentionQueries !== 0) throw new \RuntimeException('Failed backup triggered retention');
    if ($scenario === 'archive_failure' && $uploads !== 0) throw new \RuntimeException('Broken archive was uploaded');
}
echo "Backup failure tests passed\n";
