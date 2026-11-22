import { useEffect, useState } from 'react';
import { useCurrentServer, useCurrentServerUuid } from '@pterodactyl/sdk';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';
import { Alert, Button, Input, PageContentBlock, Spinner } from '@pterodactyl/sdk';

export default function ServerScreen() {
    const uuid = useCurrentServerUuid();
    const server = useCurrentServer((s: any) => s?.attributes);
    const [sub, setSub] = useState('');
    const [port, setPort] = useState('');
    const [config, setConfig] = useState<{ configured: boolean; domain: string } | null>(null);
    const [creating, setCreating] = useState(false);
    const [message, setMessage] = useState<{ type: string; text: string } | null>(null);

    useEffect(() => {
        if (!uuid) return;
        http.get('/api/admin/extensions/subdomains/config')
            .then(({ data }) => setConfig(data))
            .catch(() => setConfig({ configured: false, domain: '' }));
    }, [uuid]);

    const create = async () => {
        setCreating(true);
        setMessage(null);
        try {
            const { data } = await http.post(`/api/client/servers/${uuid}/extensions/subdomains/create`, {
                sub,
                port: port ? parseInt(port) : undefined,
            });
            setMessage({ type: 'success', text: `Subdomain created: ${data.fqdn} (SRV points to port ${data.port})` });
        } catch (e: any) {
            setMessage({ type: 'danger', text: e.response?.data?.error || httpErrorToHuman(e) });
        } finally {
            setCreating(false);
        }
    };

    return (
        <PageContentBlock title={'Subdomain'}>
            <h1 className={'sd:text-2xl sd:font-bold sd:mb-1'}>Subdomain Manager</h1>
            <p className={'sd:mb-4 sd:text-sm sd:text-muted-foreground'}>
                Give your server a friendly address like <code>play.yourdomain.com</code> — an A record + Minecraft SRV record is created automatically.
            </p>

            {message && <Alert type={message.type === 'success' ? 'success' : 'danger'} className={'sd:mb-4'}>{message.text}</Alert>}

            {!config ? (
                <Spinner size={'large'} centered />
            ) : !config.configured ? (
                <Alert type={'info'}>
                    Subdomains are not configured yet. An administrator needs to set the Cloudflare API token and domain in the extension settings.
                </Alert>
            ) : (
                <div className={'sd:space-y-4'}>
                    <div>
                        <label className={'sd:mb-1.5 sd:block sd:text-sm sd:font-medium'}>Subdomain</label>
                        <div className={'sd:flex sd:items-center sd:gap-2'}>
                            <Input value={sub} onChange={(e: any) => setSub(e.target.value.toLowerCase())} placeholder={'play'} className={'sd:w-48'} />
                            <span className={'sd:text-muted-foreground'}>.{config.domain}</span>
                        </div>
                    </div>
                    <div>
                        <label className={'sd:mb-1.5 sd:block sd:text-sm sd:font-medium'}>Port (defaults to your server's primary allocation)</label>
                        <Input value={port} onChange={(e: any) => setPort(e.target.value)} placeholder={server?.relationships?.allocations?.data?.[0]?.attributes?.port || '25565'} className={'sd:w-48'} />
                    </div>
                    <Button onClick={create} disabled={creating || !sub}>{creating ? 'Creating...' : 'Create Subdomain'}</Button>
                </div>
            )}
        </PageContentBlock>
    );
}
