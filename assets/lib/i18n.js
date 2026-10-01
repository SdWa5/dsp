/** Build a text translator. Missing messages fall back to their English source. Parameters stay literal. */
export function makeTranslator(messages = {}) {
    return (message, params = {}) => {
        const text = Object.hasOwn(messages, message) ? messages[message] : message;
        return text.replace(/%([a-z_]+)%/g, (token, name) => Object.hasOwn(params, name) ? String(params[name]) : token);
    };
}

let translate;
/** The request locale's catalogue is embedded by Twig; Node callers use the English source. */
export function t(message, params = {}) {
    if (!translate) {
        const node = typeof document === 'undefined' ? null : document.getElementById('trackdsp-i18n');
        translate = makeTranslator(node ? JSON.parse(node.textContent) : {});
    }
    return translate(message, params);
}

/** A one-year language override, or its deletion to restore server/browser negotiation. */
export function languageCookie(locale, secure = false) {
    const override = ['en', 'de'].includes(locale);
    return `trackdsp_locale=${override ? locale : ''}; Path=/; Max-Age=${override ? 31536000 : 0}; SameSite=Lax${secure ? '; Secure' : ''}`;
}
