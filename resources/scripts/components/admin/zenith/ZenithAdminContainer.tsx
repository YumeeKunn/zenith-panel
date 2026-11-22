import { useCallback, useEffect, useRef, useState } from 'react';
import { Activity, BarChart3, Bell, CloudOff, Cpu, Gauge, HardDrive, MemoryStick, RefreshCw, Send, Server } from 'lucide-react';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import Alert from '@/components/elements/alert/Alert';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import { getZenithSettings, saveZenithSettings, testZenithChannels, ZenithAlertConfig, ZenithSettings } from '@/api/admin/zenith/queries';
import { httpErrorToHuman } from '@/api/http';

type Tab = 'alerts' | 'graphs';
type ChannelChoice = 'off' | 'discord' | 'generic' | 'both';

const CHANNEL_LABELS: Record<ChannelChoice, string> = {
    off: 'Disabled',
    discord: 'Discord only',
    generic: 'Generic webhook only',
    both: 'Discord + Generic',
};

interface NodeInfo {
    id: number;
    name: string;
    fqdn: string;
}

export default function ZenithAdminContainer() {
    const [tab, setTab] = useState<Tab>('alerts');
    return (
        <AdminContentBlock title={'Zenith Control'}>
            <h1 className={'mb-1 flex items-center gap-2 text-2xl font-bold text-neutral-100'}>
                <ShieldIcon /> Zenith Control
            </h1>
            <p className={'mb-6 text-sm text-neutral-400'}>
                Watchdog, notifications, alert thresholds and live graphs — all managed here.
            </p>
            <div className={'mb-6 flex gap-2 border-b border-neutral-700'}>
                <TabButton active={tab === 'alerts'} onClick={() => setTab('alerts')} icon={<Bell size={14} />} label={'Alert Settings'} />
                <TabButton active={tab === 'graphs'} onClick={() => setTab('graphs')} icon={<BarChart3 size={14} />} label={'Graph Monitor'} />
            </div>
            {tab === 'alerts' ? <AlertSettingsTab /> : <GraphMonitorTab />}
        </AdminContentBlock>
    );
}

const ShieldIcon = () => (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" />
    </svg>
);

const TabButton = ({ active, onClick, icon, label }: { active: boolean; onClick: () => void; icon: React.ReactNode; label: string }) => (
    <button
        onClick={onClick}
        className={`flex items-center gap-2 rounded-t-lg border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
            active ? 'border-violet-500 text-violet-300' : 'border-transparent text-neutral-400 hover:text-neutral-200'
        }`}
    >
        {icon} {label}
    </button>
);

/* ============================= ALERT SETTINGS TAB ============================= */

function AlertSettingsTab() {
    const [settings, setSettings] = useState<ZenithSettings | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
    const [testResults, setTestResults] = useState<Record<string, string> | null>(null);

    useEffect(() => {
        getZenithSettings()
            .then(setSettings)
            .catch((e) => setMessage({ type: 'error', text: httpErrorToHuman(e) }))
            .finally(() => setLoading(false));
    }, []);

    const updateAlert = (type: string, patch: Partial<ZenithAlertConfig>) => {
        if (!settings) return;
        setSettings({ ...settings, alerts: { ...settings.alerts, [type]: { ...settings.alerts[type], ...patch } } });
    };

    const save = async () => {
        if (!settings) return;
        setSaving(true);
        setMessage(null);
        try {
            const alerts: Record<string, { channel: string; threshold?: number | null }> = {};
            for (const [type, cfg] of Object.entries(settings.alerts)) {
                alerts[type] = { channel: cfg.channel, threshold: cfg.has_threshold ? cfg.threshold : null };
            }
            await saveZenithSettings({
                discord_webhook: settings.discord_webhook,
                generic_webhook: settings.generic_webhook,
                cooldown: parseInt(String(settings.cooldown)) || 900,
                alerts,
            });
            setMessage({ type: 'success', text: 'Settings saved — applied from the next watchdog cycle.' });
        } catch (e) {
            setMessage({ type: 'error', text: httpErrorToHuman(e) });
        } finally {
            setSaving(false);
        }
    };

    const test = async () => {
        setTesting(true);
        setTestResults(null);
        try {
            setTestResults(await testZenithChannels());
        } catch (e) {
            setMessage({ type: 'error', text: httpErrorToHuman(e) });
        } finally {
            setTesting(false);
        }
    };

    if (loading) return <Spinner size={'large'} centered />;

    return (
        <div>
            {message && (
                <Alert type={message.type === 'success' ? 'success' : 'danger'} className={'mb-4'}>
                    {message.text}
                </Alert>
            )}

            <div className={'mb-6 rounded-lg border border-neutral-700 bg-neutral-800/50 p-5'}>
                <h2 className={'mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-neutral-300'}>
                    <Bell size={14} /> Notification Channels
                </h2>
                <div className={'grid gap-4 md:grid-cols-2'}>
                    <div>
                        <label className={'mb-1 block text-sm text-neutral-300'}>
                            <Send size={12} className={'inline'} /> Discord Webhook
                        </label>
                        <input
                            type={'text'}
                            className={'w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm'}
                            value={settings!.discord_webhook}
                            onChange={(e) => setSettings({ ...settings!, discord_webhook: e.target.value })}
                            placeholder={'https://discord.com/api/webhooks/...'}
                        />
                    </div>
                    <div>
                        <label className={'mb-1 block text-sm text-neutral-300'}>
                            <CloudOff size={12} className={'inline'} /> Generic Webhook (ntfy / Slack / custom)
                        </label>
                        <input
                            type={'text'}
                            className={'w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm'}
                            value={settings!.generic_webhook}
                            onChange={(e) => setSettings({ ...settings!, generic_webhook: e.target.value })}
                            placeholder={'https://ntfy.sh/your-topic'}
                        />
                    </div>
                </div>
                <div className={'mt-4'}>
                    <label className={'mb-1 block text-sm text-neutral-300'}>Alert Repeat Interval (seconds)</label>
                    <input
                        type={'number'}
                        className={'w-48 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm'}
                        value={settings!.cooldown}
                        onChange={(e) => setSettings({ ...settings!, cooldown: parseInt(e.target.value) || 900 })}
                        min={60}
                    />
                    <p className={'mt-1 text-xs text-neutral-500'}>Same alert repeats after this interval (anti-spam).</p>
                </div>
            </div>

            <div className={'mb-6 overflow-x-auto rounded-lg border border-neutral-700 bg-neutral-800/50'}>
                <table className={'w-full text-sm'}>
                    <thead>
                        <tr className={'border-b border-neutral-700 text-left text-xs uppercase text-neutral-400'}>
                            <th className={'px-4 py-3'}>Alert</th>
                            <th className={'w-56 px-4 py-3'}>Send To</th>
                            <th className={'w-40 px-4 py-3'}>Threshold (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {Object.entries(settings!.alerts).map(([type, cfg]) => (
                            <tr key={type} className={'border-b border-neutral-800'}>
                                <td className={'px-4 py-3'}>
                                    <div className={'font-medium text-neutral-100'}>{cfg.label}</div>
                                    <div className={'text-xs text-neutral-500'}>{cfg.hint}</div>
                                </td>
                                <td className={'px-4 py-3'}>
                                    <select
                                        className={'w-full rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1.5 text-sm'}
                                        value={cfg.channel}
                                        onChange={(e) => updateAlert(type, { channel: e.target.value as ChannelChoice })}
                                    >
                                        {Object.entries(CHANNEL_LABELS).map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </td>
                                <td className={'px-4 py-3'}>
                                    {cfg.has_threshold ? (
                                        <input
                                            type={'number'}
                                            className={'w-full rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1.5 text-sm'}
                                            value={cfg.threshold ?? ''}
                                            min={1}
                                            onChange={(e) => updateAlert(type, { threshold: parseInt(e.target.value) })}
                                        />
                                    ) : (
                                        <span className={'text-neutral-600'}>—</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className={'flex items-center gap-3'}>
                <Button onClick={save} disabled={saving}>
                    {saving ? 'Saving...' : 'Save Settings'}
                </Button>
                <Button variant={'secondary'} onClick={test} disabled={testing}>
                    {testing ? 'Sending...' : 'Send Test (all channels)'}
                </Button>
            </div>

            {testResults && (
                <div className={'mt-4 rounded-lg border border-neutral-700 bg-neutral-900 p-4'}>
                    {Object.entries(testResults).map(([ch, res]) => (
                        <div key={ch} className={'text-sm'}>
                            <span className={'font-semibold capitalize'}>{ch}</span>:{' '}
                            <span className={res === 'sent' ? 'text-green-400' : 'text-red-400'}>{res}</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ============================= GRAPH MONITOR TAB ============================= */

interface NodeMetricPoint {
    collected_at: string;
    cpu_percent: number;
    memory_percent: number;
    allocated_memory_percent: number | null;
    allocated_disk_percent: number | null;
}

interface ServerMetricPoint {
    collected_at: string;
    cpu_percent: number;
    memory_bytes: number;
}

function GraphMonitorTab() {
    const [nodes, setNodes] = useState<NodeInfo[]>([]);
    const [selectedNode, setSelectedNode] = useState<number | null>(null);
    const [points, setPoints] = useState<NodeMetricPoint[]>([]);
    const [serverPoints, setServerPoints] = useState<ServerMetricPoint[]>([]);
    const [loading, setLoading] = useState(true);
    const [range, setRange] = useState(6);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const chartRef = useRef<any>(null);

    const loadNodes = useCallback(async () => {
        const r = await fetch('/api/admin/nodes', { headers: { Accept: 'application/json' } });
        const d = await r.json();
        const list: NodeInfo[] = (d.data || []).map((n: any) => ({ id: n.attributes.id, name: n.attributes.name, fqdn: n.attributes.fqdn }));
        setNodes(list);
        if (list.length > 0 && selectedNode === null) setSelectedNode(list[0].id);
    }, [selectedNode]);

    const loadMetrics = useCallback(async (nodeId: number, hours: number) => {
        const r = await fetch(`/api/admin/nodes/${nodeId}/node-metrics?hours=${hours}`, { headers: { Accept: 'application/json' } });
        const d = await r.json();
        setPoints(d.data || []);
        // server metrics for the first server on this node
        const rs = await fetch(`/api/admin/servers?hours=${hours}`, { headers: { Accept: 'application/json' } });
        if (rs.ok) {
            const sd = await rs.json();
            const servers: any[] = sd.data || [];
            if (servers.length > 0) {
                const sid = servers[0].attributes.id;
                const rsr = await fetch(`/api/admin/servers/${sid}/metrics?hours=${hours}`, { headers: { Accept: 'application/json' } });
                if (rsr.ok) {
                    const sdm = await rsr.json();
                    setServerPoints(sdm.data || []);
                }
            }
        }
    }, []);

    // 3s polling — ONLY while this tab is open (cleanup on unmount)
    useEffect(() => {
        loadNodes();
        return () => {};
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (selectedNode === null) return;
        const tick = async () => {
            await loadMetrics(selectedNode, range);
            setLoading(false);
            drawChart();
        };
        tick();
        timerRef.current = setInterval(tick, 3000);
        return () => {
            if (timerRef.current) clearInterval(timerRef.current);
        };
    }, [selectedNode, range, loadMetrics]);

    const drawChart = () => {
        // lightweight custom canvas chart (no external lib needed)
        const canvas = canvasRef.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const dpr = window.devicePixelRatio || 1;
        const w = canvas.clientWidth;
        const h = 220;
        canvas.width = w * dpr;
        canvas.height = h * dpr;
        ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, w, h);

        // grid
        ctx.strokeStyle = '#262b38';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = 10 + (i * (h - 30)) / 4;
            ctx.beginPath();
            ctx.moveTo(40, y);
            ctx.lineTo(w - 10, y);
            ctx.stroke();
            ctx.fillStyle = '#8b93a7';
            ctx.font = '10px sans-serif';
            ctx.fillText(`${100 - i * 25}%`, 5, y + 3);
        }

        const series: { data: number[]; color: string; label: string }[] = [
            { data: points.map((p) => p.cpu_percent), color: '#8b5cf6', label: 'CPU' },
            { data: points.map((p) => p.memory_percent), color: '#22d3ee', label: 'RAM' },
        ];
        const maxLen = Math.max(...series.map((s) => s.data.length), 1);
        if (maxLen <= 1) return;

        for (const s of series) {
            ctx.strokeStyle = s.color;
            ctx.lineWidth = 2;
            ctx.beginPath();
            s.data.forEach((v, i) => {
                const x = 40 + (i / (maxLen - 1)) * (w - 50);
                const y = 10 + (1 - Math.min(v, 100) / 100) * (h - 30);
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });
            ctx.stroke();
        }

        // legend
        ctx.font = '11px sans-serif';
        series.forEach((s, i) => {
            ctx.fillStyle = s.color;
            ctx.fillRect(40 + i * 80, 4, 10, 3);
            ctx.fillStyle = '#8b93a7';
            ctx.fillText(s.label, 55 + i * 80, 8);
        });
    };

    if (loading && nodes.length === 0) return <Spinner size={'large'} centered />;

    return (
        <div>
            <div className={'mb-4 flex flex-wrap items-center gap-2'}>
                {nodes.map((n) => (
                    <button
                        key={n.id}
                        onClick={() => setSelectedNode(n.id)}
                        className={`flex items-center gap-2 rounded-lg border px-4 py-2 text-sm transition-colors ${
                            selectedNode === n.id
                                ? 'border-violet-500 bg-violet-500/10 text-violet-300'
                                : 'border-neutral-700 bg-neutral-800/50 text-neutral-300 hover:border-violet-500/50'
                        }`}
                    >
                        <Server size={14} /> {n.name}
                        <span className={'text-xs text-neutral-500'}>{n.fqdn}</span>
                    </button>
                ))}
            </div>

            <div className={'mb-4 flex items-center gap-3'}>
                <label className={'text-sm text-neutral-400'}>Range:</label>
                <select
                    className={'rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-sm'}
                    value={range}
                    onChange={(e) => setRange(parseInt(e.target.value))}
                >
                    <option value={1}>Last hour</option>
                    <option value={6}>Last 6 hours</option>
                    <option value={24}>Last 24 hours</option>
                    <option value={48}>Last 48 hours</option>
                </select>
                <span className={'text-xs text-neutral-500'}>
                    <RefreshCw size={10} className={'inline'} /> auto-updates every 3s while this tab is open
                </span>
            </div>

            <div className={'rounded-lg border border-neutral-700 bg-neutral-800/50 p-5'}>
                <h2 className={'mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-neutral-300'}>
                    <Cpu size={14} /> Node Load — CPU &amp; RAM % (last {range}h)
                </h2>
                {points.length === 0 ? (
                    <div className={'py-10 text-center text-sm text-neutral-500'}>
                        Collecting data points... graphs appear within a minute.
                    </div>
                ) : (
                    <canvas ref={canvasRef} style={{ width: '100%', height: 220 }} />
                )}
                <div className={'mt-4 grid grid-cols-2 gap-3 md:grid-cols-4'}>
                    <StatCard icon={<Cpu size={14} />} label={'Latest CPU'} value={points.length ? points[points.length - 1].cpu_percent + '%' : '—'} />
                    <StatCard icon={<MemoryStick size={14} />} label={'Latest RAM'} value={points.length ? points[points.length - 1].memory_percent + '%' : '—'} />
                    <StatCard icon={<HardDrive size={14} />} label={'Allocated Memory'} value={points.length ? Math.round(points[points.length - 1].allocated_memory_percent ?? 0) + '%' : '—'} />
                    <StatCard icon={<Activity size={14} />} label={'Data Points'} value={String(points.length)} />
                </div>
                <p className={'mt-3 flex items-center gap-1 text-xs text-neutral-500'}>
                    <Gauge size={10} /> Metrics are collected on-demand — zero resource cost while this tab is closed.
                </p>
            </div>

            <div className={'mt-4 rounded-lg border border-neutral-700 bg-neutral-800/50 p-5'}>
                <h2 className={'mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-neutral-300'}>
                    <BarChart3 size={14} /> Server Metrics
                </h2>
                <ServerMetricsGraph points={serverPoints} />
            </div>
        </div>
    );
}

const StatCard = ({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) => (
    <div className={'rounded-lg border border-neutral-700 bg-neutral-900 p-3'}>
        <div className={'flex items-center gap-1 text-xs text-neutral-400'}>
            {icon} {label}
        </div>
        <div className={'mt-1 text-lg font-semibold text-neutral-100'}>{value}</div>
    </div>
);

/** Simple line chart for server metrics (CPU % + RAM MB dual axis, canvas). */
function ServerMetricsGraph({ points }: { points: ServerMetricPoint[] }) {
    const ref = useRef<HTMLCanvasElement | null>(null);

    useEffect(() => {
        const canvas = ref.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const dpr = window.devicePixelRatio || 1;
        const w = canvas.clientWidth;
        const h = 200;
        canvas.width = w * dpr;
        canvas.height = h * dpr;
        ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, w, h);
        if (points.length < 2) return;

        const maxCpu = Math.max(...points.map((p) => p.cpu_percent), 10);
        const maxMem = Math.max(...points.map((p) => p.memory_bytes / 1048576), 10);
        const grid = { stroke: '#262b38', text: '#8b93a7' };
        for (let i = 0; i <= 4; i++) {
            const y = 10 + (i * (h - 30)) / 4;
            ctx.strokeStyle = grid.stroke;
            ctx.beginPath();
            ctx.moveTo(45, y);
            ctx.lineTo(w - 10, y);
            ctx.stroke();
            ctx.fillStyle = grid.text;
            ctx.font = '10px sans-serif';
            ctx.fillText(`${Math.round(maxCpu - (i * maxCpu) / 4)}%`, 5, y + 3);
        }

        const draw = (vals: number[], max: number, color: string) => {
            ctx.strokeStyle = color;
            ctx.lineWidth = 2;
            ctx.beginPath();
            vals.forEach((v, i) => {
                const x = 45 + (i / (vals.length - 1)) * (w - 55);
                const y = 10 + (1 - v / max) * (h - 30);
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });
            ctx.stroke();
        };
        draw(points.map((p) => p.cpu_percent), maxCpu, '#8b5cf6');
        draw(points.map((p) => (p.memory_bytes / 1048576) / (maxMem / maxCpu)), maxCpu, '#22d3ee');
    }, [points]);

    return (
        <div>
            {points.length < 2 ? (
                <div className={'py-8 text-center text-sm text-neutral-500'}>Not enough data points yet.</div>
            ) : (
                <canvas ref={ref} style={{ width: '100%', height: 200 }} />
            )}
            <div className={'mt-2 flex gap-4 text-xs text-neutral-400'}>
                <span><span className={'inline-block h-0.5 w-4 bg-violet-500'} /> CPU %</span>
                <span><span className={'inline-block h-0.5 w-4 bg-cyan-500'} /> RAM MB</span>
            </div>
        </div>
    );
}
