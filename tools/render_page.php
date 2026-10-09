<?php
// CLI only: the module directory is reachable through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Renders the page body (devices + code card); argv: [lang] [paired 0|1] to HTML with the module's own view,
// for a screenshot outside a logged-in browser.
class CWebUser { public static $data = ['lang' => 'en_US']; public static function get($k) { return self::$data[$k] ?? null; } }
require_once __DIR__.'/boot_common.php';
if (!function_exists('_x')) { function _x($s, $c) { return $s; } }
require_once '/usr/share/zabbix/include/translateDefines.inc.php';
CWebUser::$data['lang'] = $argv[1] ?? 'en_US';
$M = dirname(__DIR__);
require_once "$M/includes/Lang.php";
require_once "$M/includes/Ui.php";
require_once "$M/includes/Pairing.php";
// CHtmlPage::show() prints the whole page frame; only the content is wanted.
class _Page { private array $items = []; private string $title = '';
	public function setTitle($t) { $this->title = $t; return $this; }
	public function setControls($c) { return $this; }
	public function addItem($i) { $this->items[] = $i; return $this; }
	public function show() { echo '<header class="header-title"><h1>'.htmlspecialchars($this->title).'</h1></header><main>';
		foreach ($this->items as $i) echo $i; echo '</main>'; } }
$src = file_get_contents("$M/views/connect.view.php");
$src = str_replace(['(new CHtmlPage())', 'declare(strict_types = 0);'], ['(new _Page())', ''], $src);
$data = ['title' => Modules\ZbxViewConnect\Includes\Lang::t('title', 'Mobile connect'),
	'theme' => 'zvc-theme-light', 'user' => 'Test User', 'server_url' => 'https://zabbix.example.com/zabbix',
	'server_name' => 'Test Zabbix', 'api_access' => true, 'csrf' => 'x',
	'window' => Modules\ZbxViewConnect\Includes\Pairing::PAIR_WINDOW,
	'devices' => ($argv[2] ?? '0') === '1'
		? [['tokenid' => '1', 'name' => 'Work phone', 'created' => 0, 'lastaccess' => 0, 'active' => true,
			'created_text' => '2026-10-08 10:15', 'lastaccess_text' => '2026-10-08 10:16']]
		: []];
eval('?>'.$src);
