import http from 'node:http';
import path from 'node:path';
import os from 'node:os';
import { WpCliEngine } from './engines/cli.js';
import { SecurityManager } from './security.js';
import { createTools } from './tools.js';
const PORT = 3300;
const sitePath = process.env.WP_PATH || '/Volumes/T7/Other local/wptest';
const localwpScript = process.env.LOCALWP_SCRIPT_PATH ||
    path.join(os.homedir(), '.gemini/config/skills/localwp-cli-skill/scripts/localwp-cli.sh');
let safeMode = true;
let security = new SecurityManager({ safeMode });
let engine = new WpCliEngine({
    wpPath: sitePath,
    localwpScriptPath: localwpScript,
    security,
});
let tools = createTools(engine, security);
let toolMap = new Map(tools.map((t) => [t.name, t]));
function rebuildTools() {
    security = new SecurityManager({ safeMode });
    engine = new WpCliEngine({
        wpPath: sitePath,
        localwpScriptPath: localwpScript,
        security,
    });
    tools = createTools(engine, security);
    toolMap = new Map(tools.map((t) => [t.name, t]));
}
const HTML_CONTENT = `<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WordPress MCP Dashboard & Interactive Tester</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #0d1117;
      --card-bg: #161b22;
      --border: #30363d;
      --primary: #2ea043;
      --primary-hover: #3fb950;
      --accent: #58a6ff;
      --text: #c9d1d9;
      --text-muted: #8b949e;
      --heading: #f0f6fc;
      --error: #f85149;
      --warning: #d29922;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Vazirmatn', -apple-system, BlinkMacSystemFont, sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.6;
      padding: 24px;
    }
    .container { max-width: 1200px; margin: 0 auto; }
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 20px;
      border-bottom: 1px solid var(--border);
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 16px;
    }
    .title-group h1 { font-size: 1.7rem; color: var(--heading); display: flex; align-items: center; gap: 10px; }
    .title-group p { color: var(--text-muted); font-size: 0.95rem; }
    .badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
    }
    .badge-safe { background: rgba(56, 139, 253, 0.15); color: var(--accent); border: 1px solid rgba(56, 139, 253, 0.4); }
    .badge-live { background: rgba(46, 160, 67, 0.15); color: var(--primary); border: 1px solid rgba(46, 160, 67, 0.4); }
    .badge-warn { background: rgba(210, 153, 34, 0.15); color: var(--warning); border: 1px solid rgba(210, 153, 34, 0.4); }
    
    .grid { display: grid; grid-template-columns: 320px 1fr; gap: 24px; }
    @media (max-width: 860px) { .grid { grid-template-columns: 1fr; } }
    
    .sidebar { display: flex; flex-direction: column; gap: 12px; }
    .tool-btn {
      background: var(--card-bg);
      border: 1px solid var(--border);
      color: var(--heading);
      padding: 12px 16px;
      border-radius: 8px;
      cursor: pointer;
      text-align: right;
      font-family: inherit;
      font-size: 0.95rem;
      transition: all 0.2s ease;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .tool-btn:hover { border-color: var(--accent); background: #1c2128; transform: translateY(-1px); }
    .tool-btn.active { border-color: var(--accent); background: #1f242c; box-shadow: 0 0 12px rgba(88, 166, 255, 0.2); }
    .tool-btn .name { font-weight: 600; font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; color: var(--accent); direction: ltr; text-align: left; }
    .tool-btn .desc { font-size: 0.8rem; color: var(--text-muted); }

    .main-panel {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .panel-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid var(--border);
      padding-bottom: 16px;
    }
    .panel-header h2 { font-size: 1.25rem; color: var(--heading); font-family: 'JetBrains Mono', monospace; direction: ltr; }
    .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
    .form-group label { font-size: 0.85rem; font-weight: 500; color: var(--text-muted); }
    .form-control {
      background: #0d1117;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 10px 12px;
      color: var(--heading);
      font-family: inherit;
      font-size: 0.95rem;
      transition: border 0.2s ease;
    }
    .form-control:focus { outline: none; border-color: var(--accent); }
    textarea.form-control { min-height: 90px; resize: vertical; }

    .btn-run {
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: 12px 24px;
      font-size: 1rem;
      font-weight: 600;
      font-family: inherit;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: background 0.2s ease;
    }
    .btn-run:hover { background: var(--primary-hover); }
    .btn-run:disabled { opacity: 0.6; cursor: not-allowed; }

    .toggle-safe {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.85rem;
      cursor: pointer;
    }

    .output-section { margin-top: 10px; }
    .output-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
    .output-box {
      background: #0d1117;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 16px;
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.85rem;
      color: #7ee787;
      overflow-x: auto;
      max-height: 480px;
      white-space: pre-wrap;
      word-break: break-all;
      direction: ltr;
      text-align: left;
    }
    .output-box.error { color: var(--error); border-color: rgba(248, 81, 73, 0.4); }
    .loader { display: none; width: 18px; height: 18px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>
  <div class="container">
    <header>
      <div class="title-group">
        <h1>🚀 WordPress MCP Tester & Dashboard</h1>
        <p>محیط تعاملی تست و بررسی زنده ابزارهای سرور Model Context Protocol برای وردپرس</p>
      </div>
      <div style="display: flex; gap: 10px; align-items: center;">
        <span class="badge badge-live">🟢 متصل به سایت محلی</span>
        <label class="toggle-safe badge badge-safe" id="safeBadge">
          <input type="checkbox" id="safeModeToggle" checked onchange="toggleSafeMode()">
          <span>حالت امن (Safe Mode): فعال</span>
        </label>
      </div>
    </header>

    <div class="grid">
      <div class="sidebar" id="toolList"></div>

      <div class="main-panel">
        <div class="panel-header">
          <div>
            <h2 id="activeToolTitle">wp_get_site_info</h2>
            <p id="activeToolDesc" style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;"></p>
          </div>
          <button class="btn-run" id="runBtn" onclick="runActiveTool()">
            <span class="loader" id="loader"></span>
            <span id="runText">▶ اجرای ابزار (Execute Tool)</span>
          </button>
        </div>

        <div id="argsFormContainer"></div>

        <div class="output-section">
          <div class="output-header">
            <span style="font-weight: 600; font-size: 0.9rem;">خروجی پروتکل MCP (JSON Response):</span>
            <span id="execTime" style="color: var(--text-muted); font-size: 0.8rem; font-family: 'JetBrains Mono';"></span>
          </div>
          <pre class="output-box" id="outputBox">// خروجی ابزار پس از اجرا در اینجا نمایش داده خواهد شد...</pre>
        </div>
      </div>
    </div>
  </div>

  <script>
    let tools = [];
    let activeTool = null;

    async function loadTools() {
      const res = await fetch('/api/tools');
      tools = await res.json();
      renderSidebar();
      if (tools.length > 0) selectTool(tools[0]);
    }

    function renderSidebar() {
      const el = document.getElementById('toolList');
      el.innerHTML = tools.map((t, idx) => \`
        <button class="tool-btn \${idx === 0 ? 'active' : ''}" id="btn-\${t.name}" onclick="selectTool(tools[\${idx}])">
          <span class="name">\${t.name}</span>
          <span class="desc">\${t.description.substring(0, 75)}...</span>
        </button>
      \`).join('');
    }

    function selectTool(t) {
      activeTool = t;
      document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('active'));
      const activeBtn = document.getElementById('btn-' + t.name);
      if (activeBtn) activeBtn.classList.add('active');

      document.getElementById('activeToolTitle').innerText = t.name;
      document.getElementById('activeToolDesc').innerText = t.description;

      const container = document.getElementById('argsFormContainer');
      const props = t.inputSchema?.properties || {};
      const propKeys = Object.keys(props);

      if (propKeys.length === 0) {
        container.innerHTML = '<p style="color: var(--text-muted); font-size: 0.9rem;">این ابزار نیازی به پارامتر ورودی ندارد و مستقیماً قابل اجرا است.</p>';
      } else {
        container.innerHTML = propKeys.map(k => {
          const prop = props[k];
          const isTextArea = k === 'content';
          return \`
            <div class="form-group">
              <label for="arg_\${k}">\${k} \${prop.description ? '(' + prop.description + ')' : ''}:</label>
              \${isTextArea 
                ? \`<textarea class="form-control" id="arg_\${k}" placeholder="\${prop.description || ''}"></textarea>\`
                : \`<input class="form-control" type="\${prop.type === 'number' ? 'number' : 'text'}" id="arg_\${k}" placeholder="\${prop.description || ''}" \${k === 'perPage' ? 'value="5"' : ''} \${k === 'id' ? 'value="1714"' : ''}>\`
              }
            </div>
          \`;
        }).join('');
      }
    }

    async function toggleSafeMode() {
      const toggle = document.getElementById('safeModeToggle');
      const isChecked = toggle.checked;
      const res = await fetch('/api/toggle-safe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ safeMode: isChecked })
      });
      const data = await res.json();
      const badge = document.getElementById('safeBadge');
      if (data.safeMode) {
        badge.className = 'toggle-safe badge badge-safe';
        badge.querySelector('span').innerText = 'حالت امن (Safe Mode): فعال';
      } else {
        badge.className = 'toggle-safe badge badge-warn';
        badge.querySelector('span').innerText = 'حالت امن: غیرفعال (اجازه ویرایش)';
      }
    }

    async function runActiveTool() {
      if (!activeTool) return;
      const runBtn = document.getElementById('runBtn');
      const loader = document.getElementById('loader');
      const runText = document.getElementById('runText');
      const outBox = document.getElementById('outputBox');
      const timeLabel = document.getElementById('execTime');

      runBtn.disabled = true;
      loader.style.display = 'inline-block';
      runText.innerText = 'در حال ارتباط با وردپرس...';
      outBox.className = 'output-box';
      outBox.innerText = 'Sending JSON-RPC request to WordPress MCP engine...';

      const args = {};
      const props = activeTool.inputSchema?.properties || {};
      for (const k of Object.keys(props)) {
        const input = document.getElementById('arg_' + k);
        if (input && input.value.trim()) {
          args[k] = props[k].type === 'number' ? Number(input.value) : input.value;
        }
      }

      const t0 = performance.now();
      try {
        const res = await fetch('/api/call-tool', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ tool: activeTool.name, args })
        });
        const result = await res.json();
        const duration = ((performance.now() - t0) / 1000).toFixed(2);
        timeLabel.innerText = 'زمان اجرا: ' + duration + ' ثانیه';

        if (result.isError) {
          outBox.className = 'output-box error';
          outBox.innerText = result.content?.[0]?.text || JSON.stringify(result, null, 2);
        } else {
          outBox.className = 'output-box';
          try {
            const parsed = JSON.parse(result.content?.[0]?.text || '{}');
            outBox.innerText = JSON.stringify(parsed, null, 2);
          } catch {
            outBox.innerText = result.content?.[0]?.text || JSON.stringify(result, null, 2);
          }
        }
      } catch (err) {
        outBox.className = 'output-box error';
        outBox.innerText = 'Network/Server Error: ' + err.message;
      } finally {
        runBtn.disabled = false;
        loader.style.display = 'none';
        runText.innerText = '▶ اجرای ابزار (Execute Tool)';
      }
    }

    loadTools();
  </script>
</body>
</html>
`;
const server = http.createServer(async (req, res) => {
    const url = new URL(req.url || '/', `http://${req.headers.host}`);
    if (req.method === 'GET' && (url.pathname === '/' || url.pathname === '/index.html')) {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(HTML_CONTENT);
        return;
    }
    if (req.method === 'GET' && url.pathname === '/api/tools') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(tools.map((t) => ({
            name: t.name,
            description: t.description,
            inputSchema: t.inputSchema,
        }))));
        return;
    }
    if (req.method === 'POST' && url.pathname === '/api/toggle-safe') {
        let body = '';
        req.on('data', (chunk) => (body += chunk));
        req.on('end', () => {
            try {
                const payload = JSON.parse(body);
                safeMode = Boolean(payload.safeMode);
                rebuildTools();
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ safeMode }));
            }
            catch (err) {
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ error: err.message }));
            }
        });
        return;
    }
    if (req.method === 'POST' && url.pathname === '/api/call-tool') {
        let body = '';
        req.on('data', (chunk) => (body += chunk));
        req.on('end', async () => {
            try {
                const payload = JSON.parse(body);
                const { tool: toolName, args = {} } = payload;
                const tool = toolMap.get(toolName);
                if (!tool) {
                    res.writeHead(404, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ error: `Tool ${toolName} not found` }));
                    return;
                }
                try {
                    const result = await tool.handler(args);
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({
                        content: [
                            {
                                type: 'text',
                                text: typeof result === 'string' ? result : JSON.stringify(result, null, 2),
                            },
                        ],
                    }));
                }
                catch (err) {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({
                        isError: true,
                        content: [
                            {
                                type: 'text',
                                text: `[WordPress MCP Error] ${err.message}`,
                            },
                        ],
                    }));
                }
            }
            catch (err) {
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ error: err.message }));
            }
        });
        return;
    }
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Not Found');
});
server.listen(PORT, () => {
    console.log(`\n======================================================`);
    console.log(`🚀 WordPress MCP Interactive Dashboard is LIVE!`);
    console.log(`🌐 Open in your browser: http://localhost:${PORT}`);
    console.log(`🎯 Connected Site: ${sitePath}`);
    console.log(`======================================================\n`);
});
