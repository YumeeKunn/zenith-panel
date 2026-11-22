import { ServerContentBlock, TitledGreyBox } from '@pterodactyl/sdk';

export default function ServerScreen() {
    return (
        <ServerContentBlock title={'MC Plugins'}>
            <TitledGreyBox title={'MC Plugins'}>
                <p className={'mp:text-sm mp:text-muted-foreground'}>
                    Edit src/client/screens/ServerScreen.tsx to build this page.
                </p>
            </TitledGreyBox>
        </ServerContentBlock>
    );
}
