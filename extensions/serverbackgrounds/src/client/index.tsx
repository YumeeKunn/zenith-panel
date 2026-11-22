import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import ServerRowBackground from './screens/ServerRowBackground';

export default definePterodactylExtension({
    setup({ screens, slots }) {
        screens.register('admin', () => import('./screens/AdminScreen'));
        slots.register('dashboard.serverRow.before', ServerRowBackground);
    },
});
