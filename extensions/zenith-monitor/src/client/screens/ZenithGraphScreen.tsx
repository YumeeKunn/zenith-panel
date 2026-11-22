import { useEffect, useRef, useState } from 'react';
import { http } from '@pterodactyl/sdk/api';
import { PageContentBlock, Spinner } from '@pterodactyl/sdk';

interface Point { collected_at: string; cpu_percent: number; memory_percent: number; allocated_memory_percent: number | null; allocated_disk_percent: number | null; }

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
    }, [series, max]);
    return <canvas ref={ref} style={{ width: '100%', height }} />;
}

export default function ZenithGraphScreen() {
    const [nodes, setNodes] = useState<{ id: number; name: string; fqdn: string }[]>([]);
    const [selected, setSelected] = useState<number | null>(null);
    const [points, setPoints] = useState<Point[]>([]);
    const [hours, setHours] = useState(6);
    const [loading, setLoading] = useState(true);
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        http.get('/api/admin/nodes').then(({ data }) => {
            const list = (data.data || []).map((n: any) => ({ id: n.attributes.id, name: n.attributes.name, fqdn: n.attributes.fqdn }));
            setNodes(list);
            if (list.length > 0) setSelected(list[0].id);
        });
        return () => {};
    }, []);

    useEffect(() => {
        if (selected === null) return;
        const tick = async () => {
            // on-demand collect — graph tab open = collecting
            await http.get(`/api/admin/extensions/zenith-monitor/nodes/${selected}/graphs?hours=${hours}`);
            const { data } = await http.get(`/api/admin/extensions/zenith-monitor/nodes/${selected}/graphs?hours=${hours}`);
            setPoints(data.data || []);
            setLoading(false);
        };
        tick();
        timer.current = setInterval(tick, 3000);
        return () => {
            if (timer.current) clearInterval(timer.current);
        };
    }, [selected, hours]);

    const last = points.length > 0 ? points[points.length - 1] : null;

    return (
        <PageContentBlock title={'Zenith Graphs'}>
            <h1 className={'zn:text-2xl zn:font-bold zn:text-foreground zn:mb-1'}>📊 Node Graphs</h1>
            <p className={'zn:mb-4 zn:text-sm zn:text-muted-foreground'}>Per-node CPU / RAM / Storage. Auto-updates every 3s while this tab is open.</p>

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

            {loading && points.length === 0 ? (
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
        </PageContentBlock>
    );
}
