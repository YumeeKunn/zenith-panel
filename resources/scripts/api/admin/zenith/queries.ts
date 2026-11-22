import axios from '@/api/http';

export interface ZenithAlertConfig {
    label: string;
    hint: string;
    has_threshold: boolean;
    threshold_default: number | null;
    channel: 'off' | 'discord' | 'generic' | 'both';
    threshold: number | null;
}

export interface ZenithSettings {
    discord_webhook: string;
    generic_webhook: string;
    cooldown: number;
    alerts: Record<string, ZenithAlertConfig>;
}

export const getZenithSettings = async (): Promise<ZenithSettings> => {
    const { data } = await axios.get('/api/admin/zenith');
    return data.attributes;
};

export const saveZenithSettings = async (settings: {
    discord_webhook?: string;
    generic_webhook?: string;
    cooldown?: number;
    alerts?: Record<string, { channel: string; threshold?: number | null }>;
}): Promise<void> => {
    await axios.post('/api/admin/zenith', settings);
};

export const testZenithChannels = async (): Promise<Record<string, string>> => {
    const { data } = await axios.post('/api/admin/zenith/test');
    return data.attributes.results;
};
