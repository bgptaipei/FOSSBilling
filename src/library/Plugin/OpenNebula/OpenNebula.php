<?php
/**
 * FOSSBilling Servicecustom 插件：OpenNebula CE VPS 自动开通（MIT，自研）
 * 产品类型选「Custom」，Plugin 填 OpenNebula，Plugin config 填 JSON 规格，例如：
 *   {"template_id":1,"image_id":1,"vcpu":2,"memory":2048,"disk":20,"network_id":0,"cluster_id":100,"cpu_ratio":0.5,"suspend_action":"poweroff-hard"}
 * OpenNebula 连接信息放在 /etc/fossbilling/opennebula.php（webroot 之外）。
 */
require_once __DIR__ . '/OneClient.php';

use OpenNebulaWhmcs\OneClient;

class OpenNebula
{
    private array $cfg;
    private static ?PDO $pdo = null;

    public function __construct(array $config = [])
    {
        $this->cfg = $config + [
            'template_id' => 1, 'image_id' => 1, 'vcpu' => 1, 'memory' => 1024, 'disk' => 20,
            'network_id' => 0, 'cluster_id' => 100, 'cpu_ratio' => 0.5, 'suspend_action' => 'poweroff-hard',
        ];
    }

    /* ---------------- infrastructure ---------------- */

    private function one(): OneClient
    {
        $c = include '/etc/fossbilling/opennebula.php';
        return new OneClient($c['endpoint'], $c['username'], $c['password']);
    }

    private function db(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $conf = include PATH_ROOT . '/config.php';
        $d = $conf['db'];
        $dsn = 'mysql:host=' . $d['host'] . ';port=' . ($d['port'] ?? 3306) . ';dbname=' . $d['name'] . ';charset=utf8mb4';
        self::$pdo = new PDO($dsn, $d['user'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$pdo->exec('CREATE TABLE IF NOT EXISTS mod_opennebula_vms (
            order_id INT PRIMARY KEY, vmid INT NOT NULL, ip VARCHAR(64) NOT NULL DEFAULT "", hostname VARCHAR(128) NOT NULL DEFAULT "",
            root_password VARCHAR(128) NOT NULL DEFAULT "", created_at DATETIME, updated_at DATETIME) DEFAULT CHARSET=utf8mb4');
        return self::$pdo;
    }

    private function row(int $orderId): ?array
    {
        $s = $this->db()->prepare('SELECT * FROM mod_opennebula_vms WHERE order_id = ?');
        $s->execute([$orderId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function vmid(array $order): int
    {
        $r = $this->row((int)$order['id']);
        if (!$r) throw new \FOSSBilling\InformationException('该订单尚未关联虚拟机');
        return (int)$r['vmid'];
    }

    private static function q(string $v): string { return str_replace(['\\', '"'], ['\\\\', '\\"'], $v); }

    private static function genPassword(int $len = 16): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $s = '';
        for ($i = 0; $i < $len; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        return $s;
    }

    private function extra(array $order, string $pw, string $ip = ''): string
    {
        $c = $this->cfg;
        $vcpu = max(1, (int)$c['vcpu']);
        $cpu = max(0.1, round($vcpu * (float)$c['cpu_ratio'], 2));
        $nic = 'NETWORK_ID="' . (int)$c['network_id'] . '"' . ($ip ? ',IP="' . self::q($ip) . '"' : '');
        $l = [
            'VCPU="' . $vcpu . '"', 'CPU="' . $cpu . '"', 'MEMORY="' . max(256, (int)$c['memory']) . '"',
            'DISK=[IMAGE_ID="' . (int)$c['image_id'] . '",SIZE="' . (max(5, (int)$c['disk']) * 1024) . '"]',
            'NIC=[' . $nic . ']',
            'ROOT_PASSWORD="' . self::q($pw) . '"',
            'FOSSBILLING_ORDER_ID="' . (int)$order['id'] . '"', 'FOSSBILLING_CLIENT_ID="' . (int)$order['client_id'] . '"',
        ];
        if ((int)$c['cluster_id'] > 0) $l[] = 'SCHED_REQUIREMENTS="CLUSTER_ID=' . (int)$c['cluster_id'] . '"';
        return implode("\n", $l);
    }

    private static function firstIp(SimpleXMLElement $vm): string
    {
        foreach ($vm->TEMPLATE->NIC as $n) if ((string)$n->IP !== '') return (string)$n->IP;
        return '';
    }

    private function build(array $service, array $order, string $ip = ''): array
    {
        $one = $this->one();
        $host = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($service['hostname'] ?? ''));
        if ($host === '') $host = 'vps' . (int)$order['id'];
        $pw = (string)($service['root_password'] ?? '');
        if (strlen($pw) < 8) $pw = self::genPassword();
        $vmid = $one->instantiate((int)$this->cfg['template_id'], $host . '-o' . (int)$order['id'], $this->extra($order, $pw, $ip));
        $newIp = '';
        for ($i = 0; $i < 10 && !$newIp; $i++) { $newIp = self::firstIp($one->vmInfo($vmid)); if (!$newIp) sleep(1); }
        $now = date('Y-m-d H:i:s');
        $s = $this->db()->prepare('REPLACE INTO mod_opennebula_vms (order_id, vmid, ip, hostname, root_password, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
        $s->execute([(int)$order['id'], $vmid, $newIp, $host, $pw, $now, $now]);
        return ['vmid' => $vmid, 'ip' => $newIp];
    }

    /** 删除 VM：优先 terminate-hard；若当前状态不允许（如 PROLOG/BOOT），用 recover delete 强制删除 */
    private function destroy(int $vmid): void
    {
        $one = $this->one();
        try { $vm = $one->vmInfo($vmid); } catch (\Exception $e) { return; } // 已不存在
        if ((int)$vm->STATE === 6) return; // DONE
        try { $one->action('terminate-hard', $vmid); return; } catch (\Exception $e) {}
        $one->call('one.vm.recover', [$vmid, 3]);
    }

    private function act(array $order, string $action): bool
    {
        $this->one()->action($action, $this->vmid($order));
        return true;
    }

    private function wait(int $id, array $want, int $timeout): void
    {
        $t = time();
        while (time() - $t < $timeout) {
            try { $s = OneClient::stateName($this->one()->vmInfo($id)); } catch (\Exception $e) { return; }
            if (in_array($s, $want, true)) return;
            sleep(3);
        }
    }

    /* ---------------- lifecycle (called by FOSSBilling) ---------------- */

    public function activate($service, $order, $params = [])
    {
        if ($this->row((int)$order['id'])) return true;
        $this->build($service, $order);
        return true;
    }

    public function renew($service, $order, $params = []) { return true; }
    public function suspend($service, $order, $params = []) { return $this->act($order, $this->cfg['suspend_action'] ?: 'poweroff-hard'); }
    public function unsuspend($service, $order, $params = []) { return $this->act($order, 'resume'); }
    public function cancel($service, $order, $params = []) { return $this->act($order, 'poweroff-hard'); }
    public function uncancel($service, $order, $params = []) { return $this->act($order, 'resume'); }

    public function delete($service, $order, $params = [])
    {
        $r = $this->row((int)$order['id']);
        if (!$r) return true;
        $this->destroy((int)$r['vmid']);
        $this->db()->prepare('DELETE FROM mod_opennebula_vms WHERE order_id = ?')->execute([(int)$order['id']]);
        return true;
    }

    /* ---------------- custom calls (client: client/servicecustom/call, admin: admin/servicecustom/<method>) ---------------- */

    public function info($service, $order, $params = [])
    {
        $r = $this->row((int)$order['id']);
        if (!$r) return ['provisioned' => false];
        $out = ['provisioned' => true, 'vmid' => (int)$r['vmid'], 'ip' => $r['ip'], 'hostname' => $r['hostname'],
                'username' => 'root', 'password' => $r['root_password'], 'ssh_port' => 22];
        try {
            $vm = $this->one()->vmInfo((int)$r['vmid']);
            $out['state'] = OneClient::stateName($vm);
            $out['vcpu'] = (string)$vm->TEMPLATE->VCPU;
            $out['memory'] = (string)$vm->TEMPLATE->MEMORY;
            $out['disk'] = (int)round(((int)$vm->TEMPLATE->DISK[0]->SIZE) / 1024);
            $out['ip'] = self::firstIp($vm) ?: $r['ip'];
        } catch (\Exception $e) {
            $out['state'] = 'ERROR: ' . $e->getMessage();
        }
        return $out;
    }

    public function start($s, $o, $p = [])      { return $this->act($o, 'resume'); }
    public function stop($s, $o, $p = [])       { return $this->act($o, 'poweroff'); }
    public function poweroff($s, $o, $p = [])   { return $this->act($o, 'poweroff-hard'); }
    public function reboot($s, $o, $p = [])     { return $this->act($o, 'reboot'); }
    public function hardreboot($s, $o, $p = []) { return $this->act($o, 'reboot-hard'); }

    public function reinstall($service, $order, $params = [])
    {
        $r = $this->row((int)$order['id']);
        if (!$r) throw new \FOSSBilling\InformationException('该订单尚未关联虚拟机');
        $one = $this->one();
        $ip = $r['ip'];
        try { $ip = self::firstIp($one->vmInfo((int)$r['vmid'])) ?: $ip; } catch (\Exception $e) {}
        $this->destroy((int)$r['vmid']);
        $this->wait((int)$r['vmid'], ['DONE'], 120);
        sleep(2);
        $service['hostname'] = $r['hostname'];
        $service['root_password'] = '';  // 重装生成新密码
        $this->build($service, $order, $ip);
        return true;
    }
}
