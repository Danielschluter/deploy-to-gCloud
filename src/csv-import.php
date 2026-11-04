<?php

declare(strict_types=1);

require __DIR__ . '/lib/csv.php';
require __DIR__ . '/lib/security.php';

require_admin();
send_page_headers();
csrf_token();

$message = '';
$messageType = '';
$result = null;
$maxUpload = ini_get('upload_max_filesize');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // PHP drops the whole body when it exceeds post_max_size.
        $message = "The upload is too large. The limit is {$maxUpload}.";
        $messageType = 'error';
    } else {
        csrf_check();
        $file = $_FILES['csv_file'] ?? null;
        $table = sanitize_table_name((string) ($_POST['table_name'] ?? ''));

        try {
            if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
                throw new ImportError('Choose a CSV file to upload');
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new ImportError(match ($file['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "The file is too large. The limit is {$maxUpload}.",
                    UPLOAD_ERR_NO_FILE => 'Choose a CSV file to upload',
                    default => 'The upload failed (error ' . (int) $file['error'] . '). Please try again.',
                });
            }
            if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new ImportError('Please upload a file with a .csv extension');
            }

            $result = import_csv(get_db(), $file['tmp_name'], $table);
            $result['file'] = (string) $file['name'];
            $message = ($result['created'] ? "Table '{$table}' created. " : "Added to existing table '{$table}'. ")
                . "Inserted {$result['rows']} rows.";
            $messageType = 'success';
        } catch (ImportError $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (PDOException $e) {
            error_log('CSV import failed: ' . $e->getMessage());
            $message = 'Database error: ' . pg_error_summary($e);
            $messageType = 'error';
        }
    }
}

$existing_tables = list_tables(get_db());
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CSV Import</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            /*background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);*/
            background: #f0f2ff;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            padding: 30px;
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
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        input[type="file"],
        input[type="text"],
        select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.3s;
        }
        input[type="file"]:focus,
        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .file-label {
            display: block;
            padding: 30px;
            border: 2px dashed #667eea;
            border-radius: 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9ff;
        }
        .file-label:hover {
            background: #f0f2ff;
            border-color: #764ba2;
        }
        .file-label input[type="file"] {
            display: none;
        }
        .file-name {
            font-size: 14px;
            color: #667eea;
            margin-top: 8px;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            width: 100%;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        button:active {
            transform: translateY(0);
        }
        .result {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-top: 20px;
        }
        .result h3 {
            color: #333;
            margin-bottom: 10px;
        }
        .result p {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        .result table {
            width: 100%;
            margin-top: 15px;
            border-collapse: collapse;
            font-size: 13px;
        }
        .result table th,
        .result table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .result table th {
            background: #e9ecef;
            font-weight: 600;
            color: #333;
        }
        .tables-section {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #e0e0e0;
        }
        .tables-section h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .table-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }
        .table-item {
            background: #f0f2ff;
            padding: 10px 15px;
            border-radius: 4px;
            font-size: 13px;
            color: #667eea;
            font-weight: 500;
            border: 1px solid #d4d9f5;
        }
        .hint {
            font-size: 12px;
            color: #888;
            margin-top: 6px;
        }
        a.table-item {
            text-decoration: none;
            display: block;
        }
        .empty-state {
            text-align: center;
            color: #999;
            font-size: 14px;
            padding: 20px;
        }
    </style>
    <script>
        function handleFileSelect(input) {
            const fileName = input.files[0]?.name || 'No file selected';
            const label = input.parentElement.querySelector('.file-name');
            if (label) {
                label.textContent = fileName;
            }
        }
    </script>
  </head>
  <body>
    <div class="container">
      <header>
        <h1>📊 CSV Database Importer</h1>
        <p>Upload a CSV file and automatically create/populate database tables</p>
      </header>
      
      <div class="content">
        <?php if ($message): ?>
            <div class="alert <?= h($messageType) ?>">
                <?= h($message) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="form-group">
            <label>Table Name (optional)</label>
            <input type="text" name="table_name" placeholder="Leave blank for 'imported_data'" value="" maxlength="63" pattern="[A-Za-z0-9_]*" title="Letters, digits and underscores">
          </div>
          
          <div class="form-group">
            <label>CSV File</label>
            <label class="file-label">
              <input type="file" name="csv_file" accept=".csv" onchange="handleFileSelect(this)" required>
              <div style="font-size: 14px; color: #667eea; font-weight: 500;">
                📁 Click to select CSV file or drag & drop
              </div>
              <div class="file-name"></div>
            </label>
            <p class="hint">First row must be the column names. Max size <?= h($maxUpload) ?>.</p>
          </div>
          
          <button type="submit">Import CSV</button>
        </form>
        
        <?php if ($result): ?>
            <div class="result">
              <h3>✅ Import Successful</h3>
              <p><strong>File:</strong> <?= h($result['file']) ?></p>
              <p><strong>Table:</strong> <a href="view-tables.php?table=<?= h(urlencode($result['table'])) ?>"><?= h($result['table']) ?></a></p>
              <p><strong>Rows Inserted:</strong> <?= (int) $result['rows'] ?></p>
              <p><strong>Columns:</strong></p>
              <table>
                <thead>
                  <tr>
                    <th>Column Name</th>
                    <th>Type</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($result['columns'] as $column => $type): ?>
                    <tr>
                      <td><?= h($column) ?></td>
                      <td><?= h(strtolower($type)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($existing_tables)): ?>
            <div class="tables-section">
              <h3>📋 Existing Tables</h3>
              <div class="table-list">
                <?php foreach ($existing_tables as $table): ?>
                    <a class="table-item" href="view-tables.php?table=<?= h(urlencode($table)) ?>"><?= h($table) ?></a>
                <?php endforeach; ?>
              </div>
            </div>
        <?php else: ?>
            <div class="tables-section">
              <div class="empty-state">No tables created yet. Upload a CSV to get started!</div>
            </div>
        <?php endif; ?>
      </div>
    </div>
  </body>
</html>
