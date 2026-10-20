<?php
require 'includes/layout.php';

$cfg   = pgclient_load_config();
$conns = $cfg['connections'] ?? [];
$active_conn = pgclient_get_active_connection();

page_header('Connections', 'connections');
?>
<style>
.conn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:24px}
.conn-card{background:var(--panel);border:1px solid var(--border);border-radius:8px;overflow:hidden;transition:border-color .15s}
.conn-card.active-conn{border-color:var(--accent)}
.conn-card-hdr{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.conn-dot{width:9px;height:9px;border-radius:50%;background:var(--border2);flex-shrink:0}
.conn-dot.online{background:var(--green)}
.conn-info{flex:1;min-width:0}
.conn-name{font-weight:600;font-size:13px;color:var(--text);display:flex;align-items:center;gap:6px}
.conn-sub{font-size:11px;color:var(--text3);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.conn-body{padding:14px 16px}
.conn-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
.conn-meta-item{font-size:11px}
.conn-meta-item .lbl{color:var(--text3);font-size:10px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px}
.conn-meta-item .val{color:var(--text2);font-family:monospace;font-size:11.5px}
.conn-actions{display:flex;gap:6px}
.add-card{background:transparent;border:2px dashed var(--border2);border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;min-height:160px;cursor:pointer;color:var(--text3);transition:all .15s;text-decoration:none}
.add-card:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-bg);text-decoration:none}
.add-card-ico{font-size:28px}
.add-card-lbl{font-size:12px;font-weight:600}

/* Modal */
.modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;align-items:center;justify-content:center}
.modal-ov.open{display:flex}
.modal{background:var(--panel);border:1px solid var(--border2);border-radius:10px;padding:0;width:480px;max-width:94vw;overflow:hidden}
.modal-hdr{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-hdr h3{font-size:14px;font-weight:600;color:var(--text)}
.modal-body{padding:20px}
.modal-foot{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end}
.form-group{margin-bottom:14px}
.form-lbl{font-size:10.5px;color:var(--text3);margin-bottom:5px;display:block;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.form-inp{background:var(--bg);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:5px;font-size:12.5px;font-family:inherit;width:100%}
.form-inp:focus{outline:none;border-color:var(--accent)}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.g3{display:grid;grid-template-columns:2fr 1fr;gap:12px}
.test-bar{font-size:12px;padding:8px 12px;border-radius:5px;margin-top:10px;display:none}
.test-bar.ok{background:var(--green-bg);color:var(--green)}
.test-bar.fail{background:var(--red-bg);color:var(--red)}
.cb-wrap{display:flex;align-items:center;gap:8px;padding:6px 0}
.cb-wrap input{width:14px;height:14px;accent-color:var(--accent);cursor:pointer}
.cb-wrap label{font-size:12.5px;color:var(--text2);cursor:pointer}
</style>

<div class="page-actions" style="display:none"></div>

<div class="conn-grid" id="conn-grid">
<?php foreach ($conns as $conn):
    $is_active = ($conn['id'] === ($active_conn['id'] ?? ''));
?>
<div class="conn-card <?= $is_active ? 'active-conn' : '' ?>" id="card-<?= htmlspecialchars($conn['id']) ?>">
    <div class="conn-card-hdr">
        <div class="conn-dot <?= $is_active ? 'online' : '' ?>"></div>
        <div class="conn-info">
            <div class="conn-name">
                <?= htmlspecialchars($conn['name']) ?>
                <?php if ($is_active): ?><span class="badge b-green" style="font-size:9px">Active</span><?php endif; ?>
                <?php if (!empty($conn['is_default'])): ?><span class="badge b-gray" style="font-size:9px">Default</span><?php endif; ?>
            </div>
            <div class="conn-sub"><?= htmlspecialchars($conn['host'] . ':' . ($conn['port'] ?? 5432)) ?></div>
        </div>
    </div>
    <div class="conn-body">
        <div class="conn-meta">
            <div class="conn-meta-item">
                <div class="lbl">Database</div>
                <div class="val"><?= htmlspecialchars($conn['database']) ?></div>
            </div>
            <div class="conn-meta-item">
                <div class="lbl">Username</div>
                <div class="val"><?= htmlspecialchars($conn['username']) ?></div>
            </div>
            <div class="conn-meta-item">
                <div class="lbl">Port</div>
                <div class="val"><?= htmlspecialchars($conn['port'] ?? 5432) ?></div>
            </div>
            <div class="conn-meta-item">
                <div class="lbl">SSL</div>
                <div class="val"><?= !empty($conn['ssl']) ? '✓ Yes' : 'No' ?></div>
            </div>
        </div>
        <div class="conn-actions">
            <?php if (!$is_active): ?>
            <button class="btn btn-primary btn-sm" onclick="switchConn('<?= htmlspecialchars($conn['id']) ?>')">⚡ Use This</button>
            <?php endif; ?>
            <button class="btn btn-ghost btn-sm" onclick="editConn(<?= htmlspecialchars(json_encode($conn)) ?>)">✎ Edit</button>
            <button class="btn btn-ghost btn-sm" onclick="testConnId('<?= htmlspecialchars($conn['id']) ?>', this)">↻ Test</button>
            <?php if (!$is_active): ?>
            <button class="btn btn-danger btn-sm" onclick="deleteConn('<?= htmlspecialchars($conn['id']) ?>', '<?= htmlspecialchars($conn['name']) ?>')">✕</button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<a href="#" class="add-card" onclick="openAddModal();return false">
    <div class="add-card-ico">＋</div>
    <div class="add-card-lbl">Add Connection</div>
</a>
</div>

<!-- Add/Edit Modal -->
<div class="modal-ov" id="conn-modal">
    <div class="modal">
        <div class="modal-hdr">
            <h3 id="modal-title">Add Connection</h3>
            <button onclick="closeModal()" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:16px">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="edit-id">
            <div class="form-group">
                <label class="form-lbl">Connection Name</label>
                <input class="form-inp" type="text" id="f-name" placeholder="e.g. Production DB">
            </div>
            <div class="g3">
                <div class="form-group">
                    <label class="form-lbl">Host</label>
                    <input class="form-inp" type="text" id="f-host" placeholder="localhost">
                </div>
                <div class="form-group">
                    <label class="form-lbl">Port</label>
                    <input class="form-inp" type="number" id="f-port" placeholder="5432" value="5432">
                </div>
            </div>
            <div class="form-group">
                <label class="form-lbl">Database Name</label>
                <input class="form-inp" type="text" id="f-database" placeholder="postgres">
            </div>
            <div class="g2">
                <div class="form-group">
                    <label class="form-lbl">Username</label>
                    <input class="form-inp" type="text" id="f-username" placeholder="postgres" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-lbl">Password</label>
                    <input class="form-inp" type="password" id="f-password" placeholder="(leave blank to keep)" autocomplete="new-password">
                </div>
            </div>
            <div class="cb-wrap">
                <input type="checkbox" id="f-ssl">
                <label for="f-ssl">Require SSL</label>
            </div>
            <div class="cb-wrap">
                <input type="checkbox" id="f-default">
                <label for="f-default">Set as default connection</label>
            </div>
            <div id="modal-test-bar" class="test-bar"></div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost btn-sm" onclick="testModalConn()">↻ Test Connection</button>
            <button class="btn btn-ghost btn-sm" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary btn-sm" onclick="saveConn()">Save Connection</button>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modal-title').textContent = 'Add Connection';
    document.getElementById('edit-id').value = '';
    document.getElementById('f-name').value = '';
    document.getElementById('f-host').value = 'localhost';
    document.getElementById('f-port').value = '5432';
    document.getElementById('f-database').value = '';
    document.getElementById('f-username').value = '';
    document.getElementById('f-password').value = '';
    document.getElementById('f-ssl').checked = false;
    document.getElementById('f-default').checked = false;
    document.getElementById('modal-test-bar').style.display = 'none';
    document.getElementById('conn-modal').classList.add('open');
}

function editConn(conn) {
    document.getElementById('modal-title').textContent = 'Edit Connection';
    document.getElementById('edit-id').value = conn.id;
    document.getElementById('f-name').value = conn.name || '';
    document.getElementById('f-host').value = conn.host || '';
    document.getElementById('f-port').value = conn.port || 5432;
    document.getElementById('f-database').value = conn.database || '';
    document.getElementById('f-username').value = conn.username || '';
    document.getElementById('f-password').value = '';
    document.getElementById('f-ssl').checked = !!conn.ssl;
    document.getElementById('f-default').checked = !!conn.is_default;
    document.getElementById('modal-test-bar').style.display = 'none';
    document.getElementById('conn-modal').classList.add('open');
}

function closeModal() {
    document.getElementById('conn-modal').classList.remove('open');
}

async function saveConn() {
    const id = document.getElementById('edit-id').value;
    const fd = new FormData();
    fd.append('action', id ? 'update_connection' : 'add_connection');
    if (id) fd.append('id', id);
    fd.append('name',     document.getElementById('f-name').value);
    fd.append('host',     document.getElementById('f-host').value);
    fd.append('port',     document.getElementById('f-port').value);
    fd.append('database', document.getElementById('f-database').value);
    fd.append('username', document.getElementById('f-username').value);
    fd.append('password', document.getElementById('f-password').value);
    fd.append('ssl',      document.getElementById('f-ssl').checked ? '1' : '0');
    fd.append('is_default', document.getElementById('f-default').checked ? '1' : '0');

    const r = await fetch('api.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) { closeModal(); location.reload(); }
    else alert('Error: ' + d.error);
}

async function testModalConn() {
    const bar = document.getElementById('modal-test-bar');
    bar.style.display = 'none';
    const fd = new FormData();
    fd.append('action',   'test_connection');
    fd.append('host',     document.getElementById('f-host').value);
    fd.append('port',     document.getElementById('f-port').value);
    fd.append('database', document.getElementById('f-database').value);
    fd.append('username', document.getElementById('f-username').value);
    fd.append('password', document.getElementById('f-password').value);
    fd.append('ssl',      document.getElementById('f-ssl').checked ? '1' : '0');
    try {
        const r = await fetch('api.php', { method: 'POST', body: fd });
        const d = await r.json();
        bar.style.display = 'block';
        bar.className = 'test-bar ' + (d.success ? 'ok' : 'fail');
        bar.textContent = d.success ? '✓ Connected! ' + (d.version||'') : '✕ ' + (d.error||'Failed');
    } catch(e) {
        bar.style.display = 'block';
        bar.className = 'test-bar fail';
        bar.textContent = '✕ ' + e.message;
    }
}

async function testConnId(id, btn) {
    const orig = btn.textContent;
    btn.textContent = 'Testing…';
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'test_connection_by_id');
    fd.append('id', id);
    const r = await fetch('api.php', { method: 'POST', body: fd });
    const d = await r.json();
    btn.textContent = d.success ? '✓ OK' : '✕ Fail';
    btn.style.color = d.success ? 'var(--green)' : 'var(--red)';
    setTimeout(() => { btn.textContent = orig; btn.disabled = false; btn.style.color=''; }, 2500);
}

async function switchConn(id) {
    const fd = new FormData();
    fd.append('action', 'set_active_connection');
    fd.append('id', id);
    const r = await fetch('api.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) location.reload();
    else alert('Error: ' + d.error);
}

async function deleteConn(id, name) {
    if (!confirm('Delete connection "' + name + '"?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_connection');
    fd.append('id', id);
    const r = await fetch('api.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) { document.getElementById('card-' + id)?.remove(); }
    else alert('Error: ' + d.error);
}

document.getElementById('conn-modal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});
</script>
<?php page_footer(); ?>
