import { useEffect, useRef, useState } from 'react';
import { Alert, Button, Input, PageContentBlock, Select, Spinner } from '@pterodactyl/sdk';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';

const CHANNELS = [
    { value: 'both', label: 'Discord + Generic' },
    { value: 'discord', label: 'Discord only' },
    { value: 'generic', label: 'Generic webhook only' },
    { value: 'off', label: 'Disabled' },
];

interface AlertConfig {
    label: string;
    hint: string;
    has_threshold: boolean;
    threshold_default: number | null;
    channel: string;
    threshold: number | null;
}

interface Config {
    discord_webhook: string;
    generic_webhook: string;
    cooldown: number;
    modules: { watchdog: boolean; graphs: boolean; usage: boolean };
    alerts: Record<string, AlertConfig>;
}

interface NodePoint { collected_at: string; cpu_percent: number; memory_percent: number; disk_percent: number; }
interface UsageServer { id: number; name: string; state: string; cpu_percent: number; memory_percent: number; disk_percent: number; memory_used_mb: number; memory_limit_mb: number; disk_used_mb: number; disk_limit_mb: number; suspended: boolean; }
interface NodeInfo { id: number; name: string; fqdn: string; }

/* ── Line chart (canvas) ── */
function LineChart({ series, max, height = 200 }: { series: { data: number[]; color: string; label: string }[]; max: number; height?: number }) {
    const ref = useRef<HTMLCanvasElement | null>(null);
    useEffect(() => {
        const canvas = ref.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const dpr = window.devicePixelRatio || 1;
        const w = canvas.clientWidth;
        canvas.width = w * dpr;
        canvas.height = height * dpr;
        ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, w, height);
        ctx.strokeStyle = 'rgba(139,147,167,0.25)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = 10 + (i * (height - 30)) / 4;
            ctx.beginPath();
            ctx.moveTo(45, y);
            ctx.lineTo(w - 10, y);
            ctx.stroke();
            ctx.fillStyle = '#8b93a7';
            ctx.font = '10px sans-serif';
            ctx.fillText(`${Math.round(max - (i * max) / 4)}`, 5, y + 3);
        }
        const maxLen = Math.max(...series.map((s) => s.data.length), 1);
        if (maxLen <= 1) return;
        for (const s of series) {
            ctx.strokeStyle = s.color;
            ctx.lineWidth = 2;
            ctx.beginPath();
            s.data.forEach((v, i) => {
                const x = 45 + (i / (maxLen - 1)) * (w - 55);
                const y = 10 + (1 - Math.min(v, max) / max) * (height - 30);
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });
            ctx.stroke();
        }
        let lx = 45;
        for (const s of series) {
            ctx.fillStyle = s.color;
            ctx.fillRect(lx, 4, 10, 3);
            ctx.fillStyle = '#8b93a7';
            ctx.fillText(s.label, lx + 14, 9);
            lx += 14 + ctx.measureText(s.label).width + 16;
        }
    }, [series, max, height]);
    return <canvas ref={ref} style={{ width: '100%', height }} />;
}

/* ── Graph Monitor tab ── */
function GraphTab() {
    const [nodes, setNodes] = useState<NodeInfo[]>([]);
    const [selected, setSelected] = useState<number | null>(null);
    const [points, setPoints] = useState<NodePoint[]>([]);
    const [hours, setHours] = useState(6);
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        http.get('/api/admin/nodes').then(({ data }) => {
            const list = (data.data || []).map((n: any) => ({ id: n.attributes.id, name: n.attributes.name, fqdn: n.attributes.fqdn }));
            setNodes(list);
            if (list.length > 0) setSelected(list[0].id);
        });
    }, []);

    useEffect(() => {
        if (selected === null) return;
        const tick = async () => {
            try {
                const { data } = await http.get(`/api/admin/extensions/zenith-monitor/nodes/${selected}/graphs?hours=${hours}`);
                setPoints(data?.data ?? []);
            } catch (e) {
                console.error('[zenith] graphs fetch failed', e);
            }
        };
        tick();
        timer.current = setInterval(tick, 3000);
        return () => { if (timer.current) clearInterval(timer.current); };
    }, [selected, hours]);

    return (
        <div>
            <p className={'zn:mb-4 zn:text-sm zn:text-muted-foreground'}>Per-node CPU / RAM / Storage — auto-updates every 3s while this tab is open (on-demand collection).</p>
            <div className={'zn:mb-4 zn:flex zn:flex-wrap zn:gap-2'}>
                {nodes.map((n) => (
                    <button
                        key={n.id}
                        onClick={() => setSelected(n.id)}
                        className={`zn:rounded-lg zn:border zn:px-4 zn:py-2 zn:text-sm ${
                            selected === n.id ? 'zn:border-violet-500 zn:bg-violet-500/10 zn:text-violet-300' : 'zn:border-border zn:text-foreground'
                        }`}
                    >
                        {n.name} <span className={'zn:text-xs zn:text-muted-foreground'}>{n.fqdn}</span>
                    </button>
                ))}
            </div>
            <div className={'zn:mb-4 zn:flex zn:items-center zn:gap-3'}>
                <label className={'zn:text-sm'}>Range:</label>
                <select className={'zn:rounded zn:border zn:border-border zn:bg-transparent zn:px-3 zn:py-1.5'} value={hours} onChange={(e) => setHours(parseInt(e.target.value))}>
                    <option value={1}>Last hour</option>
                    <option value={6}>Last 6 hours</option>
                    <option value={24}>Last 24 hours</option>
                    <option value={48}>Last 48 hours</option>
                </select>
            </div>
            {points.length === 0 ? (
                <Spinner size={'large'} centered />
            ) : (
                <div className={'zn:grid zn:gap-4'}>
                    <div className={'zn:rounded-lg zn:border zn:border-border zn:p-5'}>
                        <h2 className={'zn:mb-3 zn:text-sm zn:font-semibold zn:uppercase zn:text-muted-foreground'}>CPU Usage %</h2>
                        <LineChart series={[{ data: points.map((p) => p.cpu_percent), color: '#8b5cf6', label: 'CPU %' }]} max={100} />
                    </div>
                    <div className={'zn:rounded-lg zn:border zn:border-border zn:p-5'}>
                        <h2 className={'zn:mb-3 zn:text-sm zn:font-semibold zn:uppercase zn:text-muted-foreground'}>RAM Usage %</h2>
                        <LineChart series={[{ data: points.map((p) => p.memory_percent), color: '#22d3ee', label: 'RAM %' }]} max={100} />
                    </div>
                    <div className={'zn:rounded-lg zn:border zn:border-border zn:p-5'}>
                        <h2 className={'zn:mb-3 zn:text-sm zn:font-semibold zn:uppercase zn:text-muted-foreground'}>Storage Usage %</h2>
                        <LineChart series={[{ data: points.map((p) => p.disk_percent), color: '#34d399', label: 'Disk %' }]} max={100} />
                    </div>
                </div>
            )}
        </div>
    );
}

/* ── Server Usage tab ── */
function UsageTab() {
    const [nodes, setNodes] = useState<NodeInfo[]>([]);
    const [selected, setSelected] = useState<number | null>(null);
    const [servers, setServers] = useState<UsageServer[]>([]);
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        http.get('/api/admin/nodes').then(({ data }) => {
            const list = (data.data || []).map((n: any) => ({ id: n.attributes.id, name: n.attributes.name, fqdn: n.attributes.fqdn }));
            setNodes(list);
            if (list.length > 0) setSelected(list[0].id);
        });
    }, []);

    useEffect(() => {
        if (selected === null) return;
        const tick = async () => {
            const { data } = await http.get(`/api/admin/extensions/zenith-monitor/nodes/${selected}/server-usage`);
            setServers(data.data || []);
        };
        tick();
        timer.current = setInterval(tick, 3000);
        return () => { if (timer.current) clearInterval(timer.current); };
    }, [selected]);

    const bar = (pct: number, color: string) => (
        <div className={'zn:h-2 zn:w-full zn:rounded zn:bg-muted'}>
            <div className={`zn:h-2 zn:rounded ${color}`} style={{ width: `${Math.min(pct, 100)}%` }} />
        </div>
    );

    return (
        <div>
            <p className={'zn:mb-4 zn:text-sm zn:text-muted-foreground'}>Live per-server usage on the selected node — refreshes every 3s.</p>
            <div className={'zn:mb-4 zn:flex zn:flex-wrap zn:gap-2'}>
                {nodes.map((n) => (
                    <button
                        key={n.id}
                        onClick={() => setSelected(n.id)}
                        className={`zn:rounded-lg zn:border zn:px-4 zn:py-2 zn:text-sm ${
                            selected === n.id ? 'zn:border-violet-500 zn:bg-violet-500/10 zn:text-violet-300' : 'zn:border-border zn:text-foreground'
                        }`}
                    >
                        {n.name}
                    </button>
                ))}
            </div>
            <div className={'zn:grid zn:gap-3 md:zn:grid-cols-2'}>
                {servers.map((s) => (
                    <div key={s.id} className={'zn:rounded-lg zn:border zn:border-border zn:p-4'}>
                        <div className={'zn:mb-2 zn:flex zn:items-center zn:justify-between'}>
                            <span className={'zn:font-medium'}>{s.name}</span>
                            <span className={`zn:rounded zn:px-2 zn:py-0.5 zn:text-xs ${s.suspended ? 'zn:bg-red-500/20 zn:text-red-400' : s.state === 'running' ? 'zn:bg-green-500/20 zn:text-green-400' : 'zn:bg-muted zn:text-muted-foreground'}`}>
                                {s.suspended ? 'suspended' : s.state}
                            </span>
                        </div>
                        <div className={'zn:space-y-2 zn:text-xs zn:text-muted-foreground'}>
                            <div>
                                <div className={'zn:mb-1 zn:flex zn:justify-between'}><span>CPU</span><span>{s.cpu_percent}% / {s.cpu_limit}%</span></div>
                                {bar(s.cpu_percent, 'zn:bg-violet-500')}
                            </div>
                            <div>
                                <div className={'zn:mb-1 zn:flex zn:justify-between'}><span>RAM</span><span>{s.memory_used_mb}MB / {s.memory_limit_mb}MB</span></div>
                                {bar(s.memory_percent, 'zn:bg-cyan-500')}
                            </div>
                            <div>
                                <div className={'zn:mb-1 zn:flex zn:justify-between'}><span>Disk</span><span>{s.disk_used_mb}MB / {s.disk_limit_mb}MB</span></div>
                                {bar(s.disk_percent, 'zn:bg-emerald-500')}
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/* ── Watchdog (alert settings) tab ── */
function WatchdogTab({ settings, setSettings, setMessage, setSaving, setTesting, setTestResults }: any) {
    const updateAlert = (type: string, patch: Partial<AlertConfig>) => {
        setSettings({ ...settings, alerts: { ...settings.alerts, [type]: { ...settings.alerts[type], ...patch } } });
    };
    const save = async () => {
        setSaving(true);
        try {
            const alerts: Record<string, { channel: string; threshold?: number | null }> = {};
            for (const [type, cfg] of Object.entries(settings.alerts)) {
                alerts[type] = { channel: cfg.channel, threshold: cfg.has_threshold ? cfg.threshold : null };
            }
            await http.post('/api/admin/extensions/zenith-monitor/config', {
                discord_webhook: settings.discord_webhook,
                generic_webhook: settings.generic_webhook,
                cooldown: parseInt(String(settings.cooldown)) || 900,
                modules: settings.modules,
                alerts,
            });
            setMessage({ type: 'success', text: 'Settings saved — applied from the next watchdog cycle.' });
        } catch (e) {
            setMessage({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setSaving(false);
        }
    };
    const test = async () => {
        setTesting(true);
        try {
            const { data } = await http.post('/api/admin/extensions/zenith-monitor/test');
            setTestResults(data.results || {});
        } catch (e) {
            setMessage({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setTesting(false);
        }
    };

    return (
        <div>
            <p className={'zn:mb-4 zn:text-sm zn:text-muted-foreground'}>Watchdog protection, notification channels and alert thresholds.</p>
            <div className={'zn:mb-6 zn:rounded-lg zn:border zn:border-border zn:bg-card zn:p-5'}>
                <h2 className={'zn:mb-4 zn:text-sm zn:font-semibold zn:uppercase zn:tracking-wide zn:text-muted-foreground'}>Notification Channels</h2>
                <div className={'zn:grid zn:gap-4 md:zn:grid-cols-2'}>
                    <div>
                        <label className={'zn:mb-1 zn:block zn:text-sm'}>Discord Webhook</label>
                        <Input value={settings.discord_webhook} onChange={(e: any) => setSettings({ ...settings, discord_webhook: e.target.value })} placeholder={'https://discord.com/api/webhooks/...'} />
                    </div>
                    <div>
                        <label className={'zn:mb-1 zn:block zn:text-sm'}>Generic Webhook (ntfy / Slack / custom)</label>
                        <Input value={settings.generic_webhook} onChange={(e: any) => setSettings({ ...settings, generic_webhook: e.target.value })} placeholder={'https://ntfy.sh/your-topic'} />
                    </div>
                </div>
                <div className={'zn:mt-4'}>
                    <label className={'zn:mb-1 zn:block zn:text-sm'}>Alert Repeat Interval (seconds)</label>
                    <Input type={'number'} value={settings.cooldown} onChange={(e: any) => setSettings({ ...settings, cooldown: parseInt(e.target.value) || 900 })} />
                </div>
            </div>

            <div className={'zn:mb-6 zn:rounded-lg zn:border zn:border-border'}>
                <table className={'zn:w-full zn:text-sm'}>
                    <thead>
                        <tr className={'zn:border-b zn:border-border zn:text-left zn:text-xs zn:uppercase zn:text-muted-foreground'}>
                            <th className={'zn:px-4 zn:py-3'}>Alert</th>
                            <th className={'zn:px-4 zn:py-3'}>Send To</th>
                            <th className={'zn:px-4 zn:py-3'}>Threshold (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {Object.entries(settings.alerts).map(([type, cfg]) => (
                            <tr key={type} className={'zn:border-b zn:border-border'}>
                                <td className={'zn:px-4 zn:py-3'}>
                                    <div className={'zn:font-medium'}>{cfg.label}</div>
                                    <div className={'zn:text-xs zn:text-muted-foreground'}>{cfg.hint}</div>
                                </td>
                                <td className={'zn:px-4 zn:py-3'}>
                                    <Select value={cfg.channel} onChange={(v: any) => updateAlert(type, { channel: String(v) })} options={CHANNELS} />
                                </td>
                                <td className={'zn:px-4 zn:py-3'}>
                                    {cfg.has_threshold ? (
                                        <Input type={'number'} value={cfg.threshold ?? ''} onChange={(e: any) => updateAlert(type, { threshold: parseInt(e.target.value) })} />
                                    ) : (
                                        <span className={'zn:text-muted-foreground'}>—</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className={'zn:flex zn:items-center zn:gap-3'}>
                <Button onClick={save}>Save Settings</Button>
                <Button variant={"secondary"} onClick={test}>Send Test</Button>
            </div>
        </div>
    );
}

/* ── MAIN HUB ── */
const TABS = [
    { id: 'watchdog', label: 'Watchdog', icon: '🛡' },
    { id: 'graphs', label: 'Graph Monitor', icon: '📊' },
    { id: 'usage', label: 'Server Usage', icon: '📈' },
];

export default function ZenithControlScreen() {
    const [settings, setSettings] = useState<Config | null>(null);
    const [tab, setTab] = useState<string>('watchdog');
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [message, setMessage] = useState<{ type: string; text: string } | null>(null);
    const [testResults, setTestResults] = useState<Record<string, string> | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    useEffect(() => {
        http.get('/api/admin/extensions/zenith-monitor/config')
            .then(({ data: body }) => {
                const payload = body?.data?.alerts ? body.data : body;
                if (!payload?.alerts) throw new Error('Invalid settings response');
                setSettings(payload);
            })
            .catch((e) => setLoadError(httpErrorToHuman(e)));
    }, []);

    if (loadError) {
        return (
            <PageContentBlock title={'Zenith Control'}>
                <Alert type={'danger'}>{loadError}</Alert>
            </PageContentBlock>
        );
    }
    if (!settings) {
        return <PageContentBlock title={'Zenith Control'}><Spinner size={'large'} centered /></PageContentBlock>;
    }

    const visibleTabs = TABS.filter((t) => (settings.modules as any)?.[t.id] !== false);

    return (
        <PageContentBlock title={'Zenith Control'}>
            <h1 className={'zn:mb-1 zn:flex zn:items-center zn:gap-2 zn:text-2xl zn:font-bold'}>🛡 Zenith Control</h1>
            <p className={'zn:mb-4 zn:text-sm zn:text-muted-foreground'}>Watchdog protection, live resource graphs and server usage — the Zenith suite.</p>

            {/* tabs */}
            <div className={'zn:mb-6 zn:flex zn:gap-1 zn:border-b zn:border-border'}>
                {visibleTabs.map((t) => (
                    <button
                        key={t.id}
                        onClick={() => setTab(t.id)}
                        className={`zn:px-4 zn:py-2 zn:text-sm zn:transition ${
                            tab === t.id
                                ? 'zn:border-b-2 zn:border-violet-500 zn:font-medium zn:text-violet-300'
                                : 'zn:text-muted-foreground hover:zn:text-foreground'
                        }`}
                    >
                        <span className={'zn:mr-1.5'}>{t.icon}</span>{t.label}
                    </button>
                ))}
            </div>

            {message && <Alert type={message.type === 'success' ? 'success' : 'danger'}>{message.text}</Alert>}
            {testResults && Object.keys(testResults).length > 0 && (
                <Alert type={'info'}>
                    {Object.entries(testResults).map(([ch, res]) => (
                        <div key={ch} className={'zn:text-sm'}>
                            <span className={'zn:font-semibold zn:capitalize'}>{ch}</span>:{' '}
                            <span className={res === 'sent' ? 'zn:text-green-500' : 'zn:text-red-500'}>{res}</span>
                        </div>
                    ))}
                </Alert>
            )}

            <div className={'zn:mt-4'}>
                {tab === 'watchdog' && (
                    <WatchdogTab
                        settings={settings}
                        setSettings={setSettings}
                        setMessage={setMessage}
                        setSaving={setSaving}
                        setTesting={setTesting}
                        setTestResults={setTestResults}
                    />
                )}
                {tab === 'graphs' && <GraphTab />}
                {tab === 'usage' && <UsageTab />}
            </div>
        </PageContentBlock>
    );
}
