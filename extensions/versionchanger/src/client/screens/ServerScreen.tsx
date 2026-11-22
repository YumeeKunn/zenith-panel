import { useEffect, useState } from 'react';
import { useParams } from '@pterodactyl/sdk';
import { http } from '@pterodactyl/sdk/api';
import { Button, PageContentBlock, Select, Spinner, Alert } from '@pterodactyl/sdk';

interface Versions { [software: string]: string[] }

export default function ServerScreen() {
    const { id } = useParams<{ id: string }>();
    const [versions, setVersions] = useState<Versions | null>(null);
    const [software, setSoftware] = useState<string>('Paper');
    const [version, setVersion] = useState<string>('');
    const [current, setCurrent] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [switching, setSwitching] = useState(false);
    const [message, setMessage] = useState<{ type: string; text: string } | null>(null);

    useEffect(() => {
        http.get(`/api/client/servers/${id}/extensions/versionchanger/versions`)
            .then(({ data }) => {
                setVersions(data.software);
                setCurrent(data.current);
            })
            .finally(() => setLoading(false));
    }, [id]);

    const doSwitch = async () => {
        setSwitching(true);
        setMessage(null);
        try {
            const { data } = await http.post(`/api/client/servers/${id}/extensions/versionchanger/switch`, { software, version });
            setMessage({ type: 'success', text: data.message });
            setCurrent(version);
        } catch (e: any) {
            setMessage({ type: 'danger', text: e.response?.data?.error || 'Switch failed' });
        } finally {
            setSwitching(false);
        }
    };

    return (
        <PageContentBlock title={'Version Changer'}>
            <h1 className={'vc:text-xl vc:font-semibold vc:text-foreground vc:mb-1'}>Version Changer</h1>
            <p className={'vc:mb-4 vc:text-sm vc:text-muted-foreground'}>
                Switch your Minecraft server software and version. Reinstall runs automatically — world files stay safe.
            </p>
            {current && (
                <div className={'vc:mb-4 vc:rounded-md vc:border vc:border-border vc:p-3 vc:text-sm'}>
                    Current version: <strong>{current}</strong>
                </div>
            )}
            {message && <Alert type={message.type === 'success' ? 'success' : 'danger'}>{message.text}</Alert>}
            {loading || !versions ? (
                <Spinner size={'large'} centered />
            ) : (
                <div className={'vc:space-y-4'}>
                    <div>
                        <label className={'vc:mb-1 vc:block vc:text-sm'}>Software</label>
                        <Select
                            value={software}
                            onChange={(v) => { setSoftware(String(v)); setVersion(''); }}
                            options={Object.keys(versions).map((k) => ({ value: k, label: k }))}
                        />
                    </div>
                    <div>
                        <label className={'vc:mb-1 vc:block vc:text-sm'}>Version</label>
                        <Select
                            value={version}
                            onChange={(v) => setVersion(String(v))}
                            options={(versions[software] || []).map((v: string) => ({ value: v, label: v }))}
                        />
                    </div>
                    <Button onClick={doSwitch} disabled={switching || !version}>
                        {switching ? 'Switching...' : 'Switch & Reinstall'}
                    </Button>
                    <p className={'vc:text-xs vc:text-muted-foreground'}>
                        Reinstall downloads the new jar. Your worlds and configs are kept.
                    </p>
                </div>
            )}
        </PageContentBlock>
    );
}
