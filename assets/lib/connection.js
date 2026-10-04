import { t } from './i18n.js';
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
        banner = status.error || t("The bridge is not running. Start it with bin/console sdwa5-dsp:bridge.");
    } else if (!status.connected) {
        banner = (status.target ? t("Not connected to %target%.", { target: status.target }) : t("Not connected."))+ (status.error ? ` ${status.error}` : '');
    } else if (demo) {
        banner = t("Connected to the simulated device. Changes reach no 8x8 until you connect to one.");
    }

    return { label: demo ? t('Demo') : online ? t('Online') : t('Offline'), online: online && !demo, demo, banner };
}
