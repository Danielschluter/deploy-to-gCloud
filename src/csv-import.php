<?php
require 'db.php';

$message = '';
$messageType = '';
$uploaded_file = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table_name'] ?? 'imported_data');
    
    if (empty($table_name)) {
        $table_name = 'imported_data';
    }
    
    $file = $_FILES['csv_file'];
    
    // Validate file upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'File upload error: ' . $file['error'];
        $messageType = 'error';
    } elseif ($file['type'] !== 'text/csv' && pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        $message = 'Please upload a valid CSV file';
        $messageType = 'error';
    } else {
        try {
            $pdo = get_db();
            $rows = [];
            $headers = [];
            
            // Read CSV file
            if (($handle = fopen($file['tmp_name'], 'r')) !== false) {
                $row_num = 0;
                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                    if ($row_num === 0) {
                        $headers = $data;
                    } else {
                        $rows[] = $data;
                    }
                    $row_num++;
                }
                fclose($handle);
            }
            
            if (empty($headers)) {
                throw new Exception('CSV file is empty');
            }
            
            if (empty($rows)) {
                throw new Exception('CSV file has no data rows');
            }
            
            // Sanitize column names
            $clean_headers = array_map(function($header) {
                return preg_replace('/[^a-zA-Z0-9_]/', '_', trim($header));
            }, $headers);
            
            // Infer column types from first few rows
            $types = [];
            foreach ($clean_headers as $idx => $header) {
                $types[$header] = 'TEXT'; // Default to TEXT
                
                // Check first 10 rows to infer type
                $sample_rows = array_slice($rows, 0, min(10, count($rows)));
                $all_numeric = true;
                $all_integer = true;
                
                foreach ($sample_rows as $row) {
                    $value = trim($row[$idx] ?? '');
                    if ($value === '') continue;
                    
                    if (!is_numeric($value)) {
                        $all_numeric = false;
                        $all_integer = false;
                    } elseif (strpos($value, '.') !== false) {
                        // Contains a decimal point, so it's not an integer
                        $all_integer = false;
                    }
                }
                
                if ($all_numeric) {
                    $types[$header] = $all_integer ? 'INTEGER' : 'DECIMAL(15,2)';
                }
            }
            
            // Check if table exists
            $table_exists_query = "
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_name = ?
                )
            ";
            $stmt = $pdo->prepare($table_exists_query);
            $stmt->execute([$table_name]);
            $table_exists = $stmt->fetchColumn();
            
            // Create table if it doesn't exist
            if (!$table_exists) {
                $create_sql = "CREATE TABLE $table_name (id SERIAL PRIMARY KEY";
                foreach ($clean_headers as $header) {
                    $create_sql .= ", \"$header\" " . $types[$header];
                }
                $create_sql .= ")";
                
                $pdo->exec($create_sql);
                $message = "Table '$table_name' created successfully. ";
            } else {
                $message = "Inserting data into existing table '$table_name'. ";
            }
            
            // Insert data
            $insert_sql = "INSERT INTO $table_name (";
            $insert_sql .= implode(', ', array_map(fn($h) => "\"$h\"", $clean_headers));
            $insert_sql .= ") VALUES (";
            $insert_sql .= implode(', ', array_fill(0, count($clean_headers), '?'));
            $insert_sql .= ")";
            
            $stmt = $pdo->prepare($insert_sql);
            
            $inserted = 0;
            foreach ($rows as $row) {
                $values = [];
                foreach ($clean_headers as $idx => $header) {
                    $value = trim($row[$idx] ?? '');
                    $values[] = $value === '' ? null : $value;
                }
                $stmt->execute($values);
                $inserted++;
            }
            
            $message .= "Successfully inserted $inserted rows into '$table_name'.";
            $messageType = 'success';
            $uploaded_file = [
                'name' => htmlspecialchars($file['name']),
                'table' => htmlspecialchars($table_name),
                'rows' => $inserted,
                'headers' => $clean_headers
            ];
            
        } catch (Exception $e) {
            $message = 'Error: ' . htmlspecialchars($e->getMessage());
            $messageType = 'error';
        }
    }
}

// Fetch existing tables
$existing_tables = [];
try {
    $pdo = get_db();
    $stmt = $pdo->query("
        SELECT table_name FROM information_schema.tables 
        WHERE table_schema = 'public' 
        ORDER BY table_name
    ");
    $existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Silently fail
}
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
            <div class="alert <?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
          <div class="form-group">
            <label>Table Name (optional)</label>
            <input type="text" name="table_name" placeholder="Leave blank for 'imported_data'" value="">
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
          </div>
          
          <button type="submit">Import CSV</button>
        </form>
        
        <?php if ($uploaded_file): ?>
            <div class="result">
              <h3>✅ Import Successful</h3>
              <p><strong>File:</strong> <?php echo $uploaded_file['name']; ?></p>
              <p><strong>Table:</strong> <?php echo $uploaded_file['table']; ?></p>
              <p><strong>Rows Inserted:</strong> <?php echo $uploaded_file['rows']; ?></p>
              <p><strong>Columns:</strong></p>
              <table>
                <thead>
                  <tr>
                    <th>Column Name</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($uploaded_file['headers'] as $header): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($header); ?></td>
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
                    <div class="table-item"><?php echo htmlspecialchars($table); ?></div>
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
