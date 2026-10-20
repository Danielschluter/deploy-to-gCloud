<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

function page_header(string $title, string $active = 'dashboard'): void {
    $nav = [
        'dashboard'   => ['url' => 'index.php',       'label' => 'Dashboard',   'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        'editor'      => ['url' => 'editor.php',      'label' => 'SQL Editor',  'icon' => 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
        'explorer'    => ['url' => 'explorer.php',    'label' => 'Explorer',    'icon' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4'],
        'tables'      => ['url' => 'view-tables.php', 'label' => 'Table Data',  'icon' => 'M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z'],
        'import'      => ['url' => 'csv-import.php',  'label' => 'CSV Import',  'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12'],
        'connections' => ['url' => 'connections.php', 'label' => 'Connections', 'icon' => 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1'],
    ];

    $active_conn = pgclient_get_active_connection();
    $conn_name   = $active_conn ? htmlspecialchars($active_conn['name']) : '—';
    $conn_db     = $active_conn ? htmlspecialchars($active_conn['database']) : '';
    $username    = htmlspecialchars($_SESSION['pgclient_user'] ?? 'admin');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> — PGClient</title>
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        :root{
            --bg:#16181d;--bg2:#1c1f26;--sidebar:#13151a;
            --panel:#22252e;--panel2:#2a2d38;--panel3:#31364a;
            --border:#2a2d38;--border2:#363b50;
            --text:#c9cdd9;--text2:#8b90a8;--text3:#555a72;
            --accent:#4da6ff;--accent2:#3d8fe0;--accent-bg:rgba(77,166,255,.12);
            --green:#4ec994;--green-bg:rgba(78,201,148,.12);
            --red:#f07878;--red-bg:rgba(240,120,120,.12);
            --yellow:#ffd166;--yellow-bg:rgba(255,209,102,.12);
            --purple:#a78bfa;--purple-bg:rgba(167,139,250,.12);
            --cyan:#67e8f9;
            --sidebar-w:220px;
            --header-h:48px;
        }
        html,body{height:100%;overflow:hidden}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:13px;line-height:1.5}
        a{color:var(--accent);text-decoration:none}
        a:hover{text-decoration:underline}
        button,input,select,textarea{font-family:inherit;font-size:inherit}

        /* ── Layout ── */
        .app{display:flex;height:100vh;width:100vw}

        /* ── Sidebar ── */
        .sidebar{
            width:var(--sidebar-w);background:var(--sidebar);
            border-right:1px solid var(--border);
            display:flex;flex-direction:column;flex-shrink:0;overflow:hidden
        }
        .sidebar-brand{
            padding:14px 16px;border-bottom:1px solid var(--border);
            display:flex;align-items:center;gap:10px;flex-shrink:0
        }
        .brand-pg{
            width:28px;height:28px;border-radius:6px;
            background:linear-gradient(135deg,#336699,#4da6ff);
            display:flex;align-items:center;justify-content:center;
            font-size:14px;font-weight:800;color:#fff;flex-shrink:0
        }
        .brand-text{line-height:1.2}
        .brand-name{font-weight:700;font-size:13px;color:var(--text)}
        .brand-sub{font-size:10px;color:var(--text3)}

        /* Nav */
        .sidebar-nav{padding:8px;border-bottom:1px solid var(--border);flex-shrink:0}
        .nav-item{
            display:flex;align-items:center;gap:9px;
            padding:7px 10px;border-radius:6px;
            color:var(--text2);transition:all .14s;
            margin-bottom:1px;font-size:12.5px;cursor:pointer
        }
        .nav-item:hover{background:var(--panel);color:var(--text);text-decoration:none}
        .nav-item.active{background:var(--accent-bg);color:var(--accent)}
        .nav-icon{width:16px;height:16px;flex-shrink:0;opacity:.8}
        .nav-item.active .nav-icon{opacity:1}

        /* Connection badge in sidebar */
        .conn-badge{
            margin:8px;padding:8px 10px;border-radius:6px;
            background:var(--panel);border:1px solid var(--border);
            flex-shrink:0
        }
        .conn-badge-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text3);margin-bottom:3px}
        .conn-badge-name{font-size:12px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:5px}
        .conn-badge-db{font-size:10.5px;color:var(--text3);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .conn-dot-sm{width:6px;height:6px;border-radius:50%;background:var(--green);flex-shrink:0}

        /* Schema section */
        .schema-section{flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0}
        .schema-hdr{
            padding:10px 14px 6px;display:flex;align-items:center;
            justify-content:space-between;flex-shrink:0;
            font-size:10px;font-weight:700;text-transform:uppercase;
            letter-spacing:.8px;color:var(--text3)
        }
        .schema-hdr button{
            background:none;border:none;color:var(--text3);
            cursor:pointer;font-size:14px;padding:2px 4px;
            border-radius:3px;line-height:1;transition:color .14s
        }
        .schema-hdr button:hover{color:var(--accent)}
        .schema-tree{flex:1;overflow-y:auto;padding:4px 6px 8px}
        .schema-tree::-webkit-scrollbar{width:3px}
        .schema-tree::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}

        /* Tree nodes */
        .tn{margin-bottom:1px}
        .tn-hdr{
            display:flex;align-items:center;gap:5px;
            padding:4px 6px;border-radius:4px;cursor:pointer;
            color:var(--text2);font-size:12px;user-select:none;
            transition:all .12s
        }
        .tn-hdr:hover{background:var(--panel);color:var(--text)}
        .tn-arr{font-size:9px;color:var(--text3);width:10px;flex-shrink:0;transition:transform .15s}
        .tn.open>.tn-hdr .tn-arr{transform:rotate(0deg)}
        .tn:not(.open)>.tn-hdr .tn-arr{transform:rotate(-90deg)}
        .tn-children{display:none;padding-left:14px}
        .tn.open>.tn-children{display:block}
        .tn-ico{font-size:12px;width:14px;text-align:center}
        .tn-lbl{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .tn-cnt{font-size:10px;color:var(--text3);padding:0 2px}

        .tl a{
            display:flex;align-items:center;gap:5px;
            padding:3px 6px;border-radius:4px;
            color:var(--text2);font-size:11.5px;transition:all .12px
        }
        .tl a:hover{background:var(--panel);color:var(--text);text-decoration:none}
        .tl a.tl-active{background:var(--accent-bg);color:var(--accent)}

        /* User/logout strip at bottom of sidebar */
        .sidebar-footer{
            padding:8px;border-top:1px solid var(--border);flex-shrink:0;
            display:flex;align-items:center;gap:8px
        }
        .user-avatar{
            width:24px;height:24px;border-radius:50%;
            background:var(--accent-bg);border:1px solid var(--accent);
            display:flex;align-items:center;justify-content:center;
            font-size:10px;font-weight:700;color:var(--accent);flex-shrink:0
        }
        .user-name{font-size:11.5px;color:var(--text2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .logout-btn{
            background:none;border:none;color:var(--text3);
            cursor:pointer;font-size:11px;padding:3px 6px;
            border-radius:4px;transition:all .14s;white-space:nowrap;
            text-decoration:none
        }
        .logout-btn:hover{color:var(--red);background:var(--red-bg);text-decoration:none}

        /* ── Main ── */
        .app-main{flex:1;display:flex;flex-direction:column;min-width:0;overflow:hidden}
        .page-hdr{
            height:var(--header-h);padding:0 20px;
            border-bottom:1px solid var(--border);
            display:flex;align-items:center;gap:12px;
            background:var(--bg2);flex-shrink:0
        }
        .page-title{font-size:13px;font-weight:600;color:var(--text)}
        .page-body{flex:1;overflow:auto;padding:20px}
        .page-body::-webkit-scrollbar{width:6px;height:6px}
        .page-body::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}

        /* ── Reusable components ── */
        .btn{
            display:inline-flex;align-items:center;gap:6px;
            padding:6px 14px;border-radius:5px;border:none;
            font-size:12px;font-weight:500;cursor:pointer;
            transition:all .15s;text-decoration:none;white-space:nowrap
        }
        .btn-primary{background:var(--accent);color:#fff}
        .btn-primary:hover{background:var(--accent2);text-decoration:none}
        .btn-success{background:var(--green);color:#0a1a12}
        .btn-success:hover{background:#3dba85}
        .btn-danger{background:var(--red);color:#fff}
        .btn-danger:hover{background:#d96060}
        .btn-ghost{background:var(--panel);color:var(--text2);border:1px solid var(--border)}
        .btn-ghost:hover{background:var(--panel2);color:var(--text);text-decoration:none}
        .btn-sm{padding:5px 11px;font-size:11.5px}
        .btn-xs{padding:3px 8px;font-size:11px}
        .btn:disabled{opacity:.45;cursor:not-allowed;pointer-events:none}

        .card{background:var(--panel);border:1px solid var(--border);border-radius:8px}
        .card-hdr{padding:11px 16px;border-bottom:1px solid var(--border);font-weight:600;font-size:12px;color:var(--text);display:flex;align-items:center;gap:8px}
        .card-body{padding:16px}

        .grid{display:grid;gap:16px}
        .g2{grid-template-columns:1fr 1fr}
        .g3{grid-template-columns:1fr 1fr 1fr}
        .g4{grid-template-columns:1fr 1fr 1fr 1fr}

        .stat-card{background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:16px;position:relative;overflow:hidden}
        .stat-val{font-size:22px;font-weight:700;color:var(--text);margin-bottom:3px}
        .stat-lbl{font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;font-weight:600}
        .stat-icon{position:absolute;right:14px;top:14px;font-size:20px;opacity:.2}

        .data-tbl{width:100%;border-collapse:collapse;font-size:12px}
        .data-tbl th{
            background:var(--panel2);padding:8px 12px;text-align:left;
            font-weight:600;color:var(--text2);border-bottom:1px solid var(--border);
            white-space:nowrap;position:sticky;top:0;z-index:1;
            font-size:10.5px;text-transform:uppercase;letter-spacing:.4px
        }
        .data-tbl td{
            padding:7px 12px;border-bottom:1px solid var(--border);
            color:var(--text);vertical-align:middle
        }
        .data-tbl tr:hover td{background:rgba(255,255,255,.02)}
        .data-tbl .null-v{color:var(--text3);font-style:italic}
        .data-tbl .num-v{color:var(--purple);font-family:monospace}
        .data-tbl .bool-v{color:var(--yellow)}

        .badge{display:inline-flex;align-items:center;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:600}
        .b-blue{background:var(--accent-bg);color:var(--accent)}
        .b-green{background:var(--green-bg);color:var(--green)}
        .b-red{background:var(--red-bg);color:var(--red)}
        .b-yellow{background:var(--yellow-bg);color:var(--yellow)}
        .b-gray{background:var(--panel2);color:var(--text3)}
        .b-purple{background:var(--purple-bg);color:var(--purple)}

        code,.mono{font-family:'JetBrains Mono','Fira Code','Cascadia Code',monospace;font-size:11.5px;background:var(--panel2);padding:2px 5px;border-radius:3px;color:var(--accent)}
        .tbl-wrap{overflow:auto;border:1px solid var(--border);border-radius:8px}
        .tbl-wrap::-webkit-scrollbar{width:5px;height:5px}
        .tbl-wrap::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}

        .spinner{display:inline-block;width:14px;height:14px;border:2px solid var(--border2);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}

        .empty-st{text-align:center;padding:48px 24px;color:var(--text3)}
        .empty-st-ico{font-size:36px;margin-bottom:12px}
        .empty-st h3{font-size:14px;color:var(--text2);margin-bottom:6px}

        .form-inp{
            background:var(--bg);border:1px solid var(--border);
            color:var(--text);padding:7px 10px;border-radius:5px;
            font-size:12.5px;font-family:inherit;width:100%
        }
        .form-inp:focus{outline:none;border-color:var(--accent)}
        select.form-inp{cursor:pointer}
        .form-lbl{font-size:10.5px;color:var(--text3);margin-bottom:5px;display:block;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
        .form-group{margin-bottom:14px}

        .flex{display:flex;align-items:center;gap:8px}
        .ml-auto{margin-left:auto}
        .text-muted{color:var(--text3)}
        .text-sm{font-size:11px}
        .text-success{color:var(--green)}
        .text-error{color:var(--red)}
        .text-accent{color:var(--accent)}
        .truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

        /* Modal */
        .modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;align-items:center;justify-content:center}
        .modal-ov.open{display:flex}
        .modal{background:var(--panel);border:1px solid var(--border2);border-radius:10px;padding:24px;width:420px;max-width:92vw}
        .modal h3{font-size:14px;font-weight:600;margin-bottom:16px;color:var(--text)}
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-pg">PG</div>
            <div class="brand-text">
                <div class="brand-name">PGClient</div>
                <div class="brand-sub">PostgreSQL Manager</div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($nav as $key => $page): ?>
            <a href="<?= $page['url'] ?>" class="nav-item <?= $active === $key ? 'active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="<?= $page['icon'] ?>"/>
                </svg>
                <span><?= $page['label'] ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($active_conn): ?>
        <div class="conn-badge">
            <div class="conn-badge-lbl">Active Connection</div>
            <div class="conn-badge-name">
                <div class="conn-dot-sm"></div>
                <?= $conn_name ?>
            </div>
            <div class="conn-badge-db"><?= $conn_db ?></div>
        </div>
        <?php endif; ?>

        <div class="schema-section">
            <div class="schema-hdr">
                Schema
                <button id="btn-refresh-schema" title="Refresh">↻</button>
            </div>
            <div class="schema-tree" id="schema-tree">
                <div style="padding:12px;text-align:center;color:var(--text3)">
                    <div class="spinner" style="margin:0 auto 8px"></div>
                    Loading…
                </div>
            </div>
        </div>

        <div class="sidebar-footer">
            <div class="user-avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
            <span class="user-name"><?= $username ?></span>
            <a href="logout.php" class="logout-btn" title="Sign out">⎋ Logout</a>
        </div>
    </aside>
    <main class="app-main">
        <div class="page-hdr">
            <span class="page-title"><?= htmlspecialchars($title) ?></span>
            <div id="page-actions" style="margin-left:auto;display:flex;align-items:center;gap:8px;"></div>
        </div>
        <div class="page-body" id="page-body">
<?php
}

function page_footer(): void { ?>
        </div>
    </main>
</div>
<script>
(function(){
    const ACTIVE_TABLE  = window.PGCLIENT_TABLE  || null;
    const ACTIVE_SCHEMA = window.PGCLIENT_SCHEMA || 'public';

    async function loadTree(){
        const el = document.getElementById('schema-tree');
        if(!el) return;
        try{
            const r = await fetch('api.php?action=schema_tree');
            const d = await r.json();
            if(!d.success){ el.innerHTML=`<div style="padding:8px 10px;color:var(--red);font-size:11px;">Error: ${esc(d.error)}</div>`; return; }
            if(!d.schemas||!d.schemas.length){ el.innerHTML=`<div style="padding:12px;color:var(--text3);font-size:11px;text-align:center;">No schemas found</div>`; return; }
            el.innerHTML = d.schemas.map(renderSchema).join('');
            el.querySelectorAll('.tn-hdr').forEach(h=>h.addEventListener('click',()=>h.closest('.tn').classList.toggle('open')));
        }catch(e){
            el.innerHTML=`<div style="padding:8px 10px;color:var(--red);font-size:11px;">Failed to load</div>`;
        }
    }

    function renderSchema(s){
        const tables = (s.tables||[]).map(t=>leaf(t,s.name,'📋')).join('');
        const views  = (s.views ||[]).map(t=>leaf(t,s.name,'👁')).join('');
        const grps = [];
        if(s.tables&&s.tables.length) grps.push(group('Tables',s.tables.length,tables));
        if(s.views &&s.views.length)  grps.push(group('Views', s.views.length, views));
        return `<div class="tn open">
            <div class="tn-hdr"><span class="tn-arr">▾</span><span class="tn-ico">🗃</span><span class="tn-lbl">${esc(s.name)}</span></div>
            <div class="tn-children">${grps.join('')}</div>
        </div>`;
    }
    function group(label,count,children){
        return `<div class="tn open">
            <div class="tn-hdr"><span class="tn-arr">▾</span><span class="tn-lbl" style="color:var(--text3);font-size:11px;">${label}</span><span class="tn-cnt">${count}</span></div>
            <div class="tn-children">${children}</div>
        </div>`;
    }
    function leaf(t,schema,icon){
        const active = t.name===ACTIVE_TABLE && schema===ACTIVE_SCHEMA;
        const cnt = t.rowCount>0?`<span class="tn-cnt">${fmt(t.rowCount)}</span>`:'';
        return `<div class="tl"><a href="explorer.php?schema=${enc(schema)}&table=${enc(t.name)}" class="${active?'tl-active':''}">
            <span style="font-size:11px;">${icon}</span>
            <span class="tn-lbl">${esc(t.name)}</span>${cnt}
        </a></div>`;
    }
    function esc(s){const d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML;}
    function enc(s){return encodeURIComponent(s);}
    function fmt(n){n=parseInt(n)||0;if(n<0)return'?';if(n>1e6)return(n/1e6).toFixed(1)+'M';if(n>1e3)return Math.round(n/1e3)+'k';return n.toLocaleString();}

    document.getElementById('btn-refresh-schema')?.addEventListener('click',loadTree);
    loadTree();
})();
</script>
</body>
</html>
<?php
}
