<?php
/**
 * Minimal OpenNebula XML-RPC client (no php-xmlrpc extension required).
 * License: MIT. Part of the WHMCS <-> OpenNebula CE bridge.
 */
namespace OpenNebulaWhmcs;

class OneClient
{
    private string $endpoint;
    private string $session;
    private int $timeout;

    public function __construct(string $endpoint, string $user, string $password, int $timeout = 30)
    {
        $this->endpoint = $endpoint;
        $this->session  = $user . ':' . $password;
        $this->timeout  = $timeout;
    }

    public static function fromParams(array $p): self
    {
        $host = trim($p['serverhostname'] ?: $p['serverip']);
        if (preg_match('#^https?://#', $host)) {
            $endpoint = $host;
        } else {
            $port = !empty($p['serverport']) ? (int)$p['serverport'] : 2633;
            $scheme = !empty($p['serversecure']) ? 'https' : 'http';
            $endpoint = "$scheme://$host:$port/RPC2";
        }
        return new self($endpoint, $p['serverusername'], $p['serverpassword']);
    }

    /** Call a method; session is prepended automatically. Returns the payload (2nd element) or throws. */
    public function call(string $method, array $args = [])
    {
        array_unshift($args, $this->session);
        $xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($method) . '</methodName><params>';
        foreach ($args as $a) {
            $xml .= '<param>' . self::encode($a) . '</param>';
        }
        $xml .= '</params></methodCall>';

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new \Exception("OpenNebula 连接失败: $e");
        }
        curl_close($ch);

        $doc = @simplexml_load_string($raw);
        if (!$doc) {
            throw new \Exception('OpenNebula 返回无法解析: ' . substr($raw, 0, 200));
        }
        if (isset($doc->fault)) {
            $f = self::decode($doc->fault->value);
            throw new \Exception('XML-RPC fault: ' . ($f['faultString'] ?? 'unknown'));
        }
        $res = self::decode($doc->params->param->value);
        if (!is_array($res) || !$res[0]) {
            throw new \Exception('OpenNebula 错误: ' . (is_array($res) ? (string)$res[1] : 'invalid response'));
        }
        return $res[1];
    }

    private static function encode($v): string
    {
        if (is_bool($v))  return '<value><boolean>' . ($v ? 1 : 0) . '</boolean></value>';
        if (is_int($v))   return '<value><i4>' . $v . '</i4></value>';
        if (is_float($v)) return '<value><double>' . $v . '</double></value>';
        if (is_array($v)) {
            $s = '<value><array><data>';
            foreach ($v as $i) $s .= self::encode($i);
            return $s . '</data></array></value>';
        }
        return '<value><string>' . htmlspecialchars((string)$v, ENT_XML1) . '</string></value>';
    }

    private static function decode(\SimpleXMLElement $v)
    {
        $children = $v->children();
        if (count($children) === 0) return (string)$v;
        $c = $children[0];
        switch ($c->getName()) {
            case 'boolean': return ((string)$c) === '1';
            case 'i4': case 'int': case 'i8': return (int)(string)$c;
            case 'double': return (float)(string)$c;
            case 'array':
                $out = [];
                foreach ($c->data->value as $x) $out[] = self::decode($x);
                return $out;
            case 'struct':
                $out = [];
                foreach ($c->member as $m) $out[(string)$m->name] = self::decode($m->value);
                return $out;
            default: return (string)$c;
        }
    }

    /* ---------- helpers ---------- */
    public function version(): string { return (string)$this->call('one.system.version'); }

    public function vmInfo(int $id): \SimpleXMLElement
    {
        return simplexml_load_string($this->call('one.vm.info', [$id, false]));
    }

    public function instantiate(int $tplId, string $name, string $extra): int
    {
        return (int)$this->call('one.template.instantiate', [$tplId, $name, false, $extra, false]);
    }

    public function action(string $action, int $id): void { $this->call('one.vm.action', [$action, $id]); }

    public function resize(int $id, string $tpl): void { $this->call('one.vm.resize', [$id, $tpl, false]); }

    public function vnetInfo(int $id): \SimpleXMLElement
    {
        return simplexml_load_string($this->call('one.vn.info', [$id, false]));
    }

    public static function stateName(\SimpleXMLElement $vm): string
    {
        $states = ['INIT','PENDING','HOLD','ACTIVE','STOPPED','SUSPENDED','DONE','FAILED','POWEROFF','UNDEPLOYED','CLONING','CLONING_FAILURE'];
        $lcm = [0=>'LCM_INIT',1=>'PROLOG',2=>'BOOT',3=>'RUNNING',4=>'MIGRATE',5=>'SAVE_STOP',6=>'SAVE_SUSPEND',7=>'SAVE_MIGRATE',
            8=>'PROLOG_MIGRATE',9=>'PROLOG_RESUME',10=>'EPILOG_STOP',11=>'EPILOG',12=>'SHUTDOWN',15=>'CLEANUP_RESUBMIT',16=>'UNKNOWN',
            17=>'HOTPLUG',18=>'SHUTDOWN_POWEROFF',19=>'BOOT_UNKNOWN',20=>'BOOT_POWEROFF',21=>'BOOT_SUSPENDED',22=>'BOOT_STOPPED',
            36=>'BOOT_FAILURE',23=>'CLEANUP_DELETE',24=>'HOTPLUG_SNAPSHOT',25=>'HOTPLUG_NIC',26=>'HOTPLUG_SAVEAS'];
        $s = (int)$vm->STATE;
        $name = $states[$s] ?? (string)$s;
        if ($s === 3) $name = $lcm[(int)$vm->LCM_STATE] ?? 'ACTIVE';
        return $name;
    }
}
