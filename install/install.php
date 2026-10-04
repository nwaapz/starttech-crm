<?php
/**
 * Mode B — create canonical database from empty MySQL.
 * Usage (CLI): php install.php --host=localhost --user=root --pass=... --db=payamesh_crm --admin-pass=secret
 * Or open in browser with POST form fields.
 */
header('Content-Type: text/plain; charset=utf-8');

function arg(string $key, ?string $default = null): ?string {
    global $argv;
    if (PHP_SAPI === 'cli' && !empty($argv)) {
        foreach ($argv as $a) {
            if (str_starts_with($a, "--$key=")) {
                return substr($a, strlen($key) + 3);
            }
        }
    }
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

$host = arg('host', 'localhost');
$user = arg('user', 'root');
$pass = arg('pass', '');
$dbName = arg('db', 'payamesh_crm');
$adminPass = arg('admin-pass', 'admin123');
$syncToken = arg('sync-token', bin2hex(random_bytes(16)));

if ($_SERVER['REQUEST_METHOD'] === 'GET' && PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<form method="post"><h1>Payamesh CRM install (Mode B)</h1>';
    echo 'Host <input name="host" value="localhost"><br>';
    echo 'User <input name="user" value="root"><br>';
    echo 'Pass <input name="pass" type="password"><br>';
    echo 'DB <input name="db" value="payamesh_crm"><br>';
    echo 'Admin pass <input name="admin-pass" value="admin123"><br>';
    echo '<button type="submit">Install</button></form>';
    exit;
}

$mysqli = @new mysqli($host, $user, $pass);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Connect failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$mysqli->select_db($dbName);
$sql = file_get_contents(__DIR__ . '/canonical_schema.sql');
if (!$mysqli->multi_query($sql)) {
    fwrite(STDERR, "Schema error: {$mysqli->error}\n");
    exit(1);
}
while ($mysqli->more_results() && $mysqli->next_result()) { /* drain */ }

$hash = password_hash($adminPass, PASSWORD_DEFAULT);
$stmt = $mysqli->prepare('INSERT INTO users (name, pass, role) VALUES (?, ?, 5) ON DUPLICATE KEY UPDATE pass = VALUES(pass), role = 5');
$adminName = 'admin';
$stmt->bind_param('ss', $adminName, $hash);
$stmt->execute();
$stmt->close();

$configDir = dirname(__DIR__) . '/config';
if (!is_dir($configDir)) {
    mkdir($configDir, 0755, true);
}
$config = "<?php\n\$PAYAMESH_DB = [\n"
    . "    'host' => " . var_export($host, true) . ",\n"
    . "    'username' => " . var_export($user, true) . ",\n"
    . "    'password' => " . var_export($pass, true) . ",\n"
    . "    'database' => " . var_export($dbName, true) . ",\n"
    . "    'charset' => 'utf8mb4',\n"
    . "];\n\$PAYAMESH_SYNC_TOKEN = " . var_export($syncToken, true) . ";\n"
    . "function payamesh_mysqli(): mysqli {\n"
    . "    global \$PAYAMESH_DB;\n"
    . "    \$conn = new mysqli(\$PAYAMESH_DB['host'], \$PAYAMESH_DB['username'], \$PAYAMESH_DB['password'], \$PAYAMESH_DB['database']);\n"
    . "    if (\$conn->connect_error) { http_response_code(500); echo json_encode(['error'=>'DB']); exit; }\n"
    . "    \$conn->set_charset('utf8mb4'); return \$conn;\n}\n";
file_put_contents($configDir . '/database.php', $config);

echo "Installed database `$dbName`\n";
echo "Admin user: admin / (your password)\n";
echo "Sync token: $syncToken\n";
echo "Set this URL+token on the phone Admin Setup / Settings.\n";
