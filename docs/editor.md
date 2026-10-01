# Browser editor

The editor uses the bridge state for its controls. Browser preferences change how the controls are displayed.
They do not change the device settings. The device API and protocol are documented in [bridge.md](bridge.md).

## Language

Help > Language offers English, Deutsch and Automatic. English and Deutsch set the `trackdsp_locale` cookie
for one year at `/`, with `SameSite=Lax` and `Secure` on HTTPS. Changing language reloads the page. Automatic
removes this cookie and restores negotiation. The current page and the saved view preferences remain available.

The first supported locale wins, in this order.

1. The `trackdsp_locale` cookie, set by the menu.
2. `TRACKDSP_LOCALE` in the server environment or `.env.local`, when set to `en` or `de`.
3. The browser's `Accept-Language` header, including regional forms such as `de-AT`.
4. English when none of these supplies a supported language.

Unsupported cookie or server values fall through to the next choice. With an empty `TRACKDSP_LOCALE`, each
browser chooses its own language. The locale listener runs after routing and before Symfony's translation
listeners. It applies to the editor route only. API requests and CLI commands keep their existing English messages.
Errors, Auto EQ warnings and recording names returned by the API remain English until that API supplies message
codes and parameters. Channel IDs, names chosen by the user, protocol values and units are unchanged.

## Translation catalogues

`translations/messages.en.yaml` and `messages.de.yaml` hold menu keys, widget labels and explanatory text.
Menus use semantic keys. Reused widget labels use their English source text as the key. Widget macros translate
labels at rendering time and keep state paths and command arguments unchanged. Channel labels rendered as HTML
pass through unchanged.

`labels.en.yaml` and `labels.de.yaml` translate words in device option lists. Numbers, ratios and units use their
source values. `js.en.yaml` and `js.de.yaml` hold browser confirmations, connection messages and rider status.
The request's JS catalogue is embedded as JSON. `assets/lib/i18n.js` interpolates `%name%` parameters as literal
text, with English source text as fallback. Callers use textContent for messages. The translated gain value
"Stumm" parses back to mute, and the original "mute" input remains accepted.

`TranslationTest` checks matching catalogue keys and interpolation parameters. `EditorLocaleTest` checks English
and German rendering and that translated labels preserve protocol values. `LocaleListenerTest` checks precedence.
The JS tests check cookie lifetime, fallback, literal interpolation and the translated muted-gain round trip.

```bash
ddev exec composer lint
ddev exec bin/console lint:twig templates
ddev exec composer ci
```
