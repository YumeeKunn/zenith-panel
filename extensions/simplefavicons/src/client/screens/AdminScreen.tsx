import { useEffect, useState } from 'react';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';
import { Alert, Button, Input, PageContentBlock, Spinner } from '@pterodactyl/sdk';

export default function AdminScreen() {
    const [url, setUrl] = useState('');
    const [saving, setSaving] = useState(false);
    const [msg, setMsg] = useState<{ type: string; text: string } | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        http.get('/api/admin/extensions/simplefavicons/favicon')
            .then(({ data }) => { setUrl(data.url || ''); setLoading(false); })
            .catch(() => setLoading(false));
    }, []);

    const save = async () => {
        setSaving(true);
        try {
            await http.post('/api/admin/extensions/simplefavicons/favicon', { url });
            setMsg({ type: 'success', text: 'Favicon saved — refresh the panel to see it.' });
        } catch (e) {
            setMsg({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <PageContentBlock title={'Simple Favicons'}>
            <h1 className={'sf:text-2xl sf:font-bold sf:mb-1'}>Simple Favicons</h1>
            <p className={'sf:mb-4 sf:text-sm sf:text-muted-foreground'}>
                Set a custom favicon for the panel. Applies panel-wide instantly.
            </p>
            {msg && <Alert type={msg.type === 'success' ? 'success' : 'danger'} className={'sf:mb-4'}>{msg.text}</Alert>}
            {loading ? <Spinner size={'large'} centered /> : (
                <div className={'sf:space-y-4'}>
                    <div>
                        <label className={'sf:mb-1.5 sf:block sf:text-sm sf:font-medium'}>Favicon URL</label>
                        <Input value={url} onChange={(e: any) => setUrl(e.target.value)} placeholder={'https://example.com/favicon.png'} />
                        {url && <img src={url} className={'sf:mt-2 sf:h-8 sf:w-8'} alt={'favicon preview'} />}
                    </div>
                    <Button onClick={save} disabled={saving}>{saving ? 'Saving...' : 'Save Favicon'}</Button>
                </div>
            )}
        </PageContentBlock>
    );
}
