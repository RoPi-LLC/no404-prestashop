# Changelog

All notable changes to the no404 PrestaShop module. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

The module ships in two lines, under the same technical name (`no404`):

| Line | PrestaShop | PHP | Zip |
| --- | --- | --- | --- |
| **2.x** | 9.0 and newer | 8.1+ | `no404-2.x.y-ps9.zip` |
| **1.x** | 8.0 – 8.2 | 7.2+ | `no404-1.x.y-ps8.zip` |

The PrestaShop 9 line is numbered above the PrestaShop 8 line on purpose: a store
that upgrades PrestaShop from 8 to 9 is then offered 2.x as a module upgrade, and
keeps its settings.

## [2.0.0] - Unreleased

First release of the PrestaShop 9 line (PrestaShop 9.0 and newer, PHP 8.1+).
Prepared as 1.0.0 and renumbered before publication, when 1.x went to the
PrestaShop 8 line.

### Added

- Server-side 301/302 redirects for 404s: unmatched routes, deleted products and
  deleted categories. No overrides. PrestaShop 9.1.5 and newer signal them with
  the `actionNotFound` hook; 9.0.0 – 9.1.4 do not fire that hook, so there every
  404 is caught through `actionOutputHTMLBefore` instead.
- The 301/302 choice follows the `redirectStatus` the no404 API returns (the
  per-site 301 threshold set in the no404 dashboard); "Send every match as a 301"
  forces permanent redirects.
- Target validation: only the shop's own domains (including alternate and
  www / non-www domains), no loops, no control characters.
- Local cache per shop, negative results included, plus a circuit breaker
  (60 s after an outage or 5xx, 300 s after a 429 or 403/404). Every failure is
  swallowed: the shop's own 404 page is shown.
- Ad clicks: only the ad network's category (google, microsoft, meta, other) is
  sent, never the click ID or the query string.
- Visitor data for the no404 dashboard, in `X-No404-Visitor-*` headers: the
  visitor's IP truncated to its network (IPv4 /24, IPv6 /48; private addresses
  are not sent), a pseudonymous visitor ID (HMAC of the IP keyed with a secret
  derived from the store's cookie key), the browser user agent and the Cloudflare
  country code. Without them every 404 was recorded under the store server's own
  address and the module's user agent. The full IP never leaves the store; the
  `actionNo404Visitor` hook can change or remove the data.
- Settings page (Symfony form) with multistore support, masked API key,
  connection test with a specific message for each failure, and status boxes
  (no key, switched off, friendly URLs off, unwritable cache, last API error,
  paused lookups).
- Catch-all mode (off by default) for 404 pages PrestaShop renders without the
  "page not found" event, such as a disabled product set to "404 Not Found".
  Offered on PrestaShop 9.1.5 and newer; on earlier releases every 404 already
  takes that path, so the switch is not shown.
- Cache backend choice: files (default) or APCu in front of files; optional
  custom cache directory.
- Optional debug headers (`X-No404-Source`, `X-No404-Score`, `X-No404-Skip`).
- Translations: English (source), Turkish, German, French, Spanish.

## [1.0.0] - Unreleased

First release of the PrestaShop 8 line (PrestaShop 8.0 – 8.2, PHP 7.2+). Same
features, settings and translations as 2.0.0, with these differences:

- PrestaShop 8 never fires `actionNotFound`: every 404 — unmatched routes,
  deleted products, deleted categories — is caught through
  `actionOutputHTMLBefore`, so catch-all is always on and its switch is not shown.
- The settings page is built on PrestaShop 8's back-office controller.
