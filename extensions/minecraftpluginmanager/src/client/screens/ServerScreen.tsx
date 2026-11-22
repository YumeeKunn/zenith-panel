import { ServerContentBlock, TitledGreyBox } from '@pterodactyl/sdk';

export default function ServerScreen() {
    return (
        <ServerContentBlock title={'Plugin Manager'}>
            <TitledGreyBox title={'Plugin Manager'}>
                <p className={'pm:text-sm pm:text-muted-foreground'}>
                    Edit src/client/screens/ServerScreen.tsx to build this page.
                </p>
            </TitledGreyBox>
        </ServerContentBlock>
    );
}
