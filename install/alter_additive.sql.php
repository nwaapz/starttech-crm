<?php
/**
 * Mode A — additive columns for production (safe re-run).
 * Includes LAN-parity category (seller / end-user client) — not a lottery flag.
 *
 * CLI: php alter_additive.sql.php
 */
require_once __DIR__ . '/schema_helpers.php';

header('Content-Type: text/plain; charset=utf-8');

$conn = payamesh_mysqli();
payamesh_ensure_unified_serial_columns($conn, true);
echo "done — old_serials/new_serials have sync + category + score columns\n";
echo "category values: end_user_client | seller_to_end_user\n";
