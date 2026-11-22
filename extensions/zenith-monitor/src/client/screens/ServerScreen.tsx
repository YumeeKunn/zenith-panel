import { ServerContentBlock, TitledGreyBox } from '@pterodactyl/sdk';

export default function ServerScreen() {
    return (
        <ServerContentBlock title={'Zenith Monitor'}>
            <TitledGreyBox title={'Zenith Monitor'}>
                <p className={'zn:text-sm zn:text-muted-foreground'}>
                    Edit src/client/screens/ServerScreen.tsx to build this page.
                </p>
            </TitledGreyBox>
        </ServerContentBlock>
    );
}
