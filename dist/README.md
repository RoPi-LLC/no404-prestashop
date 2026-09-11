# Download

This folder holds the ready-to-install module, one zip per PrestaShop version.
Pick the one that matches your store:

| Your PrestaShop | Zip |
| --- | --- |
| **9.0 or newer** | `no404-2.x.y-ps9.zip` |
| **8.0 – 8.2** | `no404-1.x.y-ps8.zip` |

Both are the same module (`no404`) with the same settings; only the version they
are built for differs. PrestaShop refuses to install the wrong one.

1. Download the zip (do not unpack it).
2. In your PrestaShop back office open **Modules → Module Manager → Upload a module**
   and drop the zip there.
3. Open **Configure**, paste your API key from the no404 dashboard (your site →
   Integration), save, and click **Test the connection**.

A module installed this way does **not** update itself. When a new version is
published here, upload the new zip the same way; your settings are kept.

**Moving your store from PrestaShop 8 to 9?** After the PrestaShop upgrade, upload
the `-ps9` zip the same way: it installs as a module upgrade and keeps your settings.

Each `.zip.sha256` file holds the SHA-256 checksum of its zip.
