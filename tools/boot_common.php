<?php
// CLI only: the module directory is reachable through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Shared bootstrap for the offline checks: Zabbix autoloading plus the few
// globals the frontend normally has set up.
require_once '/usr/share/zabbix/vendor/autoload.php';
require_once '/usr/share/zabbix/include/defines.inc.php';
spl_autoload_register(function ($class) {
    static $map = null;
    if ($map === null) { $map = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/usr/share/zabbix/include/classes')) as $f)
            if ($f->isFile() && $f->getExtension() === 'php') $map[$f->getBasename('.php')] = $f->getPathname(); }
    $short = substr(strrchr($class, '\\') ?: $class, 1) ?: $class;
    if (isset($map[$class])) require_once $map[$class]; elseif (isset($map[$short])) require_once $map[$short];
});
require_once '/usr/share/zabbix/include/func.inc.php';
if (!function_exists('_'))  { function _($s) { return $s; } }
if (!function_exists('_s')) { function _s($s, ...$a) { return vsprintf(preg_replace('/%(\d)\$s/','%$1$s',$s), $a); } }
if (!function_exists('_n')) { function _n($a,$b,$n) { return $n==1?$a:$b; } }

// Lang reads CWebUser for the language; outside a session it must not care.
if (!class_exists('CWebUser')) {
    eval('class CWebUser { public static $data = ["lang" => "en_US"]; public static function get($k) { return self::$data[$k] ?? null; } }');
}


// The Severity field asks CSettingsHelper for severity names and colours, which
// goes to API::Settings(). Offline there is no API, so hand back the defaults.
if (!class_exists('API', false)) {
    eval('
        class _FmxSettingsStub {
            public function get(array $o) {
                $names = ["Not classified","Information","Warning","Average","High","Disaster"];
                $colors = ["97AAB3","7499FF","FFC859","FFA059","E97659","E45959"];
                $out = ["default_theme" => "dark-theme", "search_limit" => "1000", "max_in_table" => "50",
                        "custom_color" => "0", "show_technical_errors" => "0"];
                foreach ($names as $i => $n) { $out["severity_name_$i"] = $n; $out["severity_color_$i"] = $colors[$i]; }
                foreach ($o["output"] as $k) { $out += [$k => ""]; }
                return $out;
            }
        }
        class API { public static function Settings() { return new _FmxSettingsStub(); } }
    ');
}
