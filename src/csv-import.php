<?php
require 'db.php';
require 'includes/layout.php';

$message = '';
$messageType = '';
$uploaded_file = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table_name'] ?? 'imported_data');
    if (empty($table_name)) $table_name = 'imported_data';

    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Upload error: ' . $file['error']; $messageType = 'error';
    } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        $message = 'Please upload a .csv file'; $messageType = 'error';
    } else {
        try {
            $pdo = get_db();
            $rows = []; $headers = [];

            if (($handle = fopen($file['tmp_name'], 'r')) !== false) {
                $rn = 0;
                while (($data = fgetcsv($handle, 10000, ',')) !== false) {
                    if ($rn === 0) $headers = $data;
                    else $rows[] = $data;
                    $rn++;
                }
                fclose($handle);
            }

            if (empty($headers)) throw new Exception('CSV file is empty or has no headers');
            if (empty($rows)) throw new Exception('CSV file has no data rows');

            $clean_headers = array_map(fn($h) => preg_replace('/[^a-zA-Z0-9_]/', '_', trim($h)) ?: 'col', $headers);

            // Infer types
            $types = [];
            foreach ($clean_headers as $idx => $hdr) {
                $types[$hdr] = 'TEXT';
                $sample = array_slice($rows, 0, min(10, count($rows)));
                $allNum = true; $allInt = true;
                foreach ($sample as $row) {
                    $v = trim($row[$idx] ?? '');
                    if ($v === '') continue;
                    if (!is_numeric($v)) { $allNum = false; $allInt = false; break; }
                    elseif (strpos($v, '.') !== false) $allInt = false;
                }
                if ($allNum) $types[$hdr] = $allInt ? 'INTEGER' : 'DECIMAL(15,2)';
            }

            // Check / create table
            $exists = $pdo->prepare("SELECT EXISTS(SELECT FROM information_schema.tables WHERE table_name=?)");
            $exists->execute([$table_name]);
            if (!$exists->fetchColumn()) {
                $cols_sql = implode(', ', array_map(fn($h) => "\"$h\" " . $types[$h], $clean_headers));
                $pdo->exec("CREATE TABLE $table_name (id SERIAL PRIMARY KEY, $cols_sql)");
                $created = true;
            } else {
                $created = false;
            }

            // Insert
            $ph = implode(',', array_fill(0, count($clean_headers), '?'));
            $col_list = implode(',', array_map(fn($h)=>"\"$h\"", $clean_headers));
            $stmt = $pdo->prepare("INSERT INTO $table_name ($col_list) VALUES ($ph)");
            $inserted = 0;
            foreach ($rows as $row) {
                $vals = array_map(fn($i) => (trim($row[$i] ?? '') === '') ? null : trim($row[$i] ?? ''), range(0, count($clean_headers)-1));
                $stmt->execute($vals);
                $inserted++;
            }

            $message = ($created ? "Table '$table_name' created. " : "Using existing table '$table_name'. ") . "Inserted $inserted rows.";
            $messageType = 'success';
            $uploaded_file = ['name' => $file['name'], 'table' => $table_name, 'rows' => $inserted, 'headers' => $clean_headers, 'types' => $types];
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage(); $messageType = 'error';
        }
    }
}

// Existing tables
$existing_tables = [];
try {
    $pdo2 = get_db();
    $existing_tables = $pdo2->query("
        SELECT table_name FROM information_schema.tables
        WHERE table_schema='public' AND table_name NOT LIKE '_sqlclient_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

page_header('CSV Import', 'import');
?>
<style>
.import-wrap{max-width:760px;margin:0 auto}
.drop-zone{border:2px dashed var(--border2);border-radius:8px;padding:36px;text-align:center;cursor:pointer;transition:all .2s;background:var(--panel)}
.drop-zone:hover,.drop-zone.drag-over{border-color:var(--accent);background:var(--accent-bg)}
.drop-zone input[type=file]{display:none}
.drop-zone-ico{font-size:32px;margin-bottom:10px}
.drop-zone-txt{color:var(--text2);font-size:13px}
.drop-zone-sub{color:var(--text3);font-size:11px;margin-top:4px}
.file-chosen{color:var(--accent);font-size:12px;margin-top:8px;font-family:monospace}
</style>

<div class="import-wrap">
    <?php if ($message): ?>
    <div class="card" style="margin-bottom:16px;border-color:<?= $messageType==='success'?'var(--green)':'var(--red)' ?>">
        <div class="card-body" style="color:<?= $messageType==='success'?'var(--green)':'var(--red)' ?>;font-size:13px">
            <?= $messageType==='success'?'✓':'❌' ?> <?= htmlspecialchars($message) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($uploaded_file): ?>
    <div class="card" style="margin-bottom:16px;border-color:var(--green)">
        <div class="card-hdr" style="color:var(--green)">✓ Import Successful</div>
        <div class="card-body">
            <div class="grid g2" style="margin-bottom:12px">
                <div>
                    <div class="stat-lbl" style="margin-bottom:4px">File</div>
                    <div style="font-size:12px"><?= htmlspecialchars($uploaded_file['name']) ?></div>
                </div>
                <div>
                    <div class="stat-lbl" style="margin-bottom:4px">Table</div>
                    <a href="explorer.php?table=<?= urlencode($uploaded_file['table']) ?>" style="font-size:12px;font-family:monospace">
                        <?= htmlspecialchars($uploaded_file['table']) ?>
                    </a>
                </div>
                <div>
                    <div class="stat-lbl" style="margin-bottom:4px">Rows Inserted</div>
                    <div style="font-size:12px;color:var(--green);font-weight:700"><?= number_format($uploaded_file['rows']) ?></div>
                </div>
                <div>
                    <div class="stat-lbl" style="margin-bottom:4px">Columns</div>
                    <div style="font-size:12px"><?= count($uploaded_file['headers']) ?></div>
                </div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
                <?php foreach ($uploaded_file['headers'] as $h): ?>
                <span class="badge b-blue"><?= htmlspecialchars($h) ?> <span style="opacity:.6;font-size:9px"><?= $uploaded_file['types'][$h] ?></span></span>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:14px;display:flex;gap:8px">
                <a href="explorer.php?table=<?= urlencode($uploaded_file['table']) ?>" class="btn btn-primary btn-sm">View in Explorer →</a>
                <a href="view-tables.php?table=<?= urlencode($uploaded_file['table']) ?>" class="btn btn-ghost btn-sm">View Data →</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:16px">
        <div class="card-hdr">⬆ Import CSV File</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="import-form">
                <div class="form-group">
                    <label class="form-lbl">Table Name</label>
                    <input type="text" name="table_name" class="form-inp" placeholder="Leave blank to use 'imported_data'"
                           pattern="[a-zA-Z0-9_]*" title="Letters, numbers and underscores only">
                    <div style="color:var(--text3);font-size:10.5px;margin-top:4px">Letters, numbers and underscores only. Leave blank for default.</div>
                </div>
                <div class="form-group">
                    <label class="form-lbl">CSV File *</label>
                    <label class="drop-zone" id="drop-zone">
                        <input type="file" name="csv_file" id="csv-file" accept=".csv" required onchange="onFileSelect(this)">
                        <div class="drop-zone-ico">📁</div>
                        <div class="drop-zone-txt">Click to choose a CSV file</div>
                        <div class="drop-zone-sub">or drag and drop here</div>
                        <div class="file-chosen" id="file-chosen"></div>
                    </label>
                </div>
                <div style="display:flex;gap:10px;align-items:center">
                    <button type="submit" class="btn btn-primary" id="submit-btn">⬆ Import CSV</button>
                    <span id="importing-msg" style="display:none;color:var(--text3);font-size:12px"><span class="spinner" style="margin-right:6px"></span>Importing…</span>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($existing_tables)): ?>
    <div class="card">
        <div class="card-hdr" style="justify-content:space-between">
            📋 Existing Tables
            <a href="view-tables.php" class="btn btn-ghost btn-xs">Browse Data →</a>
        </div>
        <div class="card-body" style="padding:12px">
            <div style="display:flex;flex-wrap:wrap;gap:6px">
                <?php foreach ($existing_tables as $t): ?>
                <a href="explorer.php?table=<?= urlencode($t) ?>" class="badge b-gray" style="font-size:11px;text-decoration:none;padding:4px 10px">
                    📋 <?= htmlspecialchars($t) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function onFileSelect(input) {
    const el = document.getElementById('file-chosen');
    el.textContent = input.files[0]?.name || '';
}
document.getElementById('import-form').addEventListener('submit', function() {
    document.getElementById('submit-btn').disabled = true;
    document.getElementById('importing-msg').style.display = 'inline-flex';
});
const dz = document.getElementById('drop-zone');
dz.addEventListener('dragover', e=>{ e.preventDefault(); dz.classList.add('drag-over'); });
dz.addEventListener('dragleave', ()=>dz.classList.remove('drag-over'));
dz.addEventListener('drop', e=>{
    e.preventDefault(); dz.classList.remove('drag-over');
    const f = e.dataTransfer.files[0];
    if (f && f.name.endsWith('.csv')) {
        const inp = document.getElementById('csv-file');
        const dt = new DataTransfer(); dt.items.add(f); inp.files = dt.files;
        document.getElementById('file-chosen').textContent = f.name;
    }
});
</script>
<?php page_footer(); ?>
