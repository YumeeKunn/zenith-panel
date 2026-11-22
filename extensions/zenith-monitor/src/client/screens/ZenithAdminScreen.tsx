import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Input, PageContentBlock, Select, Spinner } from '@pterodactyl/sdk';
import { httpErrorToHuman } from '@pterodactyl/sdk/api';

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

interface ZenithConfig {
    discord_webhook: string;
    generic_webhook: string;
    cooldown: number;
    alerts: Record<string, AlertConfig>;
}

export default function ZenithAdminScreen() {
    const client = useQueryClient();
    const [settings, setSettings] = useState<ZenithConfig | null>(null);
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [message, setMessage] = useState<{ type: 'success' | 'danger'; text: string } | null>(null);
    const [testResults, setTestResults] = useState<Record<string, string> | null>(null);

    const { isLoading } = useQuery({
        queryKey: ['zenith', 'settings'],
        queryFn: async () => {
            const { data } = await http.get('/api/admin/extensions/zenith-monitor/settings');
            setSettings(data);
            return data;
        },
    });

    if (isLoading || !settings) return <Spinner size={'large'} centered />;

    const updateAlert = (type: string, patch: Partial<AlertConfig>) => {
        setSettings({ ...settings, alerts: { ...settings.alerts, [type]: { ...settings.alerts[type], ...patch } } });
    };

    const save = async () => {
        setSaving(true);
        setMessage(null);
        try {
            const alerts: Record<string, { channel: string; threshold?: number | null }> = {};
            for (const [type, cfg] of Object.entries(settings.alerts)) {
                alerts[type] = { channel: cfg.channel, threshold: cfg.has_threshold ? cfg.threshold : null };
            }
            await http.post('/api/admin/extensions/zenith-monitor/settings', {
                discord_webhook: settings.discord_webhook,
                generic_webhook: settings.generic_webhook,
                cooldown: parseInt(String(settings.cooldown)) || 900,
                alerts,
            });
            setMessage({ type: 'success', text: 'Settings saved — applied from the next watchdog cycle.' });
            client.invalidateQueries({ queryKey: ['zenith'] });
        } catch (e) {
            setMessage({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setSaving(false);
        }
    };

    const test = async () => {
        setTesting(true);
        setTestResults(null);
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
        <PageContentBlock title={'Zenith Monitor'}>
            <h1 className={'zn:text-2xl zn:font-bold zn:text-foreground zn:mb-1'}>🛡 Zenith Monitor</h1>
            <p className={'zn:mb-6 zn:text-sm zn:text-muted-foreground'}>
                Watchdog, notifications, alert thresholds — managed here. Applied from the next cycle.
            </p>

            {message && <Alert type={message.type}>{message.text}</Alert>}
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

            <div className={'zn:mb-6 zn:rounded-lg zn:border zn:border-border zn:bg-card zn:p-5'}>
                <h2 className={'zn:mb-4 zn:text-sm zn:font-semibold zn:uppercase zn:tracking-wide zn:text-muted-foreground'}>
                    Notification Channels
                </h2>
                <div className={'zn:grid zn:gap-4 md:zn:grid-cols-2'}>
                    <div>
                        <label className={'zn:mb-1 zn:block zn:text-sm zn:text-foreground'}>Discord Webhook</label>
                        <Input value={settings.discord_webhook} onChange={(e) => setSettings({ ...settings, discord_webhook: e.target.value })} placeholder={'https://discord.com/api/webhooks/...'} />
                    </div>
                    <div>
                        <label className={'zn:mb-1 zn:block zn:text-sm zn:text-foreground'}>Generic Webhook (ntfy / Slack / custom)</label>
                        <Input value={settings.generic_webhook} onChange={(e) => setSettings({ ...settings, generic_webhook: e.target.value })} placeholder={'https://ntfy.sh/your-topic'} />
                    </div>
                </div>
                <div className={'zn:mt-4'}>
                    <label className={'zn:mb-1 zn:block zn:text-sm zn:text-foreground'}>Alert Repeat Interval (seconds)</label>
                    <Input type={'number'} value={settings.cooldown} onChange={(e) => setSettings({ ...settings, cooldown: parseInt(e.target.value) || 900 })} />
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
                                    <Select
                                        value={cfg.channel}
                                        onChange={(v) => updateAlert(type, { channel: String(v) })}
                                        options={CHANNELS}
                                    />
                                </td>
                                <td className={'zn:px-4 zn:py-3'}>
                                    {cfg.has_threshold ? (
                                        <Input type={'number'} value={cfg.threshold ?? ''} onChange={(e) => updateAlert(type, { threshold: parseInt(e.target.value) })} />
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
                <Button onClick={save} disabled={saving}>{saving ? 'Saving...' : 'Save Settings'}</Button>
                <Button variant={'secondary'} onClick={test} disabled={testing}>{testing ? 'Sending...' : 'Send Test'}</Button>
            </div>
        </PageContentBlock>
    );
}
