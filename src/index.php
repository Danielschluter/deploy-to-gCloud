<?php

declare(strict_types=1);

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/security.php';

send_page_headers();

// Report whether the database answers, without revealing its version publicly.
$dbOk = false;
try {
    $dbOk = get_db()->query('SELECT 1')->fetchColumn() == 1;
} catch (Throwable $e) {
    error_log('Health query failed: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Tools</title>
    <style>
      body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #f0f2ff; margin: 0; padding: 40px 20px; color: #333; }
      main { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.12); padding: 30px; }
      h1 { margin: 0 0 6px; font-size: 24px; }
      .status { font-size: 14px; color: #666; margin-bottom: 24px; }
      .ok { color: #155724; } .down { color: #721c24; }
      a.tool { display: block; padding: 14px 16px; border: 1px solid #d4d9f5; border-radius: 6px; margin-bottom: 10px; color: #415A77; text-decoration: none; font-weight: 600; }
      a.tool:hover { background: #f8f9ff; border-color: #667eea; }
      a.tool span { display: block; font-weight: 400; font-size: 13px; color: #777; margin-top: 2px; }
    </style>
  </head>
  <body>
    <main>
      <h1>Data Tools</h1>
      <p class="status">Database: <strong class="<?= $dbOk ? 'ok' : 'down' ?>"><?= $dbOk ? 'connected' : 'unavailable' ?></strong></p>
      <a class="tool" href="csv-import.php">📊 CSV Import<span>Upload a CSV to create or fill a table</span></a>
      <a class="tool" href="view-tables.php">📋 View Tables<span>Browse, clear and delete tables</span></a>
    </main>
  </body>
</html>
