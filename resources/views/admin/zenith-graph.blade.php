<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zenith Graph Monitor — {{ $node->name }}</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
    :root {
        --bg: #0f1117; --surface: #171a23; --border: #262b38;
        --text: #e2e6ee; --muted: #8b93a7;
        --violet: #8b5cf6; --cyan: #22d3ee; --green: #34d399; --red: #f87171;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', system-ui, sans-serif; padding: 24px; }
    h1 { font-size: 22px; margin-bottom: 4px; }
    .sub { color: var(--muted); font-size: 13px; margin-bottom: 20px; }
    .controls { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
    .controls label { color: var(--muted); font-size: 13px; }
    select, button {
        background: var(--surface); color: var(--text); border: 1px solid var(--border);
        border-radius: 8px; padding: 8px 14px; font-size: 14px; cursor: pointer;
    }
    select:hover, button:hover { border-color: var(--violet); }
    .btn-refresh { background: var(--violet); border: none; color: white; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 16px; }
    .card {
        background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px;
    }
    .card h2 { font-size: 14px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 16px; }
    .stat { background: var(--bg); border-radius: 8px; padding: 12px; border: 1px solid var(--border); }
    .stat .val { font-size: 20px; font-weight: 600; }
    .stat .lbl { font-size: 11px; color: var(--muted); text-transform: uppercase; margin-top: 2px; }
    .stat.green .val { color: var(--green); } .stat.cyan .val { color: var(--cyan); }
    .stat.violet .val { color: var(--violet); } .stat.red .val { color: var(--red); }
    canvas { max-height: 220px; }
    .loading { color: var(--muted); text-align: center; padding: 40px; }
    a.back { color: var(--violet); text-decoration: none; font-size: 13px; }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<a class="back" href="/admin">← Admin Panel</a>
<h1>📊 Zenith Graph Monitor</h1>
<div class="sub">Node: <strong>{{ $node->name }}</strong> ({{ $node->fqdn }}) — live metrics, watchdog har minute collect karta hai</div>

<div class="controls">
    <label>Range:</label>
    <select id="range">
        <option value="1">Last 1 hour</option>
        <option value="6" selected>Last 6 hours</option>
        <option value="24">Last 24 hours</option>
        <option value="48">Last 48 hours</option>
    </select>
    <button class="btn-refresh" onclick="loadAll()">↻ Refresh</button>
    <button onclick="loadAll(); setTimeout(loadAll, 60000)">▶ Auto 60s</button>
    <span id="status" style="color:var(--muted);font-size:12px"></span>
</div>

<div class="stats" id="nodeStats">
    <div class="stat violet"><div class="val" id="s-cpu">—</div><div class="lbl">Host CPU</div></div>
    <div class="stat cyan"><div class="val" id="s-ram">—</div><div class="lbl">Host RAM</div></div>
    <div class="stat green"><div class="val" id="s-disk">—</div><div class="lbl">Host Disk</div></div>
    <div class="stat red"><div class="val" id="s-points">—</div><div class="lbl">Data Points</div></div>
</div>

<div class="grid">
    <div class="card"><h2>Node CPU %</h2><canvas id="c-nodecpu"></canvas></div>
    <div class="card"><h2>Node RAM %</h2><canvas id="c-noderam"></canvas></div>
    <div class="card"><h2>Node Disk %</h2><canvas id="c-nodedisk"></canvas></div>
    <div class="card"><h2>Server CPU % — {{ $server->name }}</h2><canvas id="c-svrcpu"></canvas></div>
    <div class="card"><h2>Server RAM MB — {{ $server->name }}</h2><canvas id="c-svrram"></canvas></div>
</div>

<script>
const NODE_URL = '/api/admin/nodes/{{ $node->id }}/node-metrics';
const SRV_URL = '/api/admin/servers/{{ $server->id }}/metrics';
const CSRF = '{{ csrf_token() }}';

const opts = {
    responsive: true, maintainAspectRatio: false, animation: false,
    scales: { x: { ticks: { color: '#8b93a7', maxTicksLimit: 10 }, grid: { color: '#262b38' } },
              y: { ticks: { color: '#8b93a7' }, grid: { color: '#262b38' } } },
    plugins: { legend: { display: false } }
};
const line = (label, color, yTitle) => new Chart(document.getElementById(label), {
    type: 'line',
    data: { labels: [], datasets: [{ data: [], borderColor: color, backgroundColor: color + '33', fill: true, tension: 0.3, pointRadius: 0 }] },
    options: { ...opts, scales: { ...opts.scales, y: { ...opts.scales.y, title: { display: true, text: yTitle, color: '#8b93a7' } } } }
});

let charts = {
    nodecpu: line('c-nodecpu', '#8b5cf6', 'CPU %'),
    noderam: line('c-noderam', '#22d3ee', 'RAM %'),
    nodedisk: line('c-nodedisk', '#34d399', 'Disk %'),
    svrcpu: line('c-svrcpu', '#8b5cf6', 'CPU %'),
    svrram: line('c-svrram', '#22d3ee', 'RAM MB'),
};

function fmtTime(iso) { return iso.slice(11, 16); }

async function fetchJSON(url) {
    const r = await fetch(url, { headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
}

function setLine(chart, labels, data) {
    chart.data.labels = labels;
    chart.data.datasets[0].data = data;
    chart.update();
}

async function loadAll() {
    document.getElementById('status').textContent = 'loading...';
    const hours = document.getElementById('range').value;
    try {
        // Node metrics
        const node = await fetchJSON(`${NODE_URL}?hours=${hours}`);
        const nd = node.data;
        const labels = nd.map(d => fmtTime(d.collected_at));
        setLine(charts.nodecpu, labels, nd.map(d => d.cpu_percent));
        setLine(charts.noderam, labels, nd.map(d => d.memory_percent));
        setLine(charts.nodedisk, labels, nd.map(d => d.disk_percent));
        const last = nd[nd.length - 1];
        document.getElementById('s-cpu').textContent = last ? last.cpu_percent + '%' : '—';
        document.getElementById('s-ram').textContent = last ? last.memory_percent + '%' : '—';
        document.getElementById('s-disk').textContent = last ? last.disk_percent + '%' : '—';
        document.getElementById('s-points').textContent = node.meta.count;

        // Server metrics
        const srv = await fetchJSON(`${SRV_URL}?hours=${hours}`);
        const sd = srv.data;
        const slabels = sd.map(d => fmtTime(d.collected_at));
        setLine(charts.svrcpu, slabels, sd.map(d => d.cpu_percent));
        setLine(charts.svrram, slabels, sd.map(d => Math.round(d.memory_bytes / 1048576)));
        document.getElementById('status').textContent = 'updated ' + new Date().toLocaleTimeString();
    } catch (e) {
        document.getElementById('status').textContent = 'error: ' + e.message;
    }
}

loadAll();
setInterval(loadAll, 60000); // auto-refresh every 60s
</script>
</body>
</html>
