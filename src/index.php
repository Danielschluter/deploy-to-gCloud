<?php
require 'includes/layout.php';
page_header('Dashboard', 'dashboard');
?>
<div id="dash-loading" style="text-align:center;padding:48px;color:var(--text3)">
    <div class="spinner" style="margin:0 auto 12px;width:24px;height:24px;border-width:3px"></div>
    Loading database statistics…
</div>

<div id="dash-content" style="display:none">
    <div class="grid g4" id="stat-cards" style="margin-bottom:20px"></div>

    <div class="grid g2" style="margin-bottom:20px">
        <div class="card">
            <div class="card-hdr">📊 Largest Tables</div>
            <div class="tbl-wrap" style="border-radius:0 0 8px 8px;border:none;border-top:1px solid var(--border)">
                <table class="data-tbl" id="tbl-large">
                    <thead><tr><th>Table</th><th>Total Size</th><th>Row Estimate</th></tr></thead>
                    <tbody id="tbl-large-body"></tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-hdr" style="justify-content:space-between">
                🕐 Recent Queries
                <a href="editor.php" class="btn btn-ghost btn-xs">Open Editor →</a>
            </div>
            <div id="recent-queries" style="overflow-y:auto;max-height:290px">
                <div style="padding:16px;color:var(--text3);text-align:center"><div class="spinner" style="margin:0 auto"></div></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:20px">
        <div class="card-hdr">ℹ️ Connection Info</div>
        <div class="card-body">
            <div class="grid g4" id="conn-info"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-hdr">⚡ Quick Actions</div>
        <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="editor.php" class="btn btn-primary">New SQL Query</a>
            <a href="explorer.php" class="btn btn-ghost">Browse Tables</a>
            <a href="csv-import.php" class="btn btn-ghost">↑ Import CSV</a>
            <button onclick="quick('SELECT current_database(), version(), now()')" class="btn btn-ghost">DB Info</button>
            <button onclick="quick('SELECT tablename, pg_size_pretty(pg_total_relation_size(schemaname||\'.\' ||tablename)) as size FROM pg_tables WHERE schemaname=\'public\' ORDER BY pg_total_relation_size(schemaname||\'.\'||tablename) DESC')" class="btn btn-ghost">Table Sizes</button>
            <button onclick="quick('SELECT pid, state, query, now()-query_start as duration FROM pg_stat_activity WHERE state IS NOT NULL ORDER BY duration DESC LIMIT 20')" class="btn btn-ghost">Active Queries</button>
        </div>
    </div>
</div>

<script>
async function loadDashboard() {
    try {
        const [sr, hr] = await Promise.all([fetch('api.php?action=db_stats'), fetch('api.php?action=query_history&limit=10')]);
        const [s, h] = await Promise.all([sr.json(), hr.json()]);
        document.getElementById('dash-loading').style.display = 'none';
        document.getElementById('dash-content').style.display = 'block';
        if (s.success) { renderStats(s); renderLarge(s.largeTables||[]); renderConn(s); }
        if (h.success) renderRecent(h.history||[]);
    } catch(e) {
        document.getElementById('dash-loading').innerHTML = `<div style="color:var(--red)">Failed: ${esc(e.message)}</div>`;
    }
}

function renderStats(s) {
    const cards = [
        {val:s.tableCount, lbl:'Tables',             ico:'📋', col:'var(--accent)'},
        {val:s.viewCount,  lbl:'Views',              ico:'👁',  col:'var(--purple)'},
        {val:s.size,       lbl:'Database Size',      ico:'💾', col:'var(--green)'},
        {val:s.activeConnections, lbl:'Active Connections', ico:'🔗', col:'var(--yellow)'},
    ];
    document.getElementById('stat-cards').innerHTML = cards.map(c=>`
        <div class="stat-card">
            <div class="stat-icon">${c.ico}</div>
            <div class="stat-val" style="color:${c.col}">${c.val}</div>
            <div class="stat-lbl">${c.lbl}</div>
        </div>`).join('');
}

function renderLarge(tables) {
    const b = document.getElementById('tbl-large-body');
    if (!tables.length) { b.innerHTML='<tr><td colspan="3" style="text-align:center;padding:20px;color:var(--text3)">No tables found</td></tr>'; return; }
    b.innerHTML = tables.map(t=>`<tr>
        <td><a href="explorer.php?table=${enc(t.table_name)}" style="color:var(--accent)">${esc(t.table_name)}</a></td>
        <td style="color:var(--text2)">${esc(t.total_size)}</td>
        <td style="color:var(--purple);font-family:monospace">${fmt(t.row_estimate)}</td>
    </tr>`).join('');
}

function renderRecent(history) {
    const el = document.getElementById('recent-queries');
    if (!history.length) {
        el.innerHTML = `<div style="padding:20px;color:var(--text3);text-align:center;font-size:12px">No queries yet. <a href="editor.php" style="color:var(--accent)">Open editor →</a></div>`;
        return;
    }
    el.innerHTML = history.map(h=>`
        <div onclick="quick(${JSON.stringify(h.sql_text)})"
             style="padding:10px 14px;border-bottom:1px solid var(--border);cursor:pointer"
             onmouseenter="this.style.background='rgba(255,255,255,.025)'"
             onmouseleave="this.style.background=''">
            <div style="font-family:monospace;font-size:11px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px">${esc(h.sql_text)}</div>
            <div style="display:flex;gap:8px;align-items:center;font-size:10px">
                <span class="badge ${h.status==='error'?'b-red':'b-green'}">${h.status}</span>
                <span style="color:var(--text3)">${h.execution_time}ms</span>
                <span style="color:var(--text3)">${h.row_count} rows</span>
                <span style="color:var(--text3);margin-left:auto">${fmtDate(h.executed_at)}</span>
            </div>
        </div>`).join('');
}

function renderConn(s) {
    document.getElementById('conn-info').innerHTML = `
        <div>
            <div class="stat-lbl" style="margin-bottom:4px">Version</div>
            <div style="font-size:12px">${esc(s.version)}</div>
        </div>
        <div>
            <div class="stat-lbl" style="margin-bottom:4px">Database</div>
            <code style="font-size:12px">${esc(s.database)}</code>
        </div>
        <div>
            <div class="stat-lbl" style="margin-bottom:4px">Queries Logged</div>
            <div style="font-size:12px">${s.historyCount.toLocaleString()}</div>
        </div>
        <div>
            <div class="stat-lbl" style="margin-bottom:4px">Server Started</div>
            <div style="font-size:11px;color:var(--text2)">${s.startTime?new Date(s.startTime).toLocaleString():'—'}</div>
        </div>`;
}

function quick(sql) { sessionStorage.setItem('pgclient_sql',sql); location.href='editor.php'; }
function esc(s){const d=document.createElement('div');d.textContent=String(s??'');return d.innerHTML}
function enc(s){return encodeURIComponent(s)}
function fmt(n){n=parseInt(n)||0;if(n<0)return'—';if(n>1e6)return(n/1e6).toFixed(1)+'M';if(n>1e3)return Math.round(n/1e3)+'k';return n.toLocaleString()}
function fmtDate(d){if(!d)return'';const dt=new Date(d),diff=Date.now()-dt;if(diff<6e4)return'just now';if(diff<36e5)return Math.floor(diff/6e4)+'m ago';if(diff<864e5)return Math.floor(diff/36e5)+'h ago';return dt.toLocaleDateString()}

loadDashboard();
</script>
<?php page_footer(); ?>
