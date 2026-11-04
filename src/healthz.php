<?php

// Liveness check for Cloud Run / Docker: proves nginx -> PHP-FPM works.
// Doesn't touch the database, so a database blip doesn't restart instances.

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "ok\n";
