import { useEffect, useState } from 'react';
import { useCurrentServerUuid } from '@pterodactyl/sdk';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';
import { Alert, Button, PageContentBlock, Spinner } from '@pterodactyl/sdk';

export default function ServerScreen() {
    const uuid = useCurrentServerUuid();
    const [startup, setStartup] = useState<string>('');
    const [presets, setPresets] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<{ type: string; text: string } | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        if (!uuid) return;
        http.get(`/api/client/servers/${uuid}/extensions/startupchanger/`)
            .then(({ data }) => {
                setStartup(data.startup);
                setPresets(data.presets || {});
            })
            .catch((e) => setMessage({ type: 'danger', text: httpErrorToHuman(e) }))
            .finally(() => setLoading(false));
    }, [uuid]);

    const applyPreset = (name: string, cmd: string) => {
        setStartup(cmd);
        setMessage({ type: 'info', text: `Preset "${name}" loaded — press Save to apply.` });
    };

    const save = async () => {
        setSaving(true);
        setMessage(null);
        try {
            await http.post(`/api/client/servers/${uuid}/extensions/startupchanger/`, { startup });
            setMessage({ type: 'success', text: 'Startup command saved and synced to Wings.' });
        } catch (e) {
            setMessage({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <PageContentBlock title={'Startup Changer'}>
            <h1 className={'stc:text-2xl stc:font-bold stc:mb-1'}>Startup Changer</h1>
            <p className={'stc:mb-4 stc:text-sm stc:text-muted-foreground'}>
                Switch between safe startup command presets or edit the command directly. Dangerous patterns are blocked.
            </p>

            {message && <Alert type={message.type === 'success' ? 'success' : message.type === 'info' ? 'info' : 'danger'} className={'stc:mb-4'}>{message.text}</Alert>}

            {loading ? (
                <Spinner size={'large'} centered />
            ) : (
                <div className={'stc:space-y-4'}>
                    <div>
                        <label className={'stc:mb-1.5 stc:block stc:text-sm stc:font-medium'}>Startup Command</label>
                        <textarea
                            value={startup}
                            onChange={(e) => setStartup(e.target.value)}
                            rows={3}
                            className={'stc:w-full stc:rounded-lg stc:border stc:border-border stc:bg-card stc:p-3 stc:font-mono stc:text-sm'}
                        />
                    </div>

                    {Object.keys(presets).length > 0 && (
                        <div>
                            <label className={'stc:mb-2 stc:block stc:text-sm stc:font-medium'}>Admin Presets</label>
                            <div className={'stc:space-y-2'}>
                                {Object.entries(presets).map(([name, cmd]) => (
                                    <button
                                        key={name}
                                        onClick={() => applyPreset(name, cmd)}
                                        className={'stc:flex stc:w-full stc:items-center stc:justify-between stc:rounded-lg stc:border stc:border-border stc:p-3 stc:text-left stc:text-sm hover:stc:border-violet-500'}
                                    >
                                        <span className={'stc:font-medium'}>{name}</span>
                                        <code className={'stc:text-xs stc:text-muted-foreground'}>{cmd.slice(0, 60)}</code>
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    <Button onClick={save} disabled={saving}>{saving ? 'Saving...' : 'Save Startup'}</Button>
                </div>
            )}
        </PageContentBlock>
    );
}
