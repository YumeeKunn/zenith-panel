import { useEffect, useState } from 'react';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';
import { Alert, Button, Input, PageContentBlock, Select, Spinner } from '@pterodactyl/sdk';

interface Row { server_id: number; url: string; server_name: string | null; }

export default function AdminScreen() {
    const [rows, setRows] = useState<Row[]>([]);
    const [servers, setServers] = useState<{ id: number; name: string }[]>([]);
    const [serverId, setServerId] = useState<number | null>(null);
    const [url, setUrl] = useState('');
    const [saving, setSaving] = useState(false);
    const [msg, setMsg] = useState<{ type: string; text: string } | null>(null);
    const [loading, setLoading] = useState(true);

    const load = () => {
        Promise.all([
            http.get('/api/admin/extensions/serverbackgrounds/backgrounds'),
            http.get('/api/admin/servers?per_page=100'),
        ]).then(([bg, sv]) => {
            setRows(bg.data.data || []);
            setServers((sv.data.data || []).map((s: any) => ({ id: s.attributes.id, name: s.attributes.name })));
            setLoading(false);
        }).catch(() => setLoading(false));
    };
    useEffect(() => { load(); }, []);

    const save = async () => {
        if (!serverId) return;
        setSaving(true);
        try {
            await http.post('/api/admin/extensions/serverbackgrounds/backgrounds', { server_id: serverId, url });
            setMsg({ type: 'success', text: 'Background saved — dashboard pe live update ho jayega.' });
            load();
        } catch (e) {
            setMsg({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setSaving(false);
        }
    };

    const remove = async (id: number) => {
        await http.delete('/api/admin/extensions/serverbackgrounds/backgrounds', { data: { server_id: id } });
        load();
    };

    return (
        <PageContentBlock title={'Server Backgrounds'}>
            <h1 className={'sb:text-2xl sb:font-bold sb:mb-1'}>Server Backgrounds</h1>
            <p className={'sb:mb-4 sb:text-sm sb:text-muted-foreground'}>
                Set a custom background image per server — shows on the dashboard server cards.
            </p>
            {msg && <Alert type={msg.type === 'success' ? 'success' : 'danger'} className={'sb:mb-4'}>{msg.text}</Alert>}
            {loading ? <Spinner size={'large'} centered /> : (
                <div className={'sb:space-y-4'}>
                    <div className={'sb:rounded-lg sb:border sb:border-border sb:p-4 sb:space-y-3'}>
                        <label className={'sb:block sb:text-sm sb:font-medium'}>Assign background</label>
                        <Select
                            value={serverId}
                            onChange={(v: any) => setServerId(Number(v))}
                            options={servers.map((s) => ({ value: s.id, label: s.name }))}
                        />
                        <Input value={url} onChange={(e: any) => setUrl(e.target.value)} placeholder={'https://example.com/background.jpg'} />
                        <Button onClick={save} disabled={saving || !serverId || !url}>{saving ? 'Saving...' : 'Assign Background'}</Button>
                    </div>
                    <div className={'sb:rounded-lg sb:border sb:border-border'}>
                        <table className={'sb:w-full sb:text-sm'}>
                            <thead><tr className={'sb:border-b sb:border-border sb:text-left sb:text-xs sb:uppercase sb:text-muted-foreground'}>
                                <th className={'sb:px-4 sb:py-2'}>Server</th><th className={'sb:px-4 sb:py-2'}>Background</th><th></th></tr></thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.server_id} className={'sb:border-b sb:border-border'}>
                                        <td className={'sb:px-4 sb:py-2 sb:font-medium'}>{r.server_name || `#${r.server_id}`}</td>
                                        <td className={'sb:px-4 sb:py-2 sb:font-mono sb:text-xs'}>{r.url}</td>
                                        <td className={'sb:px-4 sb:py-2'}><button className={'sb:text-red-400'} onClick={() => remove(r.server_id)}>remove</button></td>
                                    </tr>
                                ))}
                                {rows.length === 0 && <tr><td colSpan={3} className={'sb:px-4 sb:py-4 sb:text-muted-foreground'}>No backgrounds configured.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </PageContentBlock>
    );
}
