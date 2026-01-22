<?php
require 'db.php';
$pdo = get_db();
try {
    $stmt = $pdo->query('SELECT version()');
    $version = $stmt->fetchColumn();
} catch (Exception $e) {
    $version = 'Query failed: ' . $e->getMessage();
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>PHP + PostgreSQL</title>
  </head>
  <body>
    <h1>PHP + PostgreSQL</h1>
    <p>Postgres version: <?php echo htmlspecialchars($version); ?></p>
  </body>
</html>
