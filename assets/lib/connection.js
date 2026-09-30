// What the editor says about the bridge and its device connection.

/**
 * Label of the connection button, whether it shows as online, whether the target is the simulated device,
 * and the banner text ('' for none), from a bridge status as kept in device.js.
 */
export function describeConnection(status) {
    const online = Boolean(status.bridge && status.connected);
    const demo = online && Boolean(status.demo);
    let banner = '';
    if (!status.bridge) {
        banner = status.error || 'The bridge is not running. Start it with bin/console trackdsp:bridge.';
    } else if (!status.connected) {
        banner = `Not connected${status.target ? ` to ${status.target}` : ''}.${status.error ? ` ${status.error}` : ''}`;
    } else if (demo) {
        banner = 'Connected to the simulated device. Changes reach no 8x8 until you connect to one.';
    }

    return { label: demo ? 'Demo' : online ? 'Online' : 'Offline', online: online && !demo, demo, banner };
}
