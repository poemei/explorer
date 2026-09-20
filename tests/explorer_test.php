<?php
declare(strict_types=1);

// Core/SQL doubles exercise module behavior without changing a deployed database.
define('DB_NAME', 'explorer_test');
class model
{
    public array $tables = [];
    public ?string $version = null;
    public array $settings = [];
    public array $writes = [];
    public function fetch(string $sql, array $params = [])
    {
        if (isset($params['table_name'])) { return in_array($params['table_name'], $this->tables, true); }
        if (str_contains($sql, 'schema_version')) { return ['schema_version' => $this->version]; }
        return $this->settings;
    }
    public function query($sql, $params = [])
    {
        $this->writes[] = [$sql, $params];
        if (str_contains($sql, 'INSERT INTO `explorer_settings`')) { $this->settings = $params; }
    }
}
class theme { public static function render($name, $data): bool { return true; } }
require_once dirname(__DIR__) . '/models/explorer_model.php';
require_once dirname(__DIR__) . '/libraries/explorer_status.php';
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
    ++$checks;
    if (!$ok) { throw new RuntimeException($name); }
};
$model = new explorer_model();
$check($model->databaseState() === 'missing', 'Missing schema');
$check($model->chainStatus('missing')['state'] === 'unconfigured', 'Missing schema public state');
$check($model->writes === [], 'Public does not install SQL');
$model->tables = ['explorer_transactions', 'explorer_blocks', 'explorer_state'];
$model->version = '1.0.0';
$check($model->databaseState() === 'update', 'Old schema offers update');
$model->version = '1.1.0';
$check($model->databaseState() === 'invalid', 'Missing settings table detected');
$model->tables[] = 'explorer_settings';
$check($model->databaseState() === 'current', 'Current schema');
$check($model->chainStatus('current')['state'] === 'unconfigured', 'No endpoint configured');
$valid = ['primary_ip' => '127.0.0.1', 'primary_port' => '12345', 'secondary_ip' => '', 'secondary_port' => ''];
$model->saveConfiguration($valid);
$check($model->configuration()['primary_port'] === 12345, 'Configuration persisted as parameters');
$check($model->configuration()['secondary_ip'] === null, 'Optional fallback absent');
foreach ([['primary_ip' => 'https://example.com'], ['primary_port' => '0'], ['primary_port' => '65536'],
    ['primary_ip' => "' OR 1=1"], ['primary_port' => []], ['secondary_ip' => '::1'], ['secondary_port' => '10']] as $bad) {
    $count = count($model->writes);
    try { $model->saveConfiguration(array_replace($valid, $bad)); $check(false, 'Invalid configuration accepted'); }
    catch (InvalidArgumentException) { $check(count($model->writes) === $count, 'Invalid input cannot mutate'); }
}
$model->deleteData();
$check($model->configuration()['primary_ip'] === '127.0.0.1', 'Delete Data preserves configuration');
$payload = str_repeat("\0", 184);
$payload = substr_replace($payload, str_repeat("\xff", 8), 64, 8);
$payload = substr_replace($payload, str_repeat("\xff", 40), 104, 40);
$payload = substr_replace($payload, "\0\0\0\1", 176, 4);
$payload = substr_replace($payload, str_repeat("\xff", 4), 180, 4);
$info = explorer_status::decode($payload);
$check($info['Chain height'] === '18446744073709551615', 'Exact u64 decoding');
$check($info['Stored block count'] === '4294967295', 'Exact u32 decoding');
$check($info['Cumulative work (hex)'] === str_repeat('f', 80), 'Full 320-bit work');
try { explorer_status::decode('short'); $check(false, 'Short INFO accepted'); }
catch (InvalidArgumentException) { $check(true, 'Short INFO rejected'); }

$render = new class {
    public function csrf_field(): string { return '<input name="csrf" value="test">'; }
    public function page(string $view, array $data): string {
        ob_start();
        include dirname(__DIR__) . '/views/' . $view . '.php';
        return ob_get_clean();
    }
};
$html = $render->page('index', ['chain' => ['state' => 'unconfigured', 'message' => 'Blockchain not configured.']]);
$check(str_contains($html, 'Blockchain not configured.'), 'Public setup message');
foreach (['missing' => 'Install SQL', 'update' => 'Update SQL', 'current' => 'Save configuration'] as $state => $label) {
    $html = $render->page('admin/explorer', ['database_state' => $state, 'configuration' => [],
        'chain' => ['state' => 'unconfigured', 'message' => 'Blockchain not configured.']]);
    $check(str_contains($html, $label), 'Admin lifecycle control');
    $check(!str_contains($html, '{{SLUG}}') && str_contains($html,
        $state === 'update' ? '/admin/modules' : '/admin/explorer'), 'Real admin route');
    $check(str_contains($html, '/admin/uninstall') && str_contains($html, 'name="csrf"'), 'Core removal and CSRF');
}
$html = $render->page('index', ['chain' => ['state' => 'online', 'message' => 'Blockchain connected.',
    'source' => 'secondary', 'info' => ['Tip' => '<script>alert(1)</script>']]]);
$check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Escaped status fields');
$check(str_contains($html, 'fallback node'), 'Fallback identified');

// Occupy a local port without listening, giving a deterministic failed primary.
$reserved = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
$deadPort = (int) substr(strrchr(stream_socket_get_name($reserved, false), ':'), 1);
$process = proc_open([PHP_BINARY, __DIR__ . '/stnc_client_test.php', '--serve', 'valid'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
try {
    stream_set_timeout($pipes[1], 5);
    $port = (int) fgets($pipes[1]);
    $configuration = ['primary_ip' => '127.0.0.1', 'primary_port' => $deadPort,
        'secondary_ip' => '127.0.0.1', 'secondary_port' => $port];
    $status = explorer_status::load($configuration);
    $check($status['state'] === 'online' && $status['source'] === 'secondary', 'Actual fallback exchange');
    foreach ($pipes as $pipe) { fclose($pipe); }
    $check(proc_close($process) === 0, 'Fallback fixture');
    unset($configuration['secondary_ip']);
    $check(explorer_status::load($configuration)['state'] === 'unavailable', 'Offline distinct from unconfigured');
} finally {
    fclose($reserved);
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
}
$model->tables = ['explorer_settings'];
$check($model->databaseState() === 'invalid', 'Orphan settings require recovery');
$before = count($model->writes);
try { $model->installSchema(); $check(false, 'Partial schema installation accepted'); }
catch (RuntimeException) { $check(count($model->writes) === $before, 'Partial schema not overwritten'); }
$check(!method_exists($model, 'updateSchema'), 'No module migration executor');
$manifest = json_decode(file_get_contents(dirname(__DIR__) . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$check(($manifest['migrations']['1.0.0-to-1.1.0'] ?? '') === 'sql/patches/1.0.0-to-1.1.0.sql', 'Exact migration declared');
$check(!in_array('admin', $manifest['routes'], true), 'Admin excluded from public routing');
foreach (['explorer_settings', 'explorer_transactions', 'explorer_blocks', 'explorer_state'] as $table) {
    $check(in_array($table, $manifest['database_tables'], true), 'Owned table declared');
}

// Exercise rejected POSTs and authorization ordering against the actual controller.
class controller
{
    public static explorer_model $model;
    public bool $authorized = true;
    public bool $csrf = true;
    public array $events = [];
    public function require_admin($level): void { $this->events[] = 'auth'; if (!$this->authorized) { throw new RuntimeException('auth'); } }
    public function require_csrf(): void { $this->events[] = 'csrf'; if (!$this->csrf) { throw new RuntimeException('csrf'); } }
    public function model($name) { $this->events[] = 'model'; return self::$model; }
    public function error_page($message): void { $this->events[] = 'error'; }
    public function view($name, $data): void { $this->events[] = 'view'; }
}
require_once dirname(__DIR__) . '/controllers/explorer.php';
controller::$model = $model;
$_SERVER['REQUEST_METHOD'] = 'POST';
foreach (['update_sql', 'uninstall', ['bad']] as $action) {
    $_POST = ['action' => $action];
    $controller = new explorer();
    $controller->admin();
    $check($controller->events === ['auth', 'model', 'csrf', 'error'], 'Rejected action stops at error');
}
$controller = new explorer();
$controller->authorized = false;
try { $controller->admin(); } catch (RuntimeException) {}
$check($controller->events === ['auth'], 'Authorization precedes model load');
$controller = new explorer();
$controller->csrf = false;
try { $controller->admin(); } catch (RuntimeException) {}
$check($controller->events === ['auth', 'model', 'csrf'], 'CSRF precedes mutation');
$check(count($model->writes) === $before, 'Rejected admin requests cause no writes');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = ['action' => 'install_sql'];
$controller = new explorer();
$controller->admin();
$check(count($model->writes) === $before && end($controller->events) === 'view', 'GET cannot install');
echo "$checks checks, 0 failures\n";
