<?php
/**
 * Fallback for clients that POST /api/sync/push (no .php).
 * Hosts that ignore extensionless rewrites still serve this DirectoryIndex.
 */
require dirname(__DIR__) . '/push.php';
