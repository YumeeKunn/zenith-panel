import { ServerContentBlock, TitledGreyBox } from '@pterodactyl/sdk';

export default function ServerScreen() {
    return (
        <ServerContentBlock title={'MC Player Manager'}>
            <TitledGreyBox title={'MC Player Manager'}>
                <p className={'mpm:text-sm mpm:text-muted-foreground'}>
                    Edit src/client/screens/ServerScreen.tsx to build this page.
                </p>
            </TitledGreyBox>
        </ServerContentBlock>
    );
}
