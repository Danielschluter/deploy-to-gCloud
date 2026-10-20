<?php
require 'includes/layout.php';

$table  = $_GET['table']  ?? '';
$schema = $_GET['schema'] ?? 'public';

if ($table) {
    echo "<script>window.PGCLIENT_TABLE=" . json_encode($table) . ";window.PGCLIENT_SCHEMA=" . json_encode($schema) . ";</script>\n";
}

page_header('Explorer', 'explorer');
?>
<style>
#page-body{padding:0!important;overflow:hidden!important}
.ex-layout{display:flex;height:calc(100vh - 48px);overflow:hidden}
.ex-main{flex:1;min-width:0;display:flex;flex-direction:column;overflow:hidden}

.ex-breadcrumb{padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-shrink:0;background:var(--bg2);min-height:44px}
.bc-sep{color:var(--text3)}

.tab-bar{display:flex;align-items:center;border-bottom:1px solid var(--border);padding:0 16px;background:var(--bg2);flex-shrink:0;height:36px;gap:0}
.tab-btn{padding:0 14px;height:36px;display:flex;align-items:center;font-size:12px;color:var(--text3);cursor:pointer;border-bottom:2px solid transparent;transition:all .14s}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-pn{flex:1;overflow:auto;display:none}
.tab-pn.active{display:block}
#tp-data.active{display:flex;flex-direction:column}
.tab-pn::-webkit-scrollbar{width:6px;height:6px}
.tab-pn::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}

.str-tbl{width:100%;border-collapse:collapse;font-size:12px}
.str-tbl th{background:var(--panel2);padding:8px 14px;text-align:left;font-size:10.5px;color:var(--text2);border-bottom:1px solid var(--border);font-weight:600;text-transform:uppercase;letter-spacing:.4px;position:sticky;top:0;z-index:1}
.str-tbl td{padding:8px 14px;border-bottom:1px solid var(--border);color:var(--text);vertical-align:middle}
.str-tbl tr:hover td{background:rgba(255,255,255,.02)}
.pk-tag{display:inline-flex;align-items:center;background:var(--yellow-bg);color:var(--yellow);font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;margin-right:5px}

.ddl-pre{font-family:'JetBrains Mono','Fira Code',monospace;font-size:12px;color:var(--text2);line-height:1.7;white-space:pre;background:var(--panel);padding:20px;margin:16px;border-radius:8px;border:1px solid var(--border);overflow:auto}

.data-toolbar{padding:8px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;background:var(--bg2);flex-shrink:0}
.data-body{overflow:auto;height:calc(100% - 46px)}
</style>

<div class="ex-layout">
    <div class="ex-main">
        <?php if ($table): ?>

        <div class="ex-breadcrumb">
            <span style="color:var(--text3);font-size:12px"><?= htmlspecialchars($schema) ?></span>
            <span class="bc-sep">/</span>
            <span style="font-weight:600;font-size:13px"><?= htmlspecialchars($table) ?></span>
            <span class="badge b-blue" id="row-badge" style="margin-left:4px"></span>
            <div style="margin-left:auto;display:flex;gap:8px">
                <button class="btn btn-ghost btn-sm" onclick="queryTable()">⚡ Query in Editor</button>
                <button class="btn btn-ghost btn-sm" onclick="exportCSV()">↓ Export CSV</button>
            </div>
        </div>

        <div class="tab-bar">
            <div class="tab-btn active" onclick="switchTab(this,'columns')">Columns</div>
            <div class="tab-btn" onclick="switchTab(this,'data')">Data</div>
            <div class="tab-btn" onclick="switchTab(this,'indexes')">Indexes</div>
            <div class="tab-btn" onclick="switchTab(this,'fkeys')">Foreign Keys</div>
            <div class="tab-btn" onclick="switchTab(this,'ddl')">DDL</div>
        </div>

        <div class="tab-pn active" id="tp-columns">
            <div id="col-wrap"><div style="padding:24px;text-align:center;color:var(--text3)"><div class="spinner" style="margin:0 auto 8px"></div>Loading…</div></div>
        </div>

        <div class="tab-pn" id="tp-data">
            <div class="data-toolbar">
                <input type="text" id="data-search" placeholder="Search all columns…" class="form-inp" style="padding:5px 10px;font-size:12px;width:220px" oninput="debounce(()=>loadData(1),350)">
                <select id="data-perpage" onchange="loadData(1)" class="form-inp" style="padding:5px 8px;font-size:12px;width:auto">
                    <option value="25">25 rows</option>
                    <option value="50" selected>50 rows</option>
                    <option value="100">100 rows</option>
                    <option value="250">250 rows</option>
                </select>
                <span id="data-count" style="color:var(--text3);font-size:11px"></span>
                <div style="margin-left:auto;display:flex;gap:5px" id="data-pages"></div>
            </div>
            <div class="data-body" id="data-body"><div style="padding:24px;color:var(--text3)">Click the Data tab to load data.</div></div>
        </div>

        <div class="tab-pn" id="tp-indexes">
            <div id="idx-wrap"><div style="padding:20px;color:var(--text3)"></div></div>
        </div>

        <div class="tab-pn" id="tp-fkeys">
            <div id="fk-wrap"><div style="padding:20px;color:var(--text3)"></div></div>
        </div>

        <div class="tab-pn" id="tp-ddl">
            <pre class="ddl-pre" id="ddl-wrap">Loading…</pre>
        </div>

        <?php else: ?>
        <div class="empty-st" style="padding:60px">
            <div class="empty-st-ico">🗂</div>
            <h3>Schema Explorer</h3>
            <p style="margin-top:8px;font-size:12px">Select a table from the schema tree on the left to explore its structure and data.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($table): ?>
<script>
const TABLE  = <?= json_encode($table) ?>;
const SCHEMA = <?= json_encode($schema) ?>;
let sortCol='', sortDir='ASC', curPage=1, stData=null, debTimer;

function debounce(fn, ms) { clearTimeout(debTimer); debTimer=setTimeout(fn, ms); }

async function loadStructure() {
    const r = await fetch(`api.php?action=table_structure&table=${enc(TABLE)}&schema=${enc(SCHEMA)}`);
    const d = await r.json();
    if (!d.success) {
        document.getElementById('col-wrap').innerHTML = `<div style="padding:20px;color:var(--red)">Error: ${esc(d.error)}</div>`;
        return;
    }
    stData = d;
    document.getElementById('row-badge').textContent = fmt(d.rowCount) + ' rows';
    renderCols(d.columns);
    renderIdxs(d.indexes);
    renderFKs(d.foreignKeys);
    document.getElementById('ddl-wrap').textContent = d.ddl;
}

function renderCols(cols) {
    if (!cols.length) { document.getElementById('col-wrap').innerHTML='<div style="padding:20px;color:var(--text3)">No columns</div>'; return; }
    const rows = cols.map(c => {
        const isPK = c.is_primary===true||c.is_primary==='t';
        let t = c.data_type;
        if (c.character_maximum_length) t += `(${c.character_maximum_length})`;
        else if (c.numeric_precision && c.data_type==='numeric') t += `(${c.numeric_precision},${c.numeric_scale||0})`;
        return `<tr>
            <td>
                ${isPK?'<span class="pk-tag">PK</span>':''}
                <strong style="color:${isPK?'var(--yellow)':'var(--text)'}">${esc(c.column_name)}</strong>
            </td>
            <td><code style="font-size:11px">${esc(t)}</code></td>
            <td>${c.is_nullable==='YES'?'<span style="color:var(--text3)">nullable</span>':'<span class="badge b-red">NOT NULL</span>'}</td>
            <td style="color:var(--text3);font-family:monospace;font-size:11px">${c.column_default!==null?esc(c.column_default):'—'}</td>
            <td style="color:var(--text3);font-size:11px">${esc(c.udt_name||c.data_type)}</td>
        </tr>`;
    }).join('');
    document.getElementById('col-wrap').innerHTML = `<table class="str-tbl"><thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Default</th><th>PG Type</th></tr></thead><tbody>${rows}</tbody></table>`;
}

function renderIdxs(idxs) {
    const el = document.getElementById('idx-wrap');
    if (!idxs||!idxs.length) { el.innerHTML='<div style="padding:20px;color:var(--text3)">No indexes found</div>'; return; }
    const rows = idxs.map(i=>`<tr>
        <td style="font-family:monospace;font-size:12px">${esc(i.name)}</td>
        <td>${i.isPrimary?'<span class="badge b-yellow">PRIMARY</span>':i.isUnique?'<span class="badge b-blue">UNIQUE</span>':'<span class="badge b-gray">INDEX</span>'}</td>
        <td style="font-family:monospace;font-size:11px;color:var(--text2)">${esc((i.columns||[]).join(', '))}</td>
    </tr>`).join('');
    el.innerHTML = `<table class="str-tbl"><thead><tr><th>Index Name</th><th>Type</th><th>Columns</th></tr></thead><tbody>${rows}</tbody></table>`;
}

function renderFKs(fks) {
    const el = document.getElementById('fk-wrap');
    if (!fks||!fks.length) { el.innerHTML='<div style="padding:20px;color:var(--text3)">No foreign keys found</div>'; return; }
    const rows = fks.map(f=>`<tr>
        <td style="font-family:monospace;font-size:11px">${esc(f.constraint_name)}</td>
        <td style="color:var(--accent)">${esc(f.column_name)}</td>
        <td style="color:var(--text3)">→</td>
        <td><a href="explorer.php?table=${enc(f.foreign_table)}&schema=${enc(SCHEMA)}" style="color:var(--accent)">${esc(f.foreign_table)}</a></td>
        <td style="color:var(--text2)">${esc(f.foreign_column)}</td>
        <td style="font-size:11px;color:var(--text3)">DELETE ${esc(f.delete_rule)}</td>
    </tr>`).join('');
    el.innerHTML = `<table class="str-tbl"><thead><tr><th>Constraint</th><th>Column</th><th></th><th>References</th><th>Column</th><th>Rules</th></tr></thead><tbody>${rows}</tbody></table>`;
}

async function loadData(page) {
    curPage = page || 1;
    const search   = document.getElementById('data-search')?.value||'';
    const perPage  = document.getElementById('data-perpage')?.value||50;
    document.getElementById('data-body').innerHTML = '<div style="padding:24px;text-align:center;color:var(--text3)"><div class="spinner" style="margin:0 auto 8px"></div>Loading…</div>';

    const p = new URLSearchParams({action:'table_data',table:TABLE,schema:SCHEMA,page:curPage,per_page:perPage,sort_col:sortCol,sort_dir:sortDir,search});
    const r = await fetch('api.php?'+p);
    const d = await r.json();

    if (!d.success) { document.getElementById('data-body').innerHTML=`<div style="padding:20px;color:var(--red)">Error: ${esc(d.error)}</div>`; return; }

    document.getElementById('data-count').textContent = `${d.total.toLocaleString()} rows`;
    renderPages(d.page, d.totalPages);
    renderDataTable(d.columns, d.rows, parseInt(perPage));
}

function renderDataTable(cols, rows, perPage) {
    if (!rows.length) { document.getElementById('data-body').innerHTML='<div style="padding:30px;text-align:center;color:var(--text3)">No data found</div>'; return; }
    const hdr = cols.map(c=>`<th onclick="sortBy('${esc(c.column_name)}')" style="cursor:pointer;user-select:none" title="Sort by ${esc(c.column_name)}">${esc(c.column_name)}${sortCol===c.column_name?' '+(sortDir==='ASC'?'↑':'↓'):''}</th>`).join('');
    const bdy = rows.map((row,i)=>{
        const cells = cols.map(c=>{
            const v = row[c.column_name];
            if (v===null) return `<td class="null-v">NULL</td>`;
            const s = String(v);
            return `<td title="${esc(s)}">${esc(s.length>150?s.slice(0,150)+'…':s)}</td>`;
        }).join('');
        return `<tr><td style="color:var(--text3);font-size:10px;text-align:center;background:rgba(255,255,255,.015)">${(curPage-1)*perPage+i+1}</td>${cells}</tr>`;
    }).join('');
    document.getElementById('data-body').innerHTML = `
        <table class="data-tbl" style="font-size:12px">
            <thead><tr><th style="width:36px">#</th>${hdr}</tr></thead>
            <tbody>${bdy}</tbody>
        </table>`;
}

function renderPages(page, total) {
    const el = document.getElementById('data-pages');
    if (total<=1) { el.innerHTML=''; return; }
    let h = '';
    if (page>1) h+=`<button class="btn btn-ghost btn-sm" onclick="loadData(${page-1})">‹ Prev</button>`;
    h+=`<span style="color:var(--text3);font-size:11px;padding:0 8px">Page ${page} / ${total}</span>`;
    if (page<total) h+=`<button class="btn btn-ghost btn-sm" onclick="loadData(${page+1})">Next ›</button>`;
    el.innerHTML = h;
}

function sortBy(col) {
    sortDir = sortCol===col ? (sortDir==='ASC'?'DESC':'ASC') : 'ASC';
    sortCol = col;
    loadData(1);
}

function switchTab(tab, id) {
    document.querySelectorAll('.tab-btn').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-pn').forEach(p=>p.classList.remove('active'));
    tab.classList.add('active');
    const panel = document.getElementById('tp-'+id);
    panel.classList.add('active');
    if (id==='data' && !document.getElementById('data-body').querySelector('table')) loadData(1);
}

function queryTable() { sessionStorage.setItem('pgclient_sql', `SELECT *\nFROM "${SCHEMA}"."${TABLE}"\nLIMIT 100;`); location.href='editor.php'; }

function exportCSV() {
    const f=document.createElement('form'); f.method='POST'; f.action='api.php?action=export'; f.style.display='none';
    f.innerHTML=`<input name="sql" value="SELECT * FROM &quot;${SCHEMA}&quot;.&quot;${TABLE}&quot;"><input name="format" value="csv">`;
    document.body.appendChild(f); f.submit(); document.body.removeChild(f);
}

function esc(s){const d=document.createElement('div');d.textContent=String(s===null||s===undefined?'':s);return d.innerHTML}
function enc(s){return encodeURIComponent(s)}
function fmt(n){n=parseInt(n)||0;if(n<0)return'?';if(n>1e6)return(n/1e6).toFixed(1)+'M';if(n>1e3)return Math.round(n/1e3)+'k';return n.toLocaleString()}

loadStructure();
</script>
<?php endif; ?>
<?php page_footer(); ?>
