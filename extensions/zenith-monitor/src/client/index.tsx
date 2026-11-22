import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register('main', () => import('./screens/ServerScreen'));
        screens.register('control', () => import('./screens/ZenithControlScreen'));
    },
});
