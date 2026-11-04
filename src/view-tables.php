<?php

declare(strict_types=1);

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/security.php';

require_admin();
send_page_headers();
csrf_token();

$pdo = get_db();
$message = '';
$message_type = '';
$all_tables = list_tables($pdo);

// Row delete, clear table, drop table
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $target = (string) ($_POST['table_name'] ?? '');

    if (!in_array($target, $all_tables, true)) {
        $message = 'That table does not exist (any more).';
        $message_type = 'error';
    } else {
        $q = quote_ident($target);
        try {
            switch ($action) {
                case 'delete_row':
                    $rowId = (string) ($_POST['row_id'] ?? '');
                    if (!ctype_digit($rowId)) {
                        throw new InvalidArgumentException('Invalid row id');
                    }
                    $stmt = $pdo->prepare("DELETE FROM $q WHERE id = ?");
                    $stmt->execute([$rowId]);
                    $message = $stmt->rowCount() ? 'Row deleted.' : 'That row was already deleted.';
                    break;
                case 'truncate_table':
                    // No CASCADE: never silently empty other tables that reference this one.
                    $pdo->exec("TRUNCATE TABLE $q RESTART IDENTITY");
                    $message = "Table '$target' cleared.";
                    break;
                case 'drop_table':
                    $pdo->exec("DROP TABLE $q");
                    $message = "Table '$target' deleted.";
                    $all_tables = list_tables($pdo);
                    break;
                default:
                    throw new InvalidArgumentException('Unknown action');
            }
            $message_type = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $message_type = 'error';
        } catch (PDOException $e) {
            error_log("view-tables $action on $target failed: " . $e->getMessage());
            $message = 'Database error: ' . pg_error_summary($e);
            $message_type = 'error';
        }
    }
}

// Selected table
$selected_table = '';
$columns = [];
$table_data = [];
$has_id = false;
$row_count = 0;
$page = 1;
$total_pages = 1;
$requested = $_GET['table'] ?? '';

if (is_string($requested) && in_array($requested, $all_tables, true)) {
    $selected_table = $requested;
    $q = quote_ident($selected_table);

    try {
        $col_stmt = $pdo->prepare(
            "SELECT column_name, data_type FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?
             ORDER BY ordinal_position"
        );
        $col_stmt->execute([$selected_table]);
        $columns = $col_stmt->fetchAll(PDO::FETCH_ASSOC);
        // Rows can only be deleted one by one in tables with an "id" key (as the importer creates).
        $has_id = in_array('id', array_column($columns, 'column_name'), true);

        $row_count = (int) $pdo->query("SELECT COUNT(*) FROM $q")->fetchColumn();
        $per_page = 20;
        $total_pages = max(1, (int) ceil($row_count / $per_page));
        $page = min($total_pages, max(1, (int) ($_GET['page'] ?? 1)));
        $offset = ($page - 1) * $per_page;
        $order = $has_id ? 'ORDER BY id DESC' : '';

        $table_data = $pdo->query("SELECT * FROM $q $order LIMIT $per_page OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("view-tables: loading $selected_table failed: " . $e->getMessage());
        $message = 'Error loading table: ' . pg_error_summary($e);
        $message_type = 'error';
        $selected_table = '';
    }
}

/** Display form of a database value. */
function cell(mixed $v): string
{
    return match (true) {
        $v === null => '',
        is_bool($v) => $v ? 'true' : 'false',
        default => (string) $v,
    };
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View Tables</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Poppins|Inter|Montserrat">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            /*background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);*/
            background: #f0f2ff;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        header {
            background: linear-gradient(135deg, #57789e 0%, #415A77 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 600px;
        }
        .sidebar {
            background: #f8f9fa;
            border-right: 1px solid #e0e0e0;
            padding: 20px;
            overflow-y: auto;
        }
        .sidebar h2 {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .table-item {
            padding: 10px 12px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: #333;
            display: block;
            word-break: break-word;
        }
        .table-item:hover {
            background: #e9ecef;
            border-color: #667eea;
        }
        .table-item.active {
            background: linear-gradient(135deg, #57789e 0%, #778DA9 100%);
            color: white;
            border-color: #667eea;
        }
        .empty-tables {
            color: #999;
            font-size: 13px;
            text-align: center;
            padding: 20px 10px;
        }
        .main {
            padding: 30px;
            overflow-y: auto;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        .table-header h2 {
            font-size: 20px;
            color: #333;
        }
        .table-info {
            font-size: 13px;
            color: #666;
        }
        .table-actions {
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-danger {
            background: #dc3545;
            color: white;
            font-size: 12px;
            padding: 6px 12px;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-warning {
            background: #ffc107;
            color: #333;
            font-size: 12px;
            padding: 6px 12px;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .table-wrapper {
            overflow-x: auto;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e0e0e0;
            white-space: nowrap;
        }
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            word-break: break-word;
            max-width: 300px;
        }
        table tr:hover {
            background: #f8f9fa;
        }
        .cell-content {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .cell-content:hover {
            white-space: normal;
            overflow: visible;
            word-break: break-word;
        }
        .pagination {
            display: flex;
            gap: 5px;
            justify-content: center;
            margin-top: 20px;
        }
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 13px;
            text-decoration: none;
            color: #667eea;
        }
        .pagination a:hover {
            background: #e9ecef;
        }
        .pagination .active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }
        .no-data {
            text-align: center;
            color: #999;
            padding: 40px;
            font-size: 14px;
        }
        .column-info {
            font-size: 12px;
            color: #999;
            margin-top: 15px;
        }
        .column-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        .column-badge {
            background: #e9ecef;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            color: #555;
        }
        .column-badge .type {
            color: #667eea;
            font-weight: 600;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.3);
            max-width: 400px;
            text-align: center;
        }
        .modal-content h3 {
            margin-bottom: 15px;
            color: #333;
        }
        .modal-content p {
            color: #666;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .modal-buttons button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }
        .modal-buttons .btn-cancel {
            background: #e0e0e0;
            color: #333;
        }
        .modal-buttons .btn-confirm {
            background: #dc3545;
            color: white;
        }
    </style>
    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(function (m) { m.classList.remove('show'); });
            }
        });
    </script>
  </head>
  <body>
    <div class="container">
      <header>
        <h1>📊 View Database Tables</h1>
        <p>Browse and manage your imported data</p>
      </header>
      
      <div class="content">
        <div class="sidebar">
          <h2>Tables</h2>
          <div class="table-list">
            <?php if (!empty($all_tables)): ?>
                <?php foreach ($all_tables as $table): ?>
                    <a href="?table=<?php echo urlencode($table); ?>" 
                       class="table-item <?php echo $selected_table === $table ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($table); ?>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-tables">No tables found. Create one by importing a CSV file.</div>
            <?php endif; ?>
          </div>
        </div>
        
        <div class="main">
          <?php if ($message): ?>
              <div class="alert <?= h($message_type) ?>">
                  <?= h($message) ?>
              </div>
          <?php endif; ?>
          
          <?php if ($selected_table): ?>
              <div class="table-header">
                <div>
                  <h2><?php echo htmlspecialchars($selected_table); ?></h2>
                  <div class="table-info">
                      Rows: <strong><?php echo $row_count; ?></strong>
                  </div>
                  <?php if (!empty($columns)): ?>
                      <div class="column-info">
                        <strong>Columns:</strong>
                        <div class="column-list">
                          <?php foreach ($columns as $col): ?>
                              <span class="column-badge">
                                  <?php echo htmlspecialchars($col['column_name']); ?>
                                  <span class="type">(<?php echo htmlspecialchars($col['data_type']); ?>)</span>
                              </span>
                          <?php endforeach; ?>
                        </div>
                      </div>
                  <?php endif; ?>
                </div>
                <div class="table-actions">
                  <button class="btn btn-warning" onclick="openModal('deleteModal')">
                    🗑️ Clear Table
                  </button>
                  <button class="btn btn-danger" onclick="openModal('dropModal')">
                    ❌ Delete Table
                  </button>
                </div>
              </div>
              
              <?php if (!empty($table_data)): ?>
                  <div class="table-wrapper">
                    <table>
                      <thead>
                        <tr>
                          <?php foreach ($columns as $col): ?>
                              <th><?php echo htmlspecialchars($col['column_name']); ?></th>
                          <?php endforeach; ?>
                          <?php if ($has_id): ?>
                              <th style="width: 60px; text-align: center;">Action</th>
                          <?php endif; ?>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($table_data as $row): ?>
                            <tr>
                              <?php foreach ($columns as $col): ?>
                                  <td>
                                    <span class="cell-content" title="<?= h(cell($row[$col['column_name']] ?? null)) ?>">
                                        <?= h(cell($row[$col['column_name']] ?? null)) ?>
                                    </span>
                                  </td>
                              <?php endforeach; ?>
                              <?php if ($has_id): ?>
                                  <td style="text-align: center;">
                                    <form method="POST" onsubmit="return confirm('Delete this row?')">
                                      <?= csrf_field() ?>
                                      <input type="hidden" name="action" value="delete_row">
                                      <input type="hidden" name="table_name" value="<?= h($selected_table) ?>">
                                      <input type="hidden" name="row_id" value="<?= h(cell($row['id'])) ?>">
                                      <button type="submit" class="btn-danger" style="padding: 4px 8px; font-size: 11px;">Delete</button>
                                    </form>
                                  </td>
                              <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  
                  <?php if ($total_pages > 1): ?>
                      <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?table=<?php echo urlencode($selected_table); ?>&page=1">« First</a>
                            <a href="?table=<?php echo urlencode($selected_table); ?>&page=<?php echo $page - 1; ?>">‹ Previous</a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <?php if ($i === $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?table=<?php echo urlencode($selected_table); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?table=<?php echo urlencode($selected_table); ?>&page=<?php echo $page + 1; ?>">Next ›</a>
                            <a href="?table=<?php echo urlencode($selected_table); ?>&page=<?php echo $total_pages; ?>">Last »</a>
                        <?php endif; ?>
                      </div>
                  <?php endif; ?>
              <?php else: ?>
                  <div class="no-data">
                    📭 No data in this table
                  </div>
              <?php endif; ?>
          <?php else: ?>
              <div class="no-data">
                👈 Select a table from the sidebar to view its data
              </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    
    <?php if ($selected_table): ?>
    <!-- Clear (truncate) table -->
    <div id="deleteModal" class="modal">
      <div class="modal-content">
        <h3>⚠️ Clear Table</h3>
        <p>This will delete all rows from <strong><?= h($selected_table) ?></strong>. This action cannot be undone.</p>
        <form method="POST" class="modal-buttons">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="truncate_table">
          <input type="hidden" name="table_name" value="<?= h($selected_table) ?>">
          <button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
          <button type="submit" class="btn-confirm">Clear Table</button>
        </form>
      </div>
    </div>

    <!-- Drop table -->
    <div id="dropModal" class="modal">
      <div class="modal-content">
        <h3>❌ Delete Table</h3>
        <p>This will permanently delete <strong><?= h($selected_table) ?></strong> and all its data. This action cannot be undone.</p>
        <form method="POST" class="modal-buttons">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="drop_table">
          <input type="hidden" name="table_name" value="<?= h($selected_table) ?>">
          <button type="button" class="btn-cancel" onclick="closeModal('dropModal')">Cancel</button>
          <button type="submit" class="btn-confirm">Delete Table</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </body>
</html>
