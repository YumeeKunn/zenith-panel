import { useEffect, useState } from 'react';
import { useCurrentServerUuid } from '@pterodactyl/sdk';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';
import { Alert, Button, PageContentBlock, Select, Spinner } from '@pterodactyl/sdk';

interface Versions { [software: string]: string[] }

export default function ServerScreen() {
    const uuid = useCurrentServerUuid();
    const [versions, setVersions] = useState<Versions | null>(null);
    const [software, setSoftware] = useState<string>('Paper');
    const [version, setVersion] = useState<string>('');
    const [current, setCurrent] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [switching, setSwitching] = useState(false);
    const [message, setMessage] = useState<{ type: 'success' | 'danger'; text: string } | null>(null);
    const [confirming, setConfirming] = useState(false);

    useEffect(() => {
        if (!uuid) return;
        http.get(`/api/client/servers/${uuid}/extensions/versionchanger/versions`)
            .then(({ data }) => {
                setVersions(data.software);
                setCurrent(data.current);
            })
            .catch((e) => setMessage({ type: 'danger', text: httpErrorToHuman(e) }))
            .finally(() => setLoading(false));
    }, [uuid]);

    const doSwitch = async () => {
        setSwitching(true);
        setMessage(null);
        try {
            const { data } = await http.post(`/api/client/servers/${uuid}/extensions/versionchanger/switch`, { software, version });
            setMessage({ type: 'success', text: data.message });
            setCurrent(version);
            setConfirming(false);
        } catch (e: any) {
            setMessage({ type: 'danger', text: e.response?.data?.error || httpErrorToHuman(e) });
        } finally {
            setSwitching(false);
        }
    };

    return (
        <PageContentBlock title={'Version Changer'}>
            <h1 className={'vc:text-2xl vc:font-bold vc:mb-1'}>Version Changer</h1>
            <p className={'vc:mb-4 vc:text-sm vc:text-muted-foreground'}>
                Switch your Minecraft server software and version with one click. Reinstall runs automatically — world files stay safe.
            </p>

            {message && <Alert type={message.type === 'success' ? 'success' : 'danger'} className={'vc:mb-4'}>{message.text}</Alert>}

            {current && (
                <div className={'vc:mb-4 vc:rounded-lg vc:border vc:border-border vc:bg-card vc:p-4 vc:text-sm'}>
                    Currently installed: <span className={'vc:font-semibold vc:text-violet-300'}>{current}</span>
                </div>
            )}

            {loading || !versions ? (
                <Spinner size={'large'} centered />
            ) : (
                <div className={'vc:space-y-5'}>
                    <div>
                        <label className={'vc:mb-1.5 vc:block vc:text-sm vc:font-medium'}>Server Software</label>
                        <Select
                            value={software}
                            onChange={(v) => { setSoftware(String(v)); setVersion(''); }}
                            options={Object.keys(versions).map((k) => ({ value: k, label: k }))}
                        />
                    </div>
                    <div>
                        <label className={'vc:mb-1.5 vc:block vc:text-sm vc:font-medium'}>Version</label>
                        <Select
                            value={version}
                            onChange={(v) => setVersion(String(v))}
                            options={(versions[software] || []).map((v: string) => ({ value: v, label: v }))}
                        />
                    </div>

                    {confirming ? (
                        <div className={'vc:rounded-lg vc:border vc:border-red-500/50 vc:bg-red-500/10 vc:p-4'}>
                            <p className={'vc:mb-3 vc:text-sm'}>
                                Switch to <b>{software} {version}</b>? The server will reinstall — your worlds and configs are kept, but this takes a few minutes.
                            </p>
                            <div className={'vc:flex vc:gap-3'}>
                                <Button onClick={doSwitch} disabled={switching}>{switching ? 'Switching...' : 'Confirm & Reinstall'}</Button>
                                <Button variant={'secondary'} onClick={() => setConfirming(false)}>Cancel</Button>
                            </div>
                        </div>
                    ) : (
                        <Button onClick={() => setConfirming(true)} disabled={!version}>Switch Version</Button>
                    )}
                </div>
            )}
        </PageContentBlock>
    );
}
