import { useEffect, useState } from 'react';
import { useCurrentServerUuid } from '@pterodactyl/sdk';
import { http, httpErrorToHuman } from '@pterodactyl/sdk/api';
import { Alert, Button, Input, PageContentBlock, Select, Spinner } from '@pterodactyl/sdk';

interface Result { slug: string; title: string; description: string; downloads: number; icon: string; }
interface Version { id: string; version_number: string; game_versions: string[]; file_url: string; file_name: string; }

export default function ServerScreen() {
    const uuid = useCurrentServerUuid();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Result[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [selected, setSelected] = useState<Result | null>(null);
    const [versions, setVersions] = useState<Version[] | null>(null);
    const [vLoading, setVLoading] = useState(false);
    const [picked, setPicked] = useState<Version | null>(null);
    const [installing, setInstalling] = useState(false);
    const [message, setMessage] = useState<{ type: 'success' | 'danger'; text: string } | null>(null);

    const search = async () => {
        setSearching(true);
        setMessage(null);
        try {
            const { data } = await http.get(`/api/client/servers/${uuid}/extensions/mcplugins/search`, { params: { query } });
            setResults(data.results || []);
        } catch (e) {
            setMessage({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setSearching(false);
        }
    };

    const openPlugin = async (r: Result) => {
        setSelected(r);
        setVersions(null);
        setPicked(null);
        setVLoading(true);
        try {
            const { data } = await http.get(`/api/client/servers/${uuid}/extensions/mcplugins/versions/${r.slug}`);
            setVersions(data.versions || []);
        } catch (e) {
            setMessage({ type: 'danger', text: httpErrorToHuman(e) });
        } finally {
            setVLoading(false);
        }
    };

    const install = async () => {
        if (!picked) return;
        setInstalling(true);
        setMessage(null);
        try {
            const { data } = await http.post(`/api/client/servers/${uuid}/extensions/mcplugins/install`, {
                url: picked.file_url,
                filename: picked.file_name,
            });
            setMessage({ type: 'success', text: data.message });
        } catch (e: any) {
            setMessage({ type: 'danger', text: e.response?.data?.error || httpErrorToHuman(e) });
        } finally {
            setInstalling(false);
        }
    };

    return (
        <PageContentBlock title={'Plugins Installer'}>
            <h1 className={'mp:text-2xl mp:font-bold mp:mb-1'}>Plugins Installer</h1>
            <p className={'mp:mb-4 mp:text-sm mp:text-muted-foreground'}>
                Browse Modrinth and install Paper/Spigot/Bukkit plugins straight into your server's plugins folder.
            </p>

            {message && <Alert type={message.type === 'success' ? 'success' : 'danger'} className={'mp:mb-4'}>{message.text}</Alert>}

            <div className={'mp:flex mp:gap-2 mp:mb-5'}>
                <Input
                    className={'mp:flex-1'}
                    value={query}
                    onChange={(e: any) => setQuery(e.target.value)}
                    placeholder={'Search plugins... e.g. EssentialsX, WorldEdit'}
                    onKeyDown={(e: any) => e.key === 'Enter' && search()}
                />
                <Button onClick={search} disabled={searching}>{searching ? 'Searching...' : 'Search'}</Button>
            </div>

            {!results && !searching && (
                <div className={'mp:rounded-lg mp:border mp:border-border mp:p-8 mp:text-center mp:text-muted-foreground'}>
                    Search above to find plugins from Modrinth
                </div>
            )}

            {searching && <Spinner size={'large'} centered />}

            {results && !selected && (
                <div className={'mp:space-y-2'}>
                    {results.length === 0 && <p className={'mp:text-sm mp:text-muted-foreground'}>No plugins found.</p>}
                    {results.map((r) => (
                        <button
                            key={r.slug}
                            onClick={() => openPlugin(r)}
                            className={'mp:flex mp:w-full mp:items-center mp:gap-3 mp:rounded-lg mp:border mp:border-border mp:p-3 mp:text-left hover:mp:border-violet-500'}
                        >
                            {r.icon && <img src={r.icon} className={'mp:h-10 mp:w-10 mp:rounded'} alt={''} />}
                            <div className={'mp:flex-1'}>
                                <div className={'mp:font-medium'}>{r.title}</div>
                                <div className={'mp:text-xs mp:text-muted-foreground'}>{r.description}</div>
                            </div>
                            <div className={'mp:text-xs mp:text-muted-foreground'}>{r.downloads.toLocaleString()} downloads</div>
                        </button>
                    ))}
                </div>
            )}

            {selected && (
                <div>
                    <Button variant={'secondary'} onClick={() => { setSelected(null); setVersions(null); }} className={'mp:mb-4'}>← Back to results</Button>
                    <h2 className={'mp:mb-2 mp:text-lg mp:font-semibold'}>{selected.title}</h2>
                    <p className={'mp:mb-4 mp:text-sm mp:text-muted-foreground'}>{selected.description}</p>

                    {vLoading ? <Spinner size={'large'} centered /> : (
                        <div className={'mp:space-y-2'}>
                            {(versions || []).slice(0, 10).map((v) => (
                                <button
                                    key={v.id}
                                    onClick={() => setPicked(v)}
                                    className={`mp:flex mp:w-full mp:items-center mp:justify-between mp:rounded-lg mp:border mp:p-3 mp:text-sm ${
                                        picked?.id === v.id ? 'mp:border-violet-500 mp:bg-violet-500/10' : 'mp:border-border'
                                    }`}
                                >
                                    <span className={'mp:font-medium'}>{v.version_number}</span>
                                    <span className={'mp:text-xs mp:text-muted-foreground'}>{v.file_name}</span>
                                </button>
                            ))}
                            {versions && versions.length === 0 && <p className={'mp:text-sm mp:text-muted-foreground'}>No Paper/Spigot versions found for this plugin.</p>}
                        </div>
                    )}

                    {picked && (
                        <div className={'mp:mt-4 mp:rounded-lg mp:border mp:border-violet-500/50 mp:bg-violet-500/10 mp:p-4'}>
                            <p className={'mp:mb-3 mp:text-sm'}>
                                Install <b>{picked.file_name}</b> into <code>/plugins</code>? Restart the server afterwards to load it.
                            </p>
                            <Button onClick={install} disabled={installing}>{installing ? 'Downloading...' : 'Install Plugin'}</Button>
                        </div>
                    )}
                </div>
            )}
        </PageContentBlock>
    );
}
