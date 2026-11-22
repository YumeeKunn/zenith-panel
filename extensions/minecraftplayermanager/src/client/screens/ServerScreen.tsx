import { useEffect, useRef, useState } from "react";
import { useCurrentServerUuid } from '@pterodactyl/sdk';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';
import { Alert, Button, PageContentBlock, Spinner } from '@pterodactyl/sdk';

interface Online { online: number; max: number; players: string[]; raw: string; }

export default function ServerScreen() {
    const uuid = useCurrentServerUuid();
    const [data, setData] = useState<Online | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [actionMsg, setActionMsg] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);

    const load = async () => {
        try {
            const { data } = await http.get(`/api/client/servers/${uuid}/extensions/minecraftplayermanager/online`);
            setData(data);
            setError(null);
        } catch (e: any) {
            setError(e.response?.data?.error || 'Failed to fetch player list (is the server running?)');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (!uuid) return;
        load();
        timer.current = setInterval(load, 5000);
        return () => { if (timer.current) clearInterval(timer.current); };
    }, [uuid]);

    const runAction = async (action: string, player: string) => {
        setBusy(player + action);
        setActionMsg(null);
        try {
            const { data } = await http.post(`/api/client/servers/${uuid}/extensions/minecraftplayermanager/command`, { action, player });
            setActionMsg(`${action} ${player}: ${data.output || 'sent'}`);
        } catch (e: any) {
            setActionMsg(e.response?.data?.error || httpErrorToHuman(e));
        } finally {
            setBusy(null);
            setTimeout(load, 1000);
        }
    };

    return (
        <PageContentBlock title={'Players'}>
            <h1 className={'mpm:text-2xl mpm:font-bold mpm:mb-1'}>Player Manager</h1>
            <p className={'mpm:mb-4 mpm:text-sm mpm:text-muted-foreground'}>
                Live player list with moderation actions (kick, ban, op, whitelist) — refreshes every 5s.
            </p>

            {error && <Alert type={'danger'} className={'mpm:mb-4'}>{error}</Alert>}
            {actionMsg && <Alert type={'info'} className={'mpm:mb-4'}>{actionMsg}</Alert>}

            {loading && !data ? (
                <Spinner size={'large'} centered />
            ) : data ? (
                <div>
                    <div className={'mpm:mb-4 mpm:rounded-lg mpm:border mpm:border-border mpm:bg-card mpm:p-4'}>
                        <span className={'mpm:text-2xl mpm:font-bold'}>{data.online}</span>
                        <span className={'mpm:text-muted-foreground'}> / {data.max} players online</span>
                    </div>

                    {data.players.length === 0 ? (
                        <div className={'mpm:rounded-lg mpm:border mpm:border-border mpm:p-8 mpm:text-center mpm:text-muted-foreground'}>
                            No players online right now
                        </div>
                    ) : (
                        <div className={'mpm:space-y-2'}>
                            {data.players.map((p) => (
                                <div key={p} className={'mpm:flex mpm:items-center mpm:justify-between mpm:rounded-lg mpm:border mpm:border-border mpm:p-3'}>
                                    <span className={'mpm:font-medium'}>{p}</span>
                                    <div className={'mpm:flex mpm:gap-2'}>
                                        {['kick', 'op', 'ban', 'whitelist-add'].map((a) => (
                                            <Button
                                                key={a}
                                                variant={'secondary'}
                                                onClick={() => runAction(a, p)}
                                                disabled={busy === p + a}
                                            >
                                                {busy === p + a ? '...' : a}
                                            </Button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            ) : null}
        </PageContentBlock>
    );
}
