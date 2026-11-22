import { useEffect, useState } from 'react';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';
import { Alert, Button, Input, PageContentBlock, Spinner } from '@pterodactyl/sdk';

export default function AdminScreen() {
    const [presets, setPresets] = useState<Record<string, string>>({});
    const [key, setKey] = useState('');
    const [cmd, setCmd] = useState('');
    const [saving, setSaving] = useState(false);
    const [msg, setMsg] = useState<{ type: string; text: string } | null>(null);
    const [loading, setLoading] = useState(true);

    const load = () => http.get('/api/admin/extensions/startupchanger/presets')
        .then(({ data }) => { setPresets(data.presets || {}); setLoading(false); })
        .catch(() => setLoading(false));
    useEffect(() => { load(); }, []);

    const save = async () => {
        setSaving(true);
        try {
            await http.post('/api/admin/extensions/startupchanger/presets', { key, startup: cmd });
            setMsg({ type: 'success', text: `Preset "${key}" saved.` });
            setKey(''); setCmd('');
            load();
        } catch (e) {
            setMsg({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setSaving(false);
        }
    };

    const remove = async (k: string) => {
        await http.delete(`/api/admin/extensions/startupchanger/presets/${k}`);
        load();
    };

    return (
        <PageContentBlock title={'Startup Changer'}>
            <h1 className={'stc:text-2xl stc:font-bold stc:mb-1'}>Startup Changer — Presets</h1>
            <p className={'stc:mb-4 stc:text-sm stc:text-muted-foreground'}>Define safe startup command presets users can apply on their servers.</p>
            {msg && <Alert type={msg.type === 'success' ? 'success' : 'danger'} className={'stc:mb-4'}>{msg.text}</Alert>}
            {loading ? <Spinner size={'large'} centered /> : (
                <div className={'stc:space-y-4'}>
                    <div className={'stc:rounded-lg stc:border stc:border-border'}>
                        <table className={'stc:w-full stc:text-sm'}>
                            <thead><tr className={'stc:border-b stc:border-border stc:text-left stc:text-xs stc:uppercase stc:text-muted-foreground'}><th className={'stc:px-4 stc:py-2'}>Name</th><th className={'stc:px-4 stc:py-2'}>Command</th><th></th></tr></thead>
                            <tbody>
                                {Object.entries(presets).map(([k, v]) => (
                                    <tr key={k} className={'stc:border-b stc:border-border'}>
                                        <td className={'stc:px-4 stc:py-2 stc:font-medium'}>{k}</td>
                                        <td className={'stc:px-4 stc:py-2 stc:font-mono stc:text-xs'}>{v}</td>
                                        <td className={'stc:px-4 stc:py-2'}><button className={'stc:text-red-400'} onClick={() => remove(k)}>remove</button></td>
                                    </tr>
                                ))}
                                {Object.keys(presets).length === 0 && <tr><td colSpan={3} className={'stc:px-4 stc:py-4 stc:text-muted-foreground'}>No presets yet.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                    <div className={'stc:flex stc:gap-2'}>
                        <Input value={key} onChange={(e: any) => setKey(e.target.value)} placeholder={'Preset name'} className={'stc:w-44'} />
                        <Input value={cmd} onChange={(e: any) => setCmd(e.target.value)} placeholder={'java -Xms128M -Xmx...'} className={'stc:flex-1'} />
                        <Button onClick={save} disabled={saving || !key || !cmd}>Add Preset</Button>
                    </div>
                </div>
            )}
        </PageContentBlock>
    );
}
