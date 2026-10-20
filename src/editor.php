<?php
require 'includes/layout.php';
page_header('SQL Editor', 'editor');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/show-hint.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/show-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/sql-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/comment/comment.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/display/autorefresh.min.js"></script>
<style>
#page-body{padding:0!important;overflow:hidden!important}
.ed-layout{display:flex;height:calc(100vh - 48px);overflow:hidden}
.ed-main{flex:1;min-width:0;display:flex;flex-direction:column;overflow:hidden}
.ed-side{width:255px;flex-shrink:0;border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;background:var(--sidebar)}

/* Toolbar */
.ed-toolbar{padding:7px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:6px;background:var(--bg2);flex-shrink:0}
.toolbar-sep{width:1px;height:20px;background:var(--border);margin:0 3px}

/* CodeMirror */
.ed-cm{flex:1;overflow:hidden;position:relative;min-height:0}
.CodeMirror{height:100%!important;font-size:13px!important;font-family:'JetBrains Mono','Fira Code','Cascadia Code',Menlo,monospace!important;line-height:1.6!important}
.CodeMirror-scroll{height:100%!important}

/* Results panel */
.ed-results{height:270px;flex-shrink:0;border-top:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
.res-tabs{display:flex;align-items:center;border-bottom:1px solid var(--border);padding:0 10px;background:var(--bg2);flex-shrink:0;height:34px;gap:0}
.res-tab{padding:0 12px;height:34px;display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--text3);cursor:pointer;border-bottom:2px solid transparent;transition:all .14s;white-space:nowrap}
.res-tab:hover{color:var(--text)}
.res-tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.res-tab .tb{background:var(--accent);color:#fff;font-size:9px;padding:1px 5px;border-radius:7px}
.tab-pn{flex:1;overflow:auto;display:none}
.tab-pn.active{display:block}
.tab-pn::-webkit-scrollbar{width:5px;height:5px}
.tab-pn::-webkit-scrollbar-thumb{background:var(--border2)}

/* Results table */
.res-tbl{width:100%;border-collapse:collapse;font-size:12px;font-family:'JetBrains Mono','Fira Code',monospace}
.res-tbl th{background:var(--panel2);padding:5px 12px;text-align:left;font-size:10px;color:var(--text3);border-bottom:1px solid var(--border);border-right:1px solid var(--border);white-space:nowrap;position:sticky;top:0;z-index:1;font-weight:600;text-transform:uppercase;letter-spacing:.4px;user-select:none}
.res-tbl th:first-child{width:36px;text-align:center;color:var(--text3)}
.res-tbl td{padding:5px 12px;border-bottom:1px solid rgba(255,255,255,.04);border-right:1px solid rgba(255,255,255,.03);color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:420px}
.res-tbl td:first-child{color:var(--text3);text-align:center;font-size:10px;background:rgba(255,255,255,.015)}
.res-tbl tr:hover td{background:rgba(255,255,255,.025)}
.is-null{color:var(--text3)!important;font-style:italic}
.is-num{color:var(--purple)!important}
.is-bool{color:var(--yellow)!important}
.is-date{color:var(--cyan)!important}

/* Status bar */
.ed-status{height:23px;border-top:1px solid var(--border);display:flex;align-items:center;padding:0 12px;gap:14px;font-size:10.5px;color:var(--text3);background:var(--bg2);flex-shrink:0}
.st-ok{color:var(--green)}
.st-err{color:var(--red)}

/* Sidebar */
.side-tabs{display:flex;border-bottom:1px solid var(--border);flex-shrink:0}
.side-tab{flex:1;text-align:center;padding:8px 4px;font-size:11px;color:var(--text3);cursor:pointer;border-bottom:2px solid transparent;transition:all .14s}
.side-tab:hover{color:var(--text)}
.side-tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.side-pn{flex:1;overflow-y:auto;display:none}
.side-pn.active{display:block}
.side-pn::-webkit-scrollbar{width:3px}
.side-pn::-webkit-scrollbar-thumb{background:var(--border2)}
.hist-item{padding:8px 12px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s}
.hist-item:hover{background:var(--panel)}
.hist-sql{font-family:monospace;font-size:11px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px}
.hist-meta{display:flex;gap:8px;font-size:10px;color:var(--text3)}
.saved-item{padding:8px 12px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:6px}
.saved-item:hover{background:var(--panel)}
.saved-name{font-size:12px;font-weight:600;color:var(--text);margin-bottom:2px;cursor:pointer}
.saved-name:hover{color:var(--accent)}
.saved-sql{font-family:monospace;font-size:10px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer}
</style>

<div class="ed-layout">
    <div class="ed-main">
        <!-- Toolbar -->
        <div class="ed-toolbar">
            <button class="btn btn-success btn-sm" id="btn-run" onclick="runQuery()">
                ▶ Run <span style="opacity:.6;font-size:10px">(Ctrl+↵)</span>
            </button>
            <div class="toolbar-sep"></div>
            <button class="btn btn-ghost btn-sm" onclick="clearEditor()">✕ Clear</button>
            <button class="btn btn-ghost btn-sm" onclick="fmtSQL()">⬡ Format</button>
            <div class="toolbar-sep"></div>
            <button class="btn btn-ghost btn-sm" onclick="showSaveModal()">💾 Save</button>
            <div style="margin-left:auto;display:flex;gap:5px">
                <button class="btn btn-ghost btn-sm" id="btn-csv" onclick="exportRes('csv')" disabled>↓ CSV</button>
                <button class="btn btn-ghost btn-sm" id="btn-json" onclick="exportRes('json')" disabled>↓ JSON</button>
            </div>
        </div>

        <!-- Editor -->
        <div class="ed-cm">
            <textarea id="sql-ta" placeholder="-- Write SQL here… (Ctrl+Enter to run, Ctrl+/ to comment)"></textarea>
        </div>

        <!-- Results -->
        <div class="ed-results">
            <div class="res-tabs">
                <div class="res-tab active" data-t="results" onclick="switchTab(this)">
                    Results <span class="tb" id="res-cnt" style="display:none"></span>
                </div>
                <div class="res-tab" data-t="messages" onclick="switchTab(this)">Messages</div>
                <div style="margin-left:auto;font-size:11px;color:var(--text3);padding-right:4px" id="res-info"></div>
            </div>
            <div class="tab-pn active" id="tp-results">
                <div class="empty-st" style="padding:24px">
                    <div style="font-size:28px;margin-bottom:8px">⚡</div>
                    <div style="font-size:12px">Run a query to see results here</div>
                </div>
            </div>
            <div class="tab-pn" id="tp-messages">
                <div id="msg-body" style="padding:12px;font-family:monospace;font-size:12px;color:var(--text2)"></div>
            </div>
        </div>

        <!-- Status bar -->
        <div class="ed-status">
            <span id="st-main" style="color:var(--text3)">Ready</span>
            <span style="margin-left:auto" id="st-time"></span>
            <span id="st-rows"></span>
            <span>PostgreSQL</span>
        </div>
    </div>

    <!-- Right sidebar -->
    <div class="ed-side">
        <div class="side-tabs">
            <div class="side-tab active" onclick="switchSide(this,'history')">History</div>
            <div class="side-tab" onclick="switchSide(this,'saved')">Saved</div>
        </div>
        <div class="side-pn active" id="sp-history">
            <div style="padding:7px;border-bottom:1px solid var(--border);display:flex;gap:5px">
                <input type="text" placeholder="Search…" class="form-inp" style="flex:1;padding:4px 8px;font-size:11px" oninput="filterHist(this.value)">
                <button class="btn btn-ghost btn-xs" onclick="clearHistory()" title="Clear history">✕</button>
            </div>
            <div id="hist-list"></div>
        </div>
        <div class="side-pn" id="sp-saved">
            <div style="padding:7px;border-bottom:1px solid var(--border)">
                <input type="text" placeholder="Search…" class="form-inp" style="width:100%;padding:4px 8px;font-size:11px" oninput="filterSaved(this.value)">
            </div>
            <div id="saved-list"></div>
        </div>
    </div>
</div>

<!-- Save modal -->
<div class="modal-ov" id="save-modal">
    <div class="modal">
        <h3>💾 Save Query</h3>
        <div class="form-group">
            <label class="form-lbl">Name *</label>
            <input id="sv-name" type="text" class="form-inp" placeholder="My query name…">
        </div>
        <div class="form-group">
            <label class="form-lbl">Description (optional)</label>
            <input id="sv-desc" type="text" class="form-inp" placeholder="What does this do?">
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
            <button class="btn btn-ghost" onclick="hideSave()">Cancel</button>
            <button class="btn btn-primary" onclick="doSave()">Save</button>
        </div>
    </div>
</div>

<script>
let editor, histData=[], savedData=[], lastSQL='';

document.addEventListener('DOMContentLoaded', () => {
    editor = CodeMirror.fromTextArea(document.getElementById('sql-ta'), {
        mode: 'text/x-pgsql',
        theme: 'dracula',
        lineNumbers: true,
        matchBrackets: true,
        autoRefresh: true,
        lineWrapping: false,
        tabSize: 2,
        indentWithTabs: false,
        extraKeys: {
            'Ctrl-Enter': runQuery,
            'Cmd-Enter': runQuery,
            'Ctrl-/': cm => cm.execCommand('toggleComment'),
            'Ctrl-Space': 'autocomplete'
        },
        hintOptions: { completeSingle: false }
    });

    const saved = sessionStorage.getItem('pgclient_sql');
    if (saved) { editor.setValue(saved); sessionStorage.removeItem('pgclient_sql'); }

    loadHist();
    loadSaved();

    fetch('api.php?action=autocomplete').then(r=>r.json()).then(d=>{
        if (d.success) {
            const tbl = {};
            d.tables.forEach(t => tbl[t] = d.columns);
            editor.setOption('hintOptions', { tables: tbl, completeSingle: false });
        }
    });

    document.getElementById('save-modal').addEventListener('click', e=>{ if(e.target===e.currentTarget) hideSave(); });
});

async function runQuery() {
    const sql = editor.getSelection().trim() || editor.getValue().trim();
    if (!sql) return;
    lastSQL = sql;
    setSt('', '⏳ Executing…');
    document.getElementById('btn-run').disabled = true;
    document.getElementById('btn-csv').disabled = true;
    document.getElementById('btn-json').disabled = true;

    try {
        const fd = new FormData(); fd.append('sql', sql);
        const res = await fetch('api.php?action=execute', { method:'POST', body:fd });
        const d = await res.json();
        renderResults(d);
        loadHist();
    } catch(e) {
        setMsg(`<span style="color:var(--red)">Network error: ${esc(e.message)}</span>`);
        setSt('err', '❌ Network error');
        switchTabId('messages');
    } finally {
        document.getElementById('btn-run').disabled = false;
    }
}

function renderResults(d) {
    const cnt = document.getElementById('res-cnt');
    const info = document.getElementById('res-info');

    if (!d.success) {
        cnt.style.display = 'none';
        setSt('err', '❌ ' + d.error.split('\n')[0]);
        info.innerHTML = `<span style="color:var(--red)">Error in ${d.time}ms</span>`;
        document.getElementById('tp-results').innerHTML = `
            <div style="padding:16px">
                <div style="color:var(--red);font-family:monospace;font-size:12px;background:var(--red-bg);padding:14px;border-radius:6px;border-left:3px solid var(--red);line-height:1.6">
                    <strong>ERROR:</strong> ${esc(d.error)}
                    ${d.failedStatement ? `<div style="margin-top:8px;color:var(--text3);font-size:11px">Statement: ${esc(d.failedStatement)}</div>` : ''}
                </div>
            </div>`;
        switchTabId('results');
        setMsg(`<span style="color:var(--red)">ERROR: ${esc(d.error)}</span>`);
        return;
    }

    const results = d.results || [];
    const dataRes = results.find(r => r.hasData);
    const msgs = results.filter(r => !r.hasData).map(r => `<div style="color:var(--green);margin-bottom:3px">✓ ${esc(r.type)}: ${r.rowCount} row(s) affected</div>`);

    if (msgs.length) setMsg(msgs.join(''));

    if (dataRes) {
        const rc = dataRes.rows.length;
        cnt.textContent = rc; cnt.style.display = '';
        info.innerHTML = `<span style="color:var(--green)">✓ ${rc.toLocaleString()} row${rc!==1?'s':''} · ${d.time}ms</span>`;
        setSt('ok', `✓ ${rc.toLocaleString()} row${rc!==1?'s':''} returned in ${d.time}ms`);
        document.getElementById('btn-csv').disabled = false;
        document.getElementById('btn-json').disabled = false;
        document.getElementById('tp-results').innerHTML = buildTable(dataRes.columns, dataRes.rows);
        switchTabId('results');
    } else {
        cnt.style.display = 'none';
        const total = results.reduce((s,r)=>s+r.rowCount,0);
        info.innerHTML = `<span style="color:var(--green)">✓ ${total} affected · ${d.time}ms</span>`;
        setSt('ok', `✓ ${total} row(s) affected in ${d.time}ms`);
        document.getElementById('tp-results').innerHTML = `
            <div class="empty-st" style="padding:24px">
                <div style="color:var(--green);font-size:28px;margin-bottom:8px">✓</div>
                <div style="color:var(--text2)">Query executed successfully</div>
                <div style="color:var(--text3);font-size:11px;margin-top:4px">${total} row(s) affected · ${d.time}ms</div>
            </div>`;
        if (msgs.length) switchTabId('messages'); else switchTabId('results');
    }
}

function buildTable(cols, rows) {
    if (!cols||!cols.length) return '<div style="padding:16px;color:var(--text3)">No data</div>';
    const typeClass = t => {
        if (!t) return '';
        t = t.toLowerCase();
        if (/int|float|numeric|decimal|int4|int8|float4|float8/.test(t)) return 'is-num';
        if (/bool/.test(t)) return 'is-bool';
        if (/date|time|timestamp/.test(t)) return 'is-date';
        return '';
    };
    const hdr = cols.map((c,i)=>`<th title="${esc(c.type||'')}">${esc(c.name)}<span style="color:var(--text3);font-size:9px;font-weight:400;margin-left:4px">${esc(c.type||'')}</span></th>`).join('');
    const bdy = rows.map((row,ri)=>{
        const cells = row.map((v,ci)=>{
            if (v===null) return `<td class="is-null" title="NULL">NULL</td>`;
            const s = String(v);
            const tc = typeClass(cols[ci]?.type||'');
            return `<td class="${tc}" title="${esc(s)}">${esc(s.length>120?s.slice(0,120)+'…':s)}</td>`;
        }).join('');
        return `<tr><td>${ri+1}</td>${cells}</tr>`;
    }).join('');
    return `<div style="overflow:auto;height:100%"><table class="res-tbl"><thead><tr><th>#</th>${hdr}</tr></thead><tbody>${bdy}</tbody></table></div>`;
}

function setMsg(html) { document.getElementById('msg-body').innerHTML = html; }
function setSt(cls, text) {
    const el = document.getElementById('st-main');
    el.textContent = text;
    el.className = cls==='ok'?'st-ok':cls==='err'?'st-err':'';
}
function switchTab(tab) {
    document.querySelectorAll('.res-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-pn').forEach(p=>p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('tp-'+tab.dataset.t).classList.add('active');
}
function switchTabId(id) {
    const t = document.querySelector(`[data-t="${id}"]`);
    if (t) switchTab(t);
}
function switchSide(tab, id) {
    document.querySelectorAll('.side-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.side-pn').forEach(p=>p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('sp-'+id).classList.add('active');
}

async function loadHist() {
    const r = await fetch('api.php?action=query_history&limit=100');
    const d = await r.json();
    histData = d.history || [];
    renderHist(histData);
}
function renderHist(items) {
    const el = document.getElementById('hist-list');
    if (!items.length) { el.innerHTML='<div style="padding:16px;color:var(--text3);font-size:11px;text-align:center">No history</div>'; return; }
    el.innerHTML = items.map(h=>`
        <div class="hist-item" onclick="loadSQL(${JSON.stringify(h.sql_text)})">
            <div class="hist-sql">${esc(h.sql_text)}</div>
            <div class="hist-meta">
                <span class="${h.status==='error'?'text-error':'text-success'}">${h.status}</span>
                <span>${h.execution_time}ms</span>
                <span>${h.row_count} rows</span>
                <span style="margin-left:auto">${fmtDate(h.executed_at)}</span>
            </div>
        </div>`).join('');
}
function filterHist(q) { renderHist(q ? histData.filter(h=>h.sql_text.toLowerCase().includes(q.toLowerCase())) : histData); }
function loadSQL(sql) { editor.setValue(sql); editor.focus(); }
async function clearHistory() {
    if (!confirm('Clear all query history?')) return;
    await fetch('api.php?action=clear_history',{method:'POST'});
    histData=[]; renderHist([]);
}

async function loadSaved() {
    const r = await fetch('api.php?action=get_saved_queries');
    const d = await r.json();
    savedData = d.queries || [];
    renderSaved(savedData);
}
function renderSaved(items) {
    const el = document.getElementById('saved-list');
    if (!items.length) {
        el.innerHTML=`<div style="padding:16px;color:var(--text3);font-size:11px;text-align:center">No saved queries yet.<br><br><span style="color:var(--accent);cursor:pointer" onclick="showSaveModal()">Save current →</span></div>`;
        return;
    }
    el.innerHTML = items.map(q=>`
        <div class="saved-item">
            <div style="flex:1;min-width:0">
                <div class="saved-name" onclick="loadSQL(${JSON.stringify(q.sql_text)})">${esc(q.name)}</div>
                <div class="saved-sql" onclick="loadSQL(${JSON.stringify(q.sql_text)})">${esc(q.sql_text)}</div>
                ${q.description?`<div style="font-size:10px;color:var(--text3);margin-top:1px">${esc(q.description)}</div>`:''}
            </div>
            <button onclick="delSaved(${q.id})" class="btn btn-ghost btn-xs" style="color:var(--red);flex-shrink:0">✕</button>
        </div>`).join('');
}
function filterSaved(q) { renderSaved(q ? savedData.filter(s=>s.name.toLowerCase().includes(q.toLowerCase())||s.sql_text.toLowerCase().includes(q.toLowerCase())) : savedData); }
async function delSaved(id) {
    if (!confirm('Delete this saved query?')) return;
    const fd=new FormData(); fd.append('id',id);
    await fetch('api.php?action=delete_saved_query',{method:'POST',body:fd});
    loadSaved();
}

function showSaveModal() { document.getElementById('sv-name').value=''; document.getElementById('sv-desc').value=''; document.getElementById('save-modal').classList.add('open'); setTimeout(()=>document.getElementById('sv-name').focus(),60); }
function hideSave() { document.getElementById('save-modal').classList.remove('open'); }
async function doSave() {
    const name=document.getElementById('sv-name').value.trim();
    const sql=editor.getValue().trim();
    if (!name) { alert('Please enter a name'); return; }
    if (!sql) { alert('No SQL to save'); return; }
    const fd=new FormData();
    fd.append('name',name);
    fd.append('sql',sql);
    fd.append('description',document.getElementById('sv-desc').value.trim());
    const r=await fetch('api.php?action=save_query',{method:'POST',body:fd});
    const d=await r.json();
    if (d.success) { hideSave(); loadSaved(); document.querySelectorAll('.side-tab')[1].click(); setSt('ok','✓ Saved: '+name); }
    else alert('Error: '+d.error);
}

async function exportRes(fmt) {
    const sql = lastSQL || editor.getValue().trim();
    if (!sql) return;
    const fd=new FormData(); fd.append('sql',sql); fd.append('format',fmt);
    const r=await fetch('api.php?action=export',{method:'POST',body:fd});
    const blob=await r.blob();
    const a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download=`export_${Date.now()}.${fmt}`;
    a.click();
}
function clearEditor() { if (editor.getValue().trim() && !confirm('Clear editor?')) return; editor.setValue(''); editor.focus(); }
function fmtSQL() {
    let s = editor.getValue();
    const kws = ['SELECT','FROM','WHERE','LEFT JOIN','RIGHT JOIN','INNER JOIN','JOIN','GROUP BY','ORDER BY','HAVING','LIMIT','OFFSET','INSERT INTO','VALUES','UPDATE','SET','DELETE FROM','CREATE TABLE','DROP TABLE','ALTER TABLE','UNION ALL','UNION','AND','OR','ON','AS'];
    kws.forEach(k=>{ s=s.replace(new RegExp('\\b'+k+'\\b','gi'),k); });
    editor.setValue(s);
}
function esc(s){const d=document.createElement('div');d.textContent=String(s??'NULL');return d.innerHTML}
function fmtDate(d){if(!d)return'';const dt=new Date(d),diff=Date.now()-dt;if(diff<6e4)return'just now';if(diff<36e5)return Math.floor(diff/6e4)+'m ago';if(diff<864e5)return Math.floor(diff/36e5)+'h ago';return dt.toLocaleDateString()}
</script>
<?php page_footer(); ?>
