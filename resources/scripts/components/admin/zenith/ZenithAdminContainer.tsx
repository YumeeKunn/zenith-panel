import { useEffect, useState } from 'react';
import {
    getZenithSettings,
    saveZenithSettings,
    testZenithChannels,
    ZenithAlertConfig,
    ZenithSettings,
} from '@/api/admin/zenith/queries';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import Spinner from '@/components/elements/Spinner';
import { httpErrorToHuman } from '@/api/http';
import Button from '@/components/elements/Button';
import Alert from '@/components/elements/alert/Alert';

type ChannelChoice = 'off' | 'discord' | 'generic' | 'both';

const CHANNEL_LABELS: Record<ChannelChoice, string> = {
    off: 'OFF (na bhejo)',
    discord: 'Sirf Discord',
    generic: 'Sirf Generic Webhook',
    both: 'Discord + Generic',
};

export default function ZenithAdminContainer() {
    const [settings, setSettings] = useState<ZenithSettings | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
    const [testResults, setTestResults] = useState<Record<string, string> | null>(null);

    useEffect(() => {
        getZenithSettings()
            .then(setSettings)
            .catch((e) => setMessage({ type: 'error', text: httpErrorToHuman(e) }))
            .finally(() => setLoading(false));
    }, []);

    const updateAlert = (type: string, patch: Partial<ZenithAlertConfig>) => {
        if (!settings) return;
        setSettings({
            ...settings,
            alerts: { ...settings.alerts, [type]: { ...settings.alerts[type], ...patch } },
        });
    };

    const save = async () => {
        if (!settings) return;
        setSaving(true);
        setMessage(null);
        try {
            const alerts: Record<string, { channel: string; threshold?: number | null }> = {};
            for (const [type, cfg] of Object.entries(settings.alerts)) {
                alerts[type] = {
                    channel: cfg.channel,
                    threshold: cfg.has_threshold ? cfg.threshold : null,
                };
            }
            await saveZenithSettings({
                discord_webhook: settings.discord_webhook,
                generic_webhook: settings.generic_webhook,
                cooldown: parseInt(String(settings.cooldown)) || 900,
                alerts,
            });
            setMessage({ type: 'success', text: 'Settings saved — next cycle se apply honge!' });
        } catch (e) {
            setMessage({ type: 'error', text: httpErrorToHuman(e) });
        } finally {
            setSaving(false);
        }
    };

    const test = async () => {
        setTesting(true);
        setTestResults(null);
        try {
            setTestResults(await testZenithChannels());
        } catch (e) {
            setMessage({ type: 'error', text: httpErrorToHuman(e) });
        } finally {
            setTesting(false);
        }
    };

    if (loading) {
        return (
            <AdminContentBlock title={'Zenith Control'}>
                <Spinner size={'large'} centered />
            </AdminContentBlock>
        );
    }

    if (!settings) {
        return (
            <AdminContentBlock title={'Zenith Control'}>
                {message && <Alert type={'danger'}>{message.text}</Alert>}
            </AdminContentBlock>
        );
    }

    return (
        <AdminContentBlock title={'Zenith Control'}>
            <h1 className={'mb-1 text-2xl font-bold text-neutral-100'}>🛡️ Zenith Control</h1>
            <p className={'mb-6 text-sm text-neutral-400'}>
                Watchdog, notifications aur alert thresholds — sab yahan se. Settings save karte hi next
                cycle me apply hongi.
            </p>

            {message && (
                <Alert type={message.type === 'success' ? 'success' : 'danger'} className={'mb-4'}>
                    {message.text}
                </Alert>
            )}

            {/* Notification channels */}
            <div className={'mb-6 rounded-lg border border-neutral-700 bg-neutral-800/50 p-5'}>
                <h2 className={'mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-300'}>
                    Notification Channels
                </h2>
                <div className={'grid gap-4 md:grid-cols-2'}>
                    <div>
                        <label className={'mb-1 block text-sm text-neutral-300'}>
                            <i className={'fa fa-comment'} /> Discord Webhook
                        </label>
                        <input
                            type={'text'}
                            className={'w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm'}
                            value={settings.discord_webhook}
                            onChange={(e) => setSettings({ ...settings, discord_webhook: e.target.value })}
                            placeholder={'https://discord.com/api/webhooks/...'}
                        />
                    </div>
                    <div>
                        <label className={'mb-1 block text-sm text-neutral-300'}>
                            <i className={'fa fa-bolt'} /> Generic Webhook (ntfy / Slack / custom)
                        </label>
                        <input
                            type={'text'}
                            className={'w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm'}
                            value={settings.generic_webhook}
                            onChange={(e) => setSettings({ ...settings, generic_webhook: e.target.value })}
                            placeholder={'https://ntfy.sh/your-topic'}
                        />
                    </div>
                </div>
                <div className={'mt-4'}>
                    <label className={'mb-1 block text-sm text-neutral-300'}>Alert Repeat Interval (seconds)</label>
                    <input
                        type={'number'}
                        className={'w-48 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm'}
                        value={settings.cooldown}
                        onChange={(e) => setSettings({ ...settings, cooldown: parseInt(e.target.value) || 900 })}
                        min={60}
                    />
                    <p className={'mt-1 text-xs text-neutral-500'}>
                        Same alert iske baad repeat hoga (anti-spam).
                    </p>
                </div>
            </div>

            {/* Alert settings table */}
            <div className={'mb-6 overflow-x-auto rounded-lg border border-neutral-700 bg-neutral-800/50'}>
                <table className={'w-full text-sm'}>
                    <thead>
                        <tr className={'border-b border-neutral-700 text-left text-xs uppercase text-neutral-400'}>
                            <th className={'px-4 py-3'}>Alert</th>
                            <th className={'w-56 px-4 py-3'}>Bhejo kahan?</th>
                            <th className={'w-40 px-4 py-3'}>Threshold (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {Object.entries(settings.alerts).map(([type, cfg]) => (
                            <tr key={type} className={'border-b border-neutral-800'}>
                                <td className={'px-4 py-3'}>
                                    <div className={'font-medium text-neutral-100'}>{cfg.label}</div>
                                    <div className={'text-xs text-neutral-500'}>{cfg.hint}</div>
                                </td>
                                <td className={'px-4 py-3'}>
                                    <select
                                        className={'w-full rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1.5 text-sm'}
                                        value={cfg.channel}
                                        onChange={(e) => updateAlert(type, { channel: e.target.value as ChannelChoice })}
                                    >
                                        {Object.entries(CHANNEL_LABELS).map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </td>
                                <td className={'px-4 py-3'}>
                                    {cfg.has_threshold ? (
                                        <input
                                            type={'number'}
                                            className={'w-full rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1.5 text-sm'}
                                            value={cfg.threshold ?? ''}
                                            min={1}
                                            onChange={(e) => updateAlert(type, { threshold: parseInt(e.target.value) })}
                                        />
                                    ) : (
                                        <span className={'text-neutral-600'}>—</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Save */}
            <div className={'flex items-center gap-3'}>
                <Button onClick={save} disabled={saving}>
                    {saving ? 'Saving...' : '💾 Save Settings'}
                </Button>
                <Button variant={'secondary'} onClick={test} disabled={testing}>
                    {testing ? 'Sending...' : '📨 Send Test (all channels)'}
                </Button>
                <a
                    href={'/metrics-graph'}
                    className={'text-sm text-cyan-400 hover:text-cyan-300'}
                >
                    📊 Graph Monitor →
                </a>
            </div>

            {testResults && (
                <div className={'mt-4 rounded-lg border border-neutral-700 bg-neutral-900 p-4'}>
                    {Object.entries(testResults).map(([ch, res]) => (
                        <div key={ch} className={'text-sm'}>
                            <span className={'font-semibold'}>{ch}</span>:{' '}
                            <span className={res === 'sent' ? 'text-green-400' : 'text-red-400'}>{res}</span>
                        </div>
                    ))}
                </div>
            )}
        </AdminContentBlock>
    );
}
