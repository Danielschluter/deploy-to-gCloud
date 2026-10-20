<?php
require 'db.php';
require 'includes/layout.php';

$pdo = get_db();
$message = '';
$message_type = '';

// Fetch all tables
$all_tables = $pdo->query("
    SELECT table_name FROM information_schema.tables
    WHERE table_schema = 'public' AND table_name NOT LIKE '_sqlclient_%'
    ORDER BY table_name
")->fetchAll(PDO::FETCH_COLUMN);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_post = $_POST['action'] ?? '';
    $tname = $_POST['table_name'] ?? '';

    if ($tname && in_array($tname, $all_tables)) {
        try {
            if ($action_post === 'truncate_table') {
                $pdo->exec("TRUNCATE TABLE \"$tname\" RESTART IDENTITY CASCADE");
                $message = "Table '$tname' cleared.";
                $message_type = 'success';
            } elseif ($action_post === 'drop_table') {
                $pdo->exec("DROP TABLE \"$tname\"");
                $message = "Table '$tname' dropped.";
                $message_type = 'success';
                $all_tables = array_diff($all_tables, [$tname]);
            } elseif ($action_post === 'delete_row') {
                $row_id = $_POST['row_id'] ?? '';
                if ($row_id) {
                    $pdo->prepare("DELETE FROM \"$tname\" WHERE id=?")->execute([$row_id]);
                    $message = 'Row deleted.';
                    $message_type = 'success';
                }
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Load selected table
$selected = '';
$columns = [];
$table_data = [];
$row_count = 0;
$total_pages = 1;
$page = 1;
$per_page = 50;
$pk_col = '';
$sort_col = '';
$sort_dir = 'ASC';

if (isset($_GET['table']) && in_array($_GET['table'], $all_tables)) {
    $selected = $_GET['table'];
    try {
        $columns = $pdo->query("
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_name='$selected' AND table_schema='public'
            ORDER BY ordinal_position
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Find ALL primary key columns (supports composite PKs)
        $pk_stmt = $pdo->prepare("
            SELECT kcu.column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema    = kcu.table_schema
            WHERE tc.constraint_type = 'PRIMARY KEY'
              AND tc.table_schema    = 'public'
              AND tc.table_name      = ?
            ORDER BY kcu.ordinal_position
        ");
        $pk_stmt->execute([$selected]);
        $pk_cols = $pk_stmt->fetchAll(PDO::FETCH_COLUMN); // e.g. ['cik','frame','tag']
        $pk_col  = $pk_cols[0] ?? '';                     // first PK col (for legacy refs)

        $page       = max(1, (int)($_GET['page'] ?? 1));
        $valid_cols = array_column($columns, 'column_name');

        // Multi-column sort: msort=col1:ASC,col2:DESC
        // Also accept legacy single sort/dir params
        $msort_raw = $_GET['msort'] ?? '';
        if (!$msort_raw && !empty($_GET['sort'])) {
            $d = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
            $msort_raw = $_GET['sort'] . ':' . $d;
        }

        $sort_specs = [];   // ordered map: col => dir
        foreach (explode(',', $msort_raw) as $part) {
            $part = trim($part);
            if (!$part) continue;
            [$sc, $sd] = array_pad(explode(':', $part, 2), 2, 'ASC');
            $sd = strtoupper($sd) === 'DESC' ? 'DESC' : 'ASC';
            if (in_array($sc, $valid_cols, true) && !array_key_exists($sc, $sort_specs)) {
                $sort_specs[$sc] = $sd;
            }
        }

        $order_parts = [];
        foreach ($sort_specs as $sc => $sd) { $order_parts[] = "\"$sc\" $sd"; }
        $order    = $order_parts ? 'ORDER BY ' . implode(', ', $order_parts) : 'ORDER BY 1';
        $msort_str = implode(',', array_map(fn($c,$d) => "$c:$d", array_keys($sort_specs), array_values($sort_specs)));

        // Legacy single-sort vars (used in a few places)
        $sort_col = array_key_first($sort_specs) ?? '';
        $sort_dir = $sort_specs[$sort_col] ?? 'ASC';

        // Search / filter
        $search_col  = $_GET['scol'] ?? '';
        $search_val  = $_GET['sval'] ?? '';
        $search_op   = $_GET['sop']  ?? 'ilike';
        $where       = '';
        $bind_params = [];

        $valid_ops   = ['ilike', 'eq', 'neq', 'gt', 'lt', 'is_null', 'not_null'];
        if ($search_col && in_array($search_col, $valid_cols, true) && in_array($search_op, $valid_ops, true)) {
            if ($search_op === 'is_null') {
                $where = "WHERE \"$search_col\" IS NULL";
            } elseif ($search_op === 'not_null') {
                $where = "WHERE \"$search_col\" IS NOT NULL";
            } elseif ($search_val !== '') {
                switch ($search_op) {
                    case 'ilike': $where = "WHERE \"$search_col\"::TEXT ILIKE ?"; $bind_params[] = '%' . $search_val . '%'; break;
                    case 'eq':    $where = "WHERE \"$search_col\"::TEXT = ?";     $bind_params[] = $search_val; break;
                    case 'neq':   $where = "WHERE \"$search_col\"::TEXT != ?";    $bind_params[] = $search_val; break;
                    case 'gt':    $where = "WHERE \"$search_col\"::TEXT > ?";     $bind_params[] = $search_val; break;
                    case 'lt':    $where = "WHERE \"$search_col\"::TEXT < ?";     $bind_params[] = $search_val; break;
                }
            }
        }

        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM \"$selected\" $where");
        $cnt_stmt->execute($bind_params);
        $row_count   = (int)$cnt_stmt->fetchColumn();
        $total_pages = max(1, (int)ceil($row_count / $per_page));
        $offset      = ($page - 1) * $per_page;

        $data_stmt = $pdo->prepare("SELECT * FROM \"$selected\" $where $order LIMIT $per_page OFFSET $offset");
        $data_stmt->execute($bind_params);
        $table_data = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'error';
        $selected = '';
    }
}

if ($selected) {
    echo "<script>window.PGCLIENT_TABLE=" . json_encode($selected) . ";window.PGCLIENT_SCHEMA='public';</script>\n";
}

page_header('Table Data', 'tables');
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Fira+Sans:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&family=Merriweather:ital,opsz,wght@0,18..144,300..900;1,18..144,300..900&family=Noto+Sans:ital,wght@0,100..900;1,100..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap');
#page-body{padding:0!important;overflow:hidden!important}
.vt-layout{display:flex;height:calc(100vh - 48px);overflow:hidden}
.vt-side{width:200px;flex-shrink:0;border-right:1px solid var(--border);background:var(--sidebar);display:flex;flex-direction:column;overflow:hidden}
.vt-main{flex:1;display:flex;flex-direction:column;overflow:hidden}

.vt-side-hdr{padding:10px 12px;border-bottom:1px solid var(--border);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text3);display:flex;align-items:center;justify-content:space-between}
.vt-tbl-list{flex:1;overflow-y:auto;padding:6px}
.vt-tbl-list::-webkit-scrollbar{width:3px}
.vt-tbl-list::-webkit-scrollbar-thumb{background:var(--border2)}
.vt-item{display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:5px;color:var(--text2);font-size:12px;text-decoration:none;margin-bottom:1px;transition:all .12s}
.vt-item:hover{background:var(--panel);color:var(--text);text-decoration:none}
.vt-item.active{background:var(--accent-bg);color:var(--accent)}

.vt-toolbar{padding:8px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;background:var(--bg2);flex-shrink:0}
.vt-data{flex:1;overflow:auto}
.vt-data::-webkit-scrollbar{width:6px;height:6px}
.vt-data::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
.vt-data table{width:100%;border-collapse:collapse;font-size:12px}
.vt-data table th{background:var(--panel2);padding:7px 12px;text-align:left;font-size:11px;color:var(--text2);border-bottom:1px solid var(--border);white-space:nowrap;position:sticky;top:0;z-index:1;font-weight:600}
.vt-data table th a{color:var(--text2);text-decoration:none}
.vt-data table th a:hover{color:var(--accent)}
.vt-data table td{padding:7px 12px;border-bottom:1px solid var(--border);color:var(--text);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;position:relative}
.vt-data table tr:hover td{background:rgba(255,255,255,.025)}
.vt-data .null-v{color:var(--text3);font-style:italic}

/* Editable cells */
.vt-data td.editable{cursor:text}
.vt-data td.editable:hover::after{content:'✎';position:absolute;right:5px;top:50%;transform:translateY(-50%);font-size:9px;color:var(--text3);opacity:.7}
.vt-data td.editing{padding:0!important;overflow:visible!important;background:var(--accent-bg)!important;outline:2px solid var(--accent);outline-offset:-1px;z-index:2}
.cell-input{width:100%;height:100%;min-height:30px;padding:6px 10px;background:transparent;border:none;outline:none;color:var(--text);font-size:12px;font-family:inherit;box-sizing:border-box}
.vt-data td.flash-ok{animation:flashOk .7s ease-out}
.vt-data td.flash-err{animation:flashErr .9s ease-out}
@keyframes flashOk{0%{background:rgba(78,201,148,.35)}100%{background:transparent}}
@keyframes flashErr{0%,20%{background:rgba(240,120,120,.35)}100%{background:transparent}}

/* Edit mode badge */
.edit-badge{font-size:10px;padding:2px 7px;border-radius:4px;background:var(--accent-bg);color:var(--accent);font-weight:600;display:none}
.edit-mode-on .edit-badge{display:inline-block}

/* Context menu */
#cell-ctx{position:fixed;background:var(--panel2);border:1px solid var(--border2);border-radius:6px;padding:4px;z-index:9999;box-shadow:0 6px 20px rgba(0,0,0,.5);display:none;min-width:140px}
#cell-ctx button{display:block;width:100%;text-align:left;background:none;border:none;color:var(--text2);padding:6px 10px;font-size:12px;cursor:pointer;border-radius:4px}
#cell-ctx button:hover{background:var(--accent-bg);color:var(--accent)}

.vt-footer{padding:8px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:8px;background:var(--bg2);flex-shrink:0;font-size:11px;color:var(--text3)}

.alert-bar{padding:10px 16px;font-size:12px;border-bottom:1px solid var(--border);flex-shrink:0}
.alert-bar.success{background:var(--green-bg);color:var(--green)}
.alert-bar.error{background:var(--red-bg);color:var(--red)}

/* Edit hint bar */
.edit-hint{padding:5px 14px;border-bottom:1px solid var(--border);background:rgba(77,166,255,.07);font-size:11px;color:var(--text3);display:flex;align-items:center;gap:10px;flex-shrink:0}

/* Search bar */
.vt-search{padding:7px 12px;border-bottom:1px solid var(--border);background:var(--bg2);display:flex;align-items:center;gap:6px;flex-shrink:0;flex-wrap:wrap}
.vt-search select,.vt-search input{background:var(--panel2);border:1px solid var(--border2);color:var(--text);border-radius:5px;padding:4px 8px;font-size:12px;outline:none}
.vt-search select:focus,.vt-search input:focus{border-color:var(--accent)}
.vt-search input{flex:1;min-width:120px;max-width:280px}
.search-active-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(77,166,255,.15);border:1px solid rgba(77,166,255,.3);color:var(--accent);font-size:11px;padding:2px 8px;border-radius:4px;font-weight:600}
.vt-data table th.col-filtered a{color:var(--accent)!important}

/* Multi-sort column headers */
.vt-data table th.sortable{cursor:pointer;user-select:none}
.vt-data table th.sortable:hover{background:var(--panel2);color:var(--text)}
.vt-data table th.sort-active{color:var(--accent)!important}
.sort-badge{display:inline-flex;align-items:center;gap:2px;margin-left:4px;font-size:9px;background:var(--accent-bg);color:var(--accent);border-radius:3px;padding:0 4px;font-weight:700;vertical-align:middle}
.sort-hint{padding:4px 14px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);font-size:10.5px;color:var(--text3);flex-shrink:0}
.sort-chips{display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap;margin-left:4px}
.sort-chip{display:inline-flex;align-items:center;gap:3px;background:var(--panel2);border:1px solid var(--border2);border-radius:4px;padding:1px 6px;font-size:11px;color:var(--text2)}
.sort-chip span{color:var(--accent);font-weight:700}
.sort-chip button{background:none;border:none;color:var(--text3);cursor:pointer;padding:0 1px;font-size:11px;line-height:1}
.sort-chip button:hover{color:var(--red)}
</style>

<div class="vt-layout">
    <!-- Table list sidebar -->
    <div class="vt-side">
        <div class="vt-side-hdr">
            Tables
            <a href="csv-import.php" title="Import CSV" style="color:var(--text3);text-decoration:none;font-size:14px">+</a>
        </div>
        <div class="vt-tbl-list">
            <?php if (empty($all_tables)): ?>
                <div style="padding:12px;color:var(--text3);font-size:11px;text-align:center">
                    No tables yet.<br><a href="csv-import.php" style="color:var(--accent)">Import CSV →</a>
                </div>
            <?php else: ?>
                <?php foreach ($all_tables as $t): ?>
                <a href="?table=<?= urlencode($t) ?>" class="vt-item <?= $selected === $t ? 'active' : '' ?>">
                    <span style="font-size:11px">📋</span>
                    <span class="truncate"><?= htmlspecialchars($t) ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="vt-main" id="vt-main">
        <?php if ($message): ?>
        <div class="alert-bar <?= $message_type ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <?php if ($selected): ?>
        <!-- Toolbar -->
        <div class="vt-toolbar">
            <strong style="font-size:13px"><?= htmlspecialchars($selected) ?></strong>
            <?php if ($where): ?>
            <span class="search-active-badge">
                🔍 <?= number_format($row_count) ?> match<?= $row_count !== 1 ? 'es' : '' ?>
                <a href="?table=<?= urlencode($selected) ?>" style="color:var(--text3);text-decoration:none;margin-left:2px" title="Clear filter">✕</a>
            </span>
            <?php else: ?>
            <span class="badge b-blue"><?= number_format($row_count) ?> rows</span>
            <?php endif; ?>
            <?php if ($pk_col): ?>
            <span class="edit-badge" id="edit-badge">✎ Editing</span>
            <?php endif; ?>
            <div style="margin-left:auto;display:flex;gap:6px">
                <?php if ($pk_col): ?>
                <button class="btn btn-ghost btn-sm" id="edit-toggle" onclick="toggleEditMode()" title="Toggle cell editing">✎ Edit</button>
                <?php endif; ?>
                <button class="btn btn-success btn-sm" onclick="openInsertModal()">＋ Insert Row</button>
                <a href="explorer.php?table=<?= urlencode($selected) ?>" class="btn btn-ghost btn-sm">🔍 Structure</a>
                <button class="btn btn-ghost btn-sm" onclick="editorQuery()">⚡ SQL Editor</button>
                <button class="btn btn-ghost btn-sm" onclick="exportCSV()">↓ CSV</button>
                <button class="btn btn-ghost btn-sm" style="color:var(--yellow)" onclick="showTruncate()">🗑 Clear</button>
                <button class="btn btn-danger btn-sm" onclick="showDrop()">✕ Drop</button>
            </div>
        </div>

        <!-- Search / filter bar -->
        <form class="vt-search" method="GET" action="">
            <input type="hidden" name="table" value="<?= htmlspecialchars($selected) ?>">
            <select name="scol" id="scol" onchange="updateOpVisibility()">
                <option value="">— column —</option>
                <?php foreach ($columns as $col): ?>
                <option value="<?= htmlspecialchars($col['column_name']) ?>"
                    <?= $search_col === $col['column_name'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($col['column_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="sop" id="sop" onchange="updateOpVisibility()">
                <option value="ilike" <?= $search_op==='ilike'   ? 'selected':'' ?>>contains</option>
                <option value="eq"    <?= $search_op==='eq'      ? 'selected':'' ?>>=  equals</option>
                <option value="neq"   <?= $search_op==='neq'     ? 'selected':'' ?>>≠  not equals</option>
                <option value="gt"    <?= $search_op==='gt'      ? 'selected':'' ?>>›  greater than</option>
                <option value="lt"    <?= $search_op==='lt'      ? 'selected':'' ?>>‹  less than</option>
                <option value="is_null"  <?= $search_op==='is_null'  ? 'selected':'' ?>>is NULL</option>
                <option value="not_null" <?= $search_op==='not_null' ? 'selected':'' ?>>is not NULL</option>
            </select>
            <input type="text" name="sval" id="sval" placeholder="search value…"
                   value="<?= htmlspecialchars($search_val) ?>"
                   <?= in_array($search_op, ['is_null','not_null']) ? 'style="display:none"' : '' ?>>
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <?php if ($where): ?>
            <a href="?table=<?= urlencode($selected) ?>" class="btn btn-ghost btn-sm">✕ Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($pk_col): ?>
        <div class="edit-hint" id="edit-hint" style="display:none">
            <span>✎ <strong>Edit mode:</strong> double-click any cell to edit &nbsp;·&nbsp; <kbd style="background:var(--panel2);padding:1px 5px;border-radius:3px;font-size:10px">Enter</kbd> save &nbsp;·&nbsp; <kbd style="background:var(--panel2);padding:1px 5px;border-radius:3px;font-size:10px">Esc</kbd> cancel &nbsp;·&nbsp; right-click to set NULL</span>
            <button onclick="toggleEditMode()" style="margin-left:auto;background:none;border:none;color:var(--text3);cursor:pointer;font-size:11px">✕ Exit</button>
        </div>
        <?php endif; ?>

        <!-- Data grid -->
        <div class="vt-data">
            <?php if (!empty($table_data)): ?>
            <table id="data-table">
                <thead>
                    <tr>
                        <th style="width:36px;text-align:center;color:var(--text3)">#</th>
                        <?php
                        $sort_keys = array_keys($sort_specs);
                        foreach ($columns as $col):
                            $cname    = $col['column_name'];
                            $pos      = array_search($cname, $sort_keys, true); // false or 0-based index
                            $is_sorted = $pos !== false;
                            $cdir     = $is_sorted ? $sort_specs[$cname] : '';
                            $classes  = [];
                            if ($search_col === $cname) $classes[] = 'col-filtered';
                            $classes[] = 'sortable';
                            if ($is_sorted) $classes[] = 'sort-active';
                        ?>
                        <th class="<?= implode(' ', $classes) ?>"
                            data-col="<?= htmlspecialchars($cname) ?>">
                            <?= htmlspecialchars($cname) ?>
                            <?php if ($is_sorted): ?>
                            <span class="sort-badge"><?= $pos + 1 ?><?= $cdir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </th>
                        <?php endforeach; ?>
                        <th style="width:70px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_data as $i => $row):
                        // Build composite PK map: {"cik":1,"frame":"CY2025","tag":"SomeTag"}
                        $pk_map = [];
                        foreach ($pk_cols as $pkc) { $pk_map[$pkc] = $row[$pkc] ?? null; }
                        $pk_json = json_encode($pk_map, JSON_UNESCAPED_UNICODE);
                    ?>
                    <tr data-pk="<?= htmlspecialchars($pk_json) ?>">
                        <td style="color:var(--text3);font-size:10px;text-align:center;background:rgba(255,255,255,.015)"><?= ($page - 1) * $per_page + $i + 1 ?></td>
                        <?php foreach ($columns as $col):
                            $val     = $row[$col['column_name']] ?? null;
                            $is_null = !array_key_exists($col['column_name'], $row) || $row[$col['column_name']] === null;
                            $display = $is_null ? '' : (strlen((string)$val) > 200 ? substr($val, 0, 200) . '…' : $val);
                            $is_pk   = in_array($col['column_name'], $pk_cols, true);
                        ?>
                        <td class="<?= $is_null ? 'null-v' : '' ?><?= (!empty($pk_cols) && !$is_pk) ? ' editable' : '' ?>"
                            data-col="<?= htmlspecialchars($col['column_name']) ?>"
                            data-raw="<?= htmlspecialchars($is_null ? "\x00NULL" : (string)$val) ?>"
                            title="<?= htmlspecialchars($is_null ? 'NULL' : (string)$val) ?>">
                            <?= $is_null ? 'NULL' : htmlspecialchars($display) ?>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <?php if (!empty($pk_cols)): ?>
                            <button onclick="deleteRowPk(<?= htmlspecialchars(json_encode($pk_map)) ?>)" class="btn btn-danger btn-xs">Del</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-st" style="padding:40px">
                <div class="empty-st-ico"><?= $where ? '🔍' : '📭' ?></div>
                <h3><?= $where ? 'No matches' : 'No data' ?></h3>
                <?php if ($where): ?>
                <p style="font-size:12px">No rows match your filter.</p>
                <a href="?table=<?= urlencode($selected) ?>" class="btn btn-ghost btn-sm" style="margin-top:12px">✕ Clear filter</a>
                <?php else: ?>
                <p style="font-size:12px">This table is empty.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer / pagination -->
        <?php
        $sp   = '&scol=' . urlencode($search_col) . '&sop=' . urlencode($search_op) . '&sval=' . urlencode($search_val);
        $base = '?table=' . urlencode($selected) . '&msort=' . urlencode($msort_str) . $sp;
        ?>
        <div class="vt-footer">
            <?php if ($row_count > 0): ?>
            <span>Showing <?= number_format(($page-1)*$per_page+1) ?>–<?= number_format(min($page*$per_page, $row_count)) ?> of <?= number_format($row_count) ?><?= $where ? ' (filtered)' : '' ?></span>
            <?php else: ?>
            <span>No rows<?= $where ? ' matching filter' : '' ?></span>
            <?php endif; ?>
            <div style="margin-left:auto;display:flex;gap:4px">
                <?php if ($page > 1): ?>
                    <a href="<?= $base ?>&page=1" class="btn btn-ghost btn-xs">«</a>
                    <a href="<?= $base ?>&page=<?= $page-1 ?>" class="btn btn-ghost btn-xs">‹</a>
                <?php endif; ?>
                <span style="padding:3px 10px;color:var(--text2);font-size:11px">Page <?= $page ?> / <?= $total_pages ?></span>
                <?php if ($page < $total_pages): ?>
                    <a href="<?= $base ?>&page=<?= $page+1 ?>" class="btn btn-ghost btn-xs">›</a>
                    <a href="<?= $base ?>&page=<?= $total_pages ?>" class="btn btn-ghost btn-xs">»</a>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <div class="empty-st" style="padding:60px">
            <div class="empty-st-ico">📋</div>
            <h3>Select a table</h3>
            <p style="font-size:12px;margin-top:6px">Choose a table from the list on the left to view its data.</p>
            <?php if (empty($all_tables)): ?>
            <a href="csv-import.php" class="btn btn-primary" style="margin-top:16px">↑ Import a CSV to create tables</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Context menu for NULL -->
<div id="cell-ctx">
    <button id="ctx-null-btn">Set to NULL</button>
    <button id="ctx-copy-btn">Copy value</button>
</div>

<?php if ($selected): ?>

<!-- Insert Row Modal -->
<div class="modal-ov" id="m-insert">
    <div class="modal" style="width:520px">
        <h3>＋ Insert Row into <span style="color:var(--accent)"><?= htmlspecialchars($selected) ?></span></h3>
        <div id="insert-form" style="max-height:65vh;overflow-y:auto;margin-bottom:16px;padding-right:4px">
            <!-- filled by JS -->
        </div>
        <div id="insert-result" style="display:none;font-size:12px;padding:8px 12px;border-radius:5px;margin-bottom:12px"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn btn-ghost" onclick="closeM('m-insert')">Cancel</button>
            <button class="btn btn-primary" onclick="doInsertRow()">Insert Row</button>
        </div>
    </div>
</div>

<!-- Truncate / Drop modals -->
<div class="modal-ov" id="m-truncate">
    <div class="modal">
        <h3 style="color:var(--yellow)">⚠️ Clear Table</h3>
        <p style="color:var(--text2);font-size:13px;margin:12px 0">This will delete ALL rows from <strong><?= htmlspecialchars($selected) ?></strong>. This cannot be undone.</p>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn btn-ghost" onclick="closeM('m-truncate')">Cancel</button>
            <button class="btn btn-danger" onclick="doAction('truncate_table')">Clear Table</button>
        </div>
    </div>
</div>
<div class="modal-ov" id="m-drop">
    <div class="modal">
        <h3 style="color:var(--red)">⚠️ Drop Table</h3>
        <p style="color:var(--text2);font-size:13px;margin:12px 0">This will permanently delete the table <strong><?= htmlspecialchars($selected) ?></strong> and all its data.</p>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn btn-ghost" onclick="closeM('m-drop')">Cancel</button>
            <button class="btn btn-danger" onclick="doAction('drop_table')">Drop Table</button>
        </div>
    </div>
</div>
<script>
const SEL_TABLE  = <?= json_encode($selected) ?>;
const PK_COL     = <?= json_encode($pk_col) ?>;
const PK_COLS    = <?= json_encode($pk_cols) ?>;
const SEARCH_QS  = <?= json_encode('scol=' . urlencode($search_col) . '&sop=' . urlencode($search_op) . '&sval=' . urlencode($search_val)) ?>;
const TABLE_COLS = <?= json_encode(array_map(fn($c) => [
    'name'     => $c['column_name'],
    'type'     => $c['data_type'],
    'nullable' => $c['is_nullable'] === 'YES',
    'default'  => $c['column_default'],
], $columns)) ?>;
let editMode     = false;

// ── Multi-column sort ────────────────────────────────────────
// sortSpecs: [{col, dir}, ...] in priority order
let sortSpecs = <?= json_encode(array_map(fn($c,$d) => ['col'=>$c,'dir'=>$d], array_keys($sort_specs), array_values($sort_specs))) ?>;

function buildMsort(specs) {
    return specs.map(s => s.col + ':' + s.dir).join(',');
}

function navigateSort(specs) {
    const ms  = encodeURIComponent(buildMsort(specs));
    const url = `?table=${encodeURIComponent(SEL_TABLE)}&msort=${ms}&page=1&${SEARCH_QS}`;
    location.href = url;
}

document.querySelectorAll('th.sortable').forEach(th => {
    th.addEventListener('click', function(e) {
        const col = this.dataset.col;
        if (e.shiftKey) {
            // Shift+click: add/toggle in secondary position
            const idx = sortSpecs.findIndex(s => s.col === col);
            if (idx === -1) {
                sortSpecs.push({ col, dir: 'ASC' });
            } else {
                if (sortSpecs[idx].dir === 'ASC') sortSpecs[idx].dir = 'DESC';
                else sortSpecs.splice(idx, 1);   // third click = remove
            }
        } else {
            // Plain click: set as sole sort, toggle dir if already primary
            const cur = sortSpecs.find(s => s.col === col);
            const newDir = (cur && sortSpecs[0].col === col && cur.dir === 'ASC') ? 'DESC' : 'ASC';
            sortSpecs = [{ col, dir: newDir }];
        }
        navigateSort(sortSpecs);
    });
});

// ── Search bar: hide value input for IS NULL / NOT NULL ──────
function updateOpVisibility() {
    const op  = document.getElementById('sop')?.value;
    const inp = document.getElementById('sval');
    if (!inp) return;
    const noInput = op === 'is_null' || op === 'not_null';
    inp.style.display = noInput ? 'none' : '';
    if (noInput) inp.value = '';
}
// Focus search value on column select
document.getElementById('scol')?.addEventListener('change', function() {
    setTimeout(() => document.getElementById('sval')?.focus(), 50);
});
// Submit on Enter in search input
document.getElementById('sval')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); this.closest('form').submit(); }
});
let activeCell  = null;   // td currently being edited
let ctxTarget   = null;   // td right-clicked

// ── Edit mode toggle ─────────────────────────────────────────
function toggleEditMode() {
    editMode = !editMode;
    const btn   = document.getElementById('edit-toggle');
    const badge = document.getElementById('edit-badge');
    const hint  = document.getElementById('edit-hint');
    const main  = document.getElementById('vt-main');
    if (editMode) {
        btn && (btn.style.color = 'var(--accent)');
        badge && badge.parentElement.classList.add('edit-mode-on');
        hint  && (hint.style.display = 'flex');
        main  && main.classList.add('edit-mode-on');
    } else {
        commitActiveCell(false);
        btn && (btn.style.color = '');
        badge && badge.parentElement.classList.remove('edit-mode-on');
        hint  && (hint.style.display = 'none');
        main  && main.classList.remove('edit-mode-on');
    }
}

// ── Double-click to start editing ───────────────────────────
document.addEventListener('dblclick', function(e) {
    const td = e.target.closest('td.editable');
    if (!td || !editMode) return;
    startEdit(td);
});

function startEdit(td) {
    if (activeCell && activeCell !== td) commitActiveCell(false);
    if (activeCell === td) return;

    activeCell = td;
    td.classList.add('editing');
    td.classList.remove('null-v');

    const raw = td.dataset.raw;
    const isNull = raw === '\x00NULL';
    const currentVal = isNull ? '' : raw;

    const inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'cell-input';
    inp.value = currentVal;
    inp.placeholder = isNull ? 'NULL' : '';

    inp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter')  { e.preventDefault(); commitActiveCell(true); }
        if (e.key === 'Escape') { e.preventDefault(); cancelEdit(td, true); }
        if (e.key === 'Tab')    { e.preventDefault(); commitActiveCell(true); navigateCell(td, e.shiftKey ? -1 : 1); }
    });
    inp.addEventListener('blur', function() {
        // Small delay so context-menu clicks register first
        setTimeout(() => { if (activeCell === td) commitActiveCell(true); }, 120);
    });

    td.innerHTML = '';
    td.appendChild(inp);
    inp.focus();
    inp.select();
}

function cancelEdit(td, restoreContent) {
    if (restoreContent) restoreCell(td);
    td.classList.remove('editing');
    if (activeCell === td) activeCell = null;
}

function restoreCell(td) {
    const raw = td.dataset.raw;
    const isNull = raw === '\x00NULL';
    td.innerHTML = isNull ? 'NULL' : escHtml(raw.length > 200 ? raw.slice(0,200)+'…' : raw);
    if (isNull) td.classList.add('null-v'); else td.classList.remove('null-v');
}

async function commitActiveCell(doSave) {
    if (!activeCell) return;
    const td  = activeCell;
    const inp = td.querySelector('input');
    if (!inp) { cancelEdit(td, true); return; }

    const newVal  = inp.value;
    const raw     = td.dataset.raw;
    const wasNull = raw === '\x00NULL';
    const unchanged = !wasNull && newVal === raw;

    activeCell = null;
    td.classList.remove('editing');

    if (!doSave || unchanged) { restoreCell(td); return; }

    const row   = td.closest('tr');
    const pkJson = row ? row.dataset.pk : '';
    const col    = td.dataset.col;

    // Optimistic render
    td.innerHTML = newVal === '' ? 'NULL' : escHtml(newVal);
    if (newVal === '') td.classList.add('null-v'); else td.classList.remove('null-v');

    try {
        const fd = new FormData();
        fd.append('table',    SEL_TABLE);
        fd.append('pk_json',  pkJson);
        fd.append('col',      col);
        fd.append('val',      newVal);
        fd.append('set_null', newVal === '' ? '1' : '0');

        const res = await fetch('api.php?action=update_cell', { method:'POST', body:fd });
        const json = await res.json();

        if (json.success) {
            td.dataset.raw = newVal === '' ? '\x00NULL' : newVal;
            flashCell(td, true);
        } else {
            // Revert
            restoreCell(td);
            flashCell(td, false);
            showToast('Error: ' + (json.error || 'Unknown error'), 'error');
        }
    } catch(err) {
        restoreCell(td);
        flashCell(td, false);
        showToast('Network error', 'error');
    }
}

async function setCellNull(td) {
    const row    = td.closest('tr');
    const pkJson = row ? row.dataset.pk : '';
    const col    = td.dataset.col;

    if (activeCell === td) { activeCell = null; td.classList.remove('editing'); }

    try {
        const fd = new FormData();
        fd.append('table',    SEL_TABLE);
        fd.append('pk_json',  pkJson);
        fd.append('col',      col);
        fd.append('val',      '');
        fd.append('set_null', '1');

        const res  = await fetch('api.php?action=update_cell', { method:'POST', body:fd });
        const json = await res.json();

        if (json.success) {
            td.dataset.raw = '\x00NULL';
            td.innerHTML = 'NULL';
            td.classList.add('null-v');
            flashCell(td, true);
        } else {
            flashCell(td, false);
            showToast('Error: ' + (json.error || 'Unknown'), 'error');
        }
    } catch(err) {
        flashCell(td, false);
        showToast('Network error', 'error');
    }
}

// Tab navigation between editable cells in the same row
function navigateCell(td, dir) {
    const cells = Array.from(td.closest('tr').querySelectorAll('td.editable'));
    const idx   = cells.indexOf(td);
    const next  = cells[idx + dir];
    if (next) startEdit(next);
}

// ── Context menu (right-click) ───────────────────────────────
const ctx = document.getElementById('cell-ctx');
document.addEventListener('contextmenu', function(e) {
    const td = e.target.closest('td.editable');
    if (!td || !editMode) return;
    e.preventDefault();
    ctxTarget = td;
    ctx.style.display = 'block';
    ctx.style.left    = Math.min(e.clientX, window.innerWidth  - 160) + 'px';
    ctx.style.top     = Math.min(e.clientY, window.innerHeight - 80)  + 'px';
});
document.addEventListener('click', () => { ctx.style.display = 'none'; });
document.getElementById('ctx-null-btn').addEventListener('click', () => {
    if (ctxTarget) setCellNull(ctxTarget);
});
document.getElementById('ctx-copy-btn').addEventListener('click', () => {
    if (!ctxTarget) return;
    const raw = ctxTarget.dataset.raw;
    navigator.clipboard.writeText(raw === '\x00NULL' ? '' : raw).catch(()=>{});
});

// ── Visual helpers ───────────────────────────────────────────
function flashCell(td, ok) {
    td.classList.remove('flash-ok','flash-err');
    void td.offsetWidth; // reflow to restart animation
    td.classList.add(ok ? 'flash-ok' : 'flash-err');
}

let toastTimer;
function showToast(msg, type) {
    let t = document.getElementById('cell-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'cell-toast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:10px 16px;border-radius:6px;font-size:12px;z-index:9999;transition:opacity .3s;pointer-events:none';
        document.body.appendChild(t);
    }
    t.textContent  = msg;
    t.style.background = type === 'error' ? 'var(--red-bg)' : 'var(--green-bg)';
    t.style.color      = type === 'error' ? 'var(--red)'    : 'var(--green)';
    t.style.border     = '1px solid ' + (type === 'error' ? 'var(--red)' : 'var(--green)');
    t.style.opacity    = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.style.opacity = '0', 3000);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Existing actions ──────────────────────────────────────────
function showTruncate(){ document.getElementById('m-truncate').classList.add('open'); }
function showDrop()    { document.getElementById('m-drop').classList.add('open'); }
function closeM(id)    { document.getElementById(id).classList.remove('open'); }
function doAction(act) {
    const f=document.createElement('form'); f.method='POST'; f.style.display='none';
    f.innerHTML=`<input name="action" value="${act}"><input name="table_name" value="${SEL_TABLE}">`;
    document.body.appendChild(f); f.submit();
}
async function deleteRowPk(pkMap) {
    if (!confirm('Delete this row?')) return;
    try {
        const fd = new FormData();
        fd.append('table',   SEL_TABLE);
        fd.append('schema',  'public');
        fd.append('pk_json', JSON.stringify(pkMap));
        const r = await fetch('api.php?action=delete_row_pk', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) location.reload();
        else showToast('Delete failed: ' + (d.error || 'Unknown error'), 'error');
    } catch(e) { showToast('Network error', 'error'); }
}
function editorQuery() { sessionStorage.setItem('pgclient_sql',`SELECT * FROM "${SEL_TABLE}" LIMIT 100;`); location.href='editor.php'; }
function exportCSV() {
    const f=document.createElement('form'); f.method='POST'; f.action='api.php?action=export'; f.style.display='none';
    f.innerHTML=`<input name="sql" value="SELECT * FROM &quot;${SEL_TABLE}&quot;"><input name="format" value="csv">`;
    document.body.appendChild(f); f.submit(); document.body.removeChild(f);
}

// ── Insert Row Modal ──────────────────────────────────────────
const AUTO_TYPES = ['nextval','now()','gen_random'];

function isAutoGenerated(col) {
    if (!col.default) return false;
    const d = col.default.toLowerCase();
    return AUTO_TYPES.some(t => d.includes(t));
}

function openInsertModal() {
    const form = document.getElementById('insert-form');
    const res  = document.getElementById('insert-result');
    res.style.display = 'none';
    form.innerHTML = '';

    const colsToShow = TABLE_COLS.filter(c => !isAutoGenerated(c));

    if (!colsToShow.length) {
        form.innerHTML = '<p style="color:var(--text3);text-align:center;padding:16px">All columns are auto-generated.</p>';
    } else {
        colsToShow.forEach(col => {
            const isNullable = col.nullable;
            const isPk = PK_COLS.includes(col.name);
            const typeHint = col.type;
            const wrap = document.createElement('div');
            wrap.style.cssText = 'margin-bottom:12px';
            wrap.innerHTML = `
                <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text3);display:block;margin-bottom:4px">
                    ${escHtml(col.name)}
                    <span style="font-weight:400;text-transform:none;letter-spacing:0;margin-left:4px;color:var(--text3)">${escHtml(typeHint)}</span>
                    ${isPk ? '<span style="color:var(--yellow);margin-left:4px" title="Primary key">🔑</span>' : ''}
                    ${!isNullable ? '<span style="color:var(--red);margin-left:3px" title="Required">*</span>' : ''}
                </label>
                <div style="display:flex;gap:6px;align-items:center">
                    <input type="text" data-col="${escHtml(col.name)}"
                           placeholder="${isNullable ? 'NULL or value…' : 'Required…'}"
                           style="background:var(--bg);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:5px;font-size:12.5px;font-family:inherit;flex:1;outline:none"
                           onfocus="this.style.borderColor='var(--accent)'"
                           onblur="this.style.borderColor='var(--border)'">
                    ${isNullable ? `<button type="button" title="Set NULL" onclick="setInsertNull(this)" style="background:var(--panel2);border:1px solid var(--border2);color:var(--text3);border-radius:4px;padding:6px 8px;cursor:pointer;font-size:11px;white-space:nowrap">NULL</button>` : ''}
                </div>`;
            form.appendChild(wrap);
        });
    }

    document.getElementById('m-insert').classList.add('open');
    form.querySelector('input[data-col]')?.focus();
}

function setInsertNull(btn) {
    const inp = btn.previousElementSibling;
    inp.value = '';
    inp.placeholder = 'NULL';
    inp.dataset.setNull = '1';
    btn.style.color = 'var(--accent)';
}

async function doInsertRow() {
    const form = document.getElementById('insert-form');
    const res  = document.getElementById('insert-result');
    const inputs = form.querySelectorAll('input[data-col]');
    const rowData = {};

    for (const inp of inputs) {
        const col = inp.dataset.col;
        const isNull = inp.dataset.setNull === '1' || inp.value === '';
        const colMeta = TABLE_COLS.find(c => c.name === col);
        if (isNull && colMeta && !colMeta.nullable && !PK_COLS.includes(col)) {
            res.style.display = 'block';
            res.style.background = 'var(--red-bg)';
            res.style.color = 'var(--red)';
            res.textContent = `Column "${col}" is required and cannot be NULL.`;
            return;
        }
        rowData[col] = (isNull && colMeta && colMeta.nullable) ? '__NULL__' : inp.value;
    }

    try {
        const fd = new FormData();
        fd.append('table',    SEL_TABLE);
        fd.append('schema',   'public');
        fd.append('row_data', JSON.stringify(rowData));
        const r = await fetch('api.php?action=insert_row', { method: 'POST', body: fd });
        const d = await r.json();
        res.style.display = 'block';
        if (d.success) {
            res.style.background = 'var(--green-bg)';
            res.style.color = 'var(--green)';
            res.textContent = '✓ Row inserted successfully!';
            setTimeout(() => { closeM('m-insert'); location.reload(); }, 800);
        } else {
            res.style.background = 'var(--red-bg)';
            res.style.color = 'var(--red)';
            res.textContent = '✕ ' + (d.error || 'Insert failed');
        }
    } catch(e) {
        res.style.display = 'block';
        res.style.background = 'var(--red-bg)';
        res.style.color = 'var(--red)';
        res.textContent = '✕ Network error: ' + e.message;
    }
}

document.querySelectorAll('.modal-ov').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
</script>
<?php endif; ?>
<?php page_footer(); ?>
