import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import ZenithAdminScreen from './screens/ZenithAdminScreen';
import ZenithGraphScreen from './screens/ZenithGraphScreen';

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register('admin', () => import('./screens/ZenithAdminScreen'));
        screens.register('graphs', () => import('./screens/ZenithGraphScreen'));
    },
});
