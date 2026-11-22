import { useEffect, useState } from 'react';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';
import { Alert, Button, Input, PageContentBlock, Spinner } from '@pterodactyl/sdk';

export default function AdminScreen() {
    const [token, setToken] = useState('');
    const [zone, setZone] = useState('');
    const [domain, setDomain] = useState('');
    const [ip, setIp] = useState('');
    const [saving, setSaving] = useState(false);
    const [msg, setMsg] = useState<{ type: string; text: string } | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        http.get('/api/admin/extensions/subdomains/cfconfig').then(({ data }) => {
            const v: any = {};
            (data.schema || []).forEach((s: any) => { v[s.input] = s.value; });
            setToken(v.cf_token || '');
            setZone(v.cf_zone || '');
            setDomain(v.domain || '');
            setIp(v.server_ip || '');
            setLoading(false);
        }).catch(() => setLoading(false));
    }, []);

    const save = async () => {
        setSaving(true);
        try {
            await http.post('/api/admin/extensions/subdomains/cfconfig', {
                cf_token: token, cf_zone: zone, domain, server_ip: ip,
            });
            setMsg({ type: 'success', text: 'Cloudflare configuration saved.' });
        } catch (e) {
            setMsg({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <PageContentBlock title={'SubDomains'}>
            <h1 className={'sd:text-2xl sd:font-bold sd:mb-1'}>SubDomains — Cloudflare Settings</h1>
            <p className={'sd:mb-4 sd:text-sm sd:text-muted-foreground'}>Configure the Cloudflare API token and the domain users can claim subdomains on.</p>
            {msg && <Alert type={msg.type === 'success' ? 'success' : 'danger'} className={'sd:mb-4'}>{msg.text}</Alert>}
            {loading ? <Spinner size={'large'} centered /> : (
                <div className={'sd:space-y-4'}>
                    <div><label className={'sd:mb-1 sd:block sd:text-sm sd:font-medium'}>Cloudflare API Token</label><Input value={token} onChange={(e: any) => setToken(e.target.value)} /></div>
                    <div><label className={'sd:mb-1 sd:block sd:text-sm sd:font-medium'}>Zone ID</label><Input value={zone} onChange={(e: any) => setZone(e.target.value)} /></div>
                    <div><label className={'sd:mb-1 sd:block sd:text-sm sd:font-medium'}>Domain</label><Input value={domain} onChange={(e: any) => setDomain(e.target.value)} placeholder={'yourdomain.com'} /></div>
                    <div><label className={'sd:mb-1 sd:block sd:text-sm sd:font-medium'}>Server IP (A record target)</label><Input value={ip} onChange={(e: any) => setIp(e.target.value)} /></div>
                    <Button onClick={save} disabled={saving}>{saving ? 'Saving...' : 'Save Configuration'}</Button>
                </div>
            )}
        </PageContentBlock>
    );
}
