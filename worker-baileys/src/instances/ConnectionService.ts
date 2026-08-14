import { ConnectOptions, InstanceManager } from './InstanceManager';

export class ConnectionService {
    static connect(uuid: string, options: ConnectOptions = {}) {
        return InstanceManager.connect(uuid, options);
    }

    static disconnect(uuid: string) {
        return InstanceManager.disconnect(uuid);
    }

    static status(uuid: string) {
        return InstanceManager.status(uuid);
    }
}
