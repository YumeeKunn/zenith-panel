import { ServerContentBlock, TitledGreyBox } from '@pterodactyl/sdk';

export default function ServerScreen() {
    return (
        <ServerContentBlock title={'Startup Changer'}>
            <TitledGreyBox title={'Startup Changer'}>
                <p className={'stc:text-sm stc:text-muted-foreground'}>
                    Edit src/client/screens/ServerScreen.tsx to build this page.
                </p>
            </TitledGreyBox>
        </ServerContentBlock>
    );
}
