<?php
require __DIR__ . '/../src/admin/dns_utils.php';

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function getPDO() { return $GLOBALS['db']; }

check(getHostingDnsTargetIp('192.0.2.20', []) === '192.0.2.20', 'Explicit IPv4 must be retained');
check(getHostingDnsTargetIp('2001:db8::20', []) === '2001:db8::20', 'Explicit IPv6 must be retained');
check(getHostingDnsTargetIp('', ['127.0.0.1','10.0.0.1','8.8.8.8']) === '8.8.8.8', 'Automatic target must skip private/loopback addresses');
try { getHostingDnsTargetIp('not-an-IP', []); throw new LogicException('Invalid IP accepted'); } catch (InvalidArgumentException $expected) {}
try { getHostingDnsTargetIp('', []); throw new LogicException('Missing IP silently accepted'); } catch (RuntimeException $expected) {}

// Small database double: exercise the real worker and SQL ordering rules without
// touching a production database or contacting real DNS servers.
class QueueResult {
    private $value;
    function __construct($value) { $this->value = $value; }
    function fetchColumn() { return 1; }
    function fetchAll($mode = null) { return ['job_key', 'status', 'completed_at']; }
    function fetch($mode = null) { return $this->value; }
}
class QueueStatement {
    private $db;
    private $sql;
    function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
    function execute($args) {
        if (strpos($this->sql, 'INSERT INTO sys_dns_outbox') === 0) {
            $id = count($this->db->rows) + 1;
            $this->db->rows[$id] = ['id' => $id, 'dns_server_id' => $args[0], 'dns_server_name' => $args[1], 'endpoint' => $args[2], 'payload' => $args[3], 'job_key' => $args[4], 'status' => 'pending', 'next_retry_at' => 0, 'attempts' => 0];
        } elseif (strpos($this->sql, "SET status = 'completed'") !== false) {
            $this->db->rows[$args[2]]['status'] = 'completed';
        } elseif (strpos($this->sql, 'SET status = ?, attempts') !== false) {
            $id = $args[6];
            $this->db->rows[$id]['status'] = $args[0];
            $this->db->rows[$id]['attempts'] = $args[1];
            $this->db->rows[$id]['last_error'] = $args[3];
            $this->db->rows[$id]['next_retry_at'] = strtotime($args[4]);
        } else { throw new RuntimeException('Unexpected statement: ' . $this->sql); }
    }
}
class QueueDB {
    public $rows = [];
    private $saved;
    function beginTransaction() { $this->saved = $this->rows; }
    function commit() { $this->saved = null; }
    function inTransaction() { return $this->saved !== null; }
    function rollBack() { $this->rows = $this->saved; $this->saved = null; }
    function exec($sql) {}
    function prepare($sql) { return new QueueStatement($this, $sql); }
    function query($sql) {
        if (strpos($sql, 'SELECT q.*') === false) return new QueueResult(null);
        foreach ($this->rows as $id => $row) {
            if (in_array($row['status'], ['completed', 'conflict'], true) || $row['next_retry_at'] > time()) continue;
            foreach ($this->rows as $older) {
                if ($older['id'] < $id && $older['dns_server_id'] === $row['dns_server_id'] && !in_array($older['status'], ['completed', 'conflict'], true)) continue 2;
            }
            return new QueueResult($row);
        }
        return new QueueResult(false);
    }
}
$db = new QueueDB();
foreach ([1 => 1, 2 => 2, 3 => 1] as $id => $node) {
    $db->rows[$id] = ['id' => $id, 'dns_server_id' => $node, 'status' => 'pending', 'next_retry_at' => 0,
        'job_key' => str_repeat((string)$id, 64), 'payload' => '{"domain":"example.com"}',
        'endpoint' => '/api-dns/record/add', 'attempts' => 0];
}
$servers = [['id' => 1, 'name' => 'DNS1', 'url' => 'dns1', 'token' => 'a'], ['id' => 2, 'name' => 'DNS2', 'url' => 'dns2', 'token' => 'b']];
$calls = [];
$send = function($url, $endpoint, $method, $data) use (&$calls) {
    $calls[] = [$url, $data['key']];
    return $url === 'dns1' ? ['code' => 0, 'response' => ''] : ['code' => 200, 'response' => '{"status":"completed"}'];
};
dnsOutboxProcess($db, 50, $send, $servers);
check(count($calls) === 2, 'One failed node must not block the other or allow its next job through');
check($db->rows[2]['status'] === 'completed', 'Healthy node must complete');
check($db->rows[1]['status'] === 'error' && $db->rows[3]['status'] === 'pending', 'Failed node must retain FIFO');
$db->rows[1]['next_retry_at'] = 0;
$send = function($url, $endpoint, $method, $data) use (&$calls) {
    $calls[] = [$url, $data['key']];
    return ['code' => 202, 'response' => '{"success":true,"status":"processing"}'];
};
dnsOutboxProcess($db, 50, $send, $servers);
check($calls[2][1] === $calls[0][1], 'Retry must reuse the same job key');
check($db->rows[1]['status'] === 'publishing', 'Acceptance must not be marked completed');
check($db->rows[3]['status'] === 'pending', 'Publication must finish before the next mutation');
$db->rows[1]['next_retry_at'] = 0;
$db->rows[1]['attempts'] = 99;
dnsOutboxProcess($db, 1, function() { return ['code' => 503, 'response' => '']; }, $servers);
check(isset($db->rows[1]) && $db->rows[1]['attempts'] === 100, 'Repeated failures must never discard a job');
$db->rows[1]['next_retry_at'] = 0;
dnsOutboxProcess($db, 50, function() { return ['code' => 200, 'response' => '{"status":"completed"}']; }, $servers);
check($db->rows[1]['status'] === 'completed' && $db->rows[3]['status'] === 'completed', 'Recovery must drain jobs in order');

$db = new QueueDB();
$db->rows[1] = ['id' => 1, 'dns_server_id' => 1, 'endpoint' => '/api-dns/zone/assignment/decide', 'payload' => '{"domain":"example.com","server":"host-a","expected_revision":0}', 'job_key' => str_repeat('c',64), 'status' => 'pending', 'next_retry_at' => 0, 'attempts' => 0];
$replicas = [];
dnsOutboxProcess($db, 50, function($url,$endpoint,$method,$data) use (&$replicas) {
    if ($data['endpoint'] === '/api-dns/zone/assignment/decide') return ['code' => 200, 'response' => '{"status":"completed","result":{"domain":"example.com","server":"host-a","revision":1}}'];
    $replicas[] = $data['payload'];
    return ['code' => 200, 'response' => '{"status":"completed"}'];
}, $servers);
check(count($replicas) === 1 && $replicas[0]['revision'] === 1 && $db->rows[2]['dns_server_id'] === 2, 'Only the accepted primary decision must be replicated to the secondary');
$db = new QueueDB();
foreach ([1,2] as $id) $db->rows[$id] = ['id' => $id, 'dns_server_id' => 1, 'endpoint' => $id === 1 ? '/api-dns/zone/assignment/decide' : '/api-dns/record/add', 'payload' => '{}', 'job_key' => str_repeat((string)$id,64), 'status' => 'pending', 'next_retry_at' => 0, 'attempts' => 0];
dnsOutboxProcess($db, 50, function($url,$endpoint,$method,$data) {
    return $data['endpoint'] === '/api-dns/zone/assignment/decide' ? ['code' => 409, 'response' => '{"message":"Assignment changed"}'] : ['code' => 200, 'response' => '{"status":"completed"}'];
}, $servers);
check(count($db->rows) === 2 && $db->rows[1]['status'] === 'conflict' && $db->rows[2]['status'] === 'completed', 'A rejected claim must not replicate or block later work');

class EnqueueDB extends QueueDB {
    public $failSecond = false;
    private $snapshot;
    function beginTransaction() { $this->snapshot = $this->rows; }
    function commit() { $this->snapshot = null; }
    function inTransaction() { return $this->snapshot !== null; }
    function rollBack() { $this->rows = $this->snapshot; $this->snapshot = null; }
    function lastInsertId() { return count($this->rows); }
    function prepare($sql) { return new EnqueueStatement($this, $sql); }
}
class EnqueueStatement {
    private $db;
    private $sql;
    private $id;
    function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
    function execute($args) {
        if (strpos($this->sql, 'INSERT INTO') === 0) {
            if ($this->db->failSecond && count($this->db->rows) === 1) throw new RuntimeException('Simulated insert failure');
            $id = count($this->db->rows) + 1;
            $this->db->rows[$id] = ['task_id' => $id, 'id' => $args[0], 'name' => $args[1], 'status' => 'pending', 'job_key' => $args[4]];
        } else { $this->id = $args[0]; }
    }
    function fetch($mode = null) { return $this->db->rows[$this->id]; }
}
$db = new EnqueueDB();
$result = dnsApiRequest('/api-dns/record/add', 'POST', ['domain' => 'example.com'], $servers);
check($result['code'] === 200 && count($db->rows) === 2, 'Saving must persist one task for every destination');
check($db->rows[1]['job_key'] !== $db->rows[2]['job_key'], 'Every destination needs an independent receipt key');
$db = new EnqueueDB(); $db->failSecond = true;
$result = dnsApiRequest('/api-dns/record/add', 'POST', ['domain' => 'example.com'], $servers);
check($result['code'] === 503 && !$db->rows, 'A failed batch must roll back all destinations before any send');
echo "DNS outbox tests passed\n";
