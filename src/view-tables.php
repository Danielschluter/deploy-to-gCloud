<?php
require 'db.php';

$pdo = get_db();
$selected_table = '';
$table_data = [];
$columns = [];
$message = '';
$message_type = '';

// Fetch all tables
$stmt = $pdo->query("
    SELECT table_name FROM information_schema.tables 
    WHERE table_schema = 'public' 
    ORDER BY table_name
");
$all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle table selection and deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete_row') {
        $table_to_delete = $_POST['table_name'] ?? '';
        $row_id = $_POST['row_id'] ?? '';
        
        if ($table_to_delete && $row_id && in_array($table_to_delete, $all_tables)) {
            try {
                $delete_stmt = $pdo->prepare("DELETE FROM \"$table_to_delete\" WHERE id = ?");
                $delete_stmt->execute([$row_id]);
                $message = 'Row deleted successfully';
                $message_type = 'success';
            } catch (Exception $e) {
                $message = 'Error deleting row: ' . htmlspecialchars($e->getMessage());
                $message_type = 'error';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'truncate_table') {
        $table_to_truncate = $_POST['table_name'] ?? '';
        
        if ($table_to_truncate && in_array($table_to_truncate, $all_tables)) {
            try {
                $pdo->exec("TRUNCATE TABLE \"$table_to_truncate\" RESTART IDENTITY CASCADE");
                $message = "Table '$table_to_truncate' cleared successfully";
                $message_type = 'success';
                $selected_table = '';
            } catch (Exception $e) {
                $message = 'Error clearing table: ' . htmlspecialchars($e->getMessage());
                $message_type = 'error';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'drop_table') {
        $table_to_drop = $_POST['table_name'] ?? '';
        
        if ($table_to_drop && in_array($table_to_drop, $all_tables)) {
            try {
                $pdo->exec("DROP TABLE \"$table_to_drop\"");
                $message = "Table '$table_to_drop' deleted successfully";
                $message_type = 'success';
                $selected_table = '';
                // Refresh table list
                $stmt = $pdo->query("
                    SELECT table_name FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    ORDER BY table_name
                ");
                $all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                $message = 'Error dropping table: ' . htmlspecialchars($e->getMessage());
                $message_type = 'error';
            }
        }
    }
}

// Load selected table data
if (isset($_GET['table']) && in_array($_GET['table'], $all_tables)) {
    $selected_table = $_GET['table'];
    
    try {
        // Get column information
        $col_stmt = $pdo->query("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = '$selected_table'
            ORDER BY ordinal_position
        ");
        $columns = $col_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get row count
        $count_stmt = $pdo->query("SELECT COUNT(*) FROM \"$selected_table\"");
        $row_count = $count_stmt->fetchColumn();
        
        // Pagination
        $per_page = 20;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $per_page;
        $total_pages = ceil($row_count / $per_page);
        
        // Get table data
        $data_stmt = $pdo->query("
            SELECT * FROM \"$selected_table\" 
            ORDER BY id DESC
            LIMIT $per_page OFFSET $offset
        ");
        $table_data = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $message = 'Error loading table: ' . htmlspecialchars($e->getMessage());
        $message_type = 'error';
        $selected_table = '';
    }
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
        function openDeleteModal(tableName) {
            document.getElementById('deleteModal').classList.add('show');
            document.getElementById('deleteTableName').value = tableName;
            document.getElementById('deleteAction').value = 'truncate_table';
        }
        
        function openDropModal(tableName) {
            document.getElementById('dropModal').classList.add('show');
            document.getElementById('dropTableName').value = tableName;
            document.getElementById('dropAction').value = 'drop_table';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }
        
        function confirmDelete() {
            const form = document.createElement('form');
            form.method = 'POST';
            
            const tableInput = document.createElement('input');
            tableInput.type = 'hidden';
            tableInput.name = 'table_name';
            tableInput.value = document.getElementById('deleteTableName').value;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'truncate_table';
            
            form.appendChild(tableInput);
            form.appendChild(actionInput);
            document.body.appendChild(form);
            form.submit();
        }
        
        function confirmDrop() {
            const form = document.createElement('form');
            form.method = 'POST';
            
            const tableInput = document.createElement('input');
            tableInput.type = 'hidden';
            tableInput.name = 'table_name';
            tableInput.value = document.getElementById('dropTableName').value;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'drop_table';
            
            form.appendChild(tableInput);
            form.appendChild(actionInput);
            document.body.appendChild(form);
            form.submit();
        }
        
        function deleteRow(tableNum, rowId) {
            if (!confirm('Are you sure you want to delete this row?')) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            
            const tableInput = document.createElement('input');
            tableInput.type = 'hidden';
            tableInput.name = 'table_name';
            tableInput.value = document.querySelector('.table-item.active').textContent;
            
            const rowInput = document.createElement('input');
            rowInput.type = 'hidden';
            rowInput.name = 'row_id';
            rowInput.value = rowId;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_row';
            
            form.appendChild(tableInput);
            form.appendChild(rowInput);
            form.appendChild(actionInput);
            document.body.appendChild(form);
            form.submit();
        }
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
              <div class="alert <?php echo $message_type; ?>">
                  <?php echo htmlspecialchars($message); ?>
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
                  <button class="btn btn-warning" onclick="openDeleteModal('<?php echo htmlspecialchars($selected_table); ?>')">
                    🗑️ Clear Table
                  </button>
                  <button class="btn btn-danger" onclick="openDropModal('<?php echo htmlspecialchars($selected_table); ?>')">
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
                          <th style="width: 60px; text-align: center;">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($table_data as $row): ?>
                            <tr>
                              <?php foreach ($columns as $col): ?>
                                  <td>
                                    <span class="cell-content" title="<?php echo htmlspecialchars($row[$col['column_name']] ?? ''); ?>">
                                        <?php echo htmlspecialchars($row[$col['column_name']] ?? ''); ?>
                                    </span>
                                  </td>
                              <?php endforeach; ?>
                              <td style="text-align: center;">
                                <button class="btn-danger" style="padding: 4px 8px; font-size: 11px;" 
                                        onclick="deleteRow(this, <?php echo $row['id']; ?>)">
                                    Delete
                                </button>
                              </td>
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
    
    <!-- Delete/Truncate Modal -->
    <div id="deleteModal" class="modal">
      <div class="modal-content">
        <h3>⚠️ Clear Table</h3>
        <p>This will delete all rows from the table. This action cannot be undone.</p>
        <input type="hidden" id="deleteTableName">
        <input type="hidden" id="deleteAction">
        <div class="modal-buttons">
          <button class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
          <button class="btn-confirm" onclick="confirmDelete()">Clear Table</button>
        </div>
      </div>
    </div>
    
    <!-- Drop Table Modal -->
    <div id="dropModal" class="modal">
      <div class="modal-content">
        <h3>❌ Delete Table</h3>
        <p>This will permanently delete the entire table and all its data. This action cannot be undone.</p>
        <input type="hidden" id="dropTableName">
        <input type="hidden" id="dropAction">
        <div class="modal-buttons">
          <button class="btn-cancel" onclick="closeModal('dropModal')">Cancel</button>
          <button class="btn-confirm" onclick="confirmDrop()">Delete Table</button>
        </div>
      </div>
    </div>
  </body>
</html>
