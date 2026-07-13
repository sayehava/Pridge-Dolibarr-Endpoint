# PrintBridge Dolibarr Endpoint

A companion Dolibarr module that adds an HTTP-based printer driver, **PrintBridge**, usable
from Dolibarr's built-in **Receipt Printers** module and from **TakePOS**, without patching
Dolibarr core and without disabling or conflicting with the built-in module.

## Why this exists

Dolibarr's receipt printer stack (`dolReceiptPrinter` + `mike42/escpos-php`) only ships 5
connector types - shown in the built-in module's UI as **Dummy Printer**, **Local Printer**,
**Network Printer**, **Local Windows Printer** and **Cups Printer** - with no hook or plugin
point to add a 6th. On top of that, TakePOS hardcodes `isModEnabled('receiptprinter')` and
instantiates the built-in module's own class from a fixed core file path in several places -
there is no way to swap in a different module's printer class, and disabling the built-in
module (as an earlier version of this project did) makes TakePOS's printer UI disappear
entirely.

This module works around both limitations without touching any core file:

- It reuses the **existing "Local Printer" connector type** unmodified. Internally that's
  `FilePrintConnector` (from `mike42/escpos-php`), meant for a local device path like
  `/dev/usb/lp0` - it does nothing but `fopen()` / `fwrite()` / `fclose()` on the printer's
  Parameter value, with zero validation of that value.
- It registers a PHP stream wrapper for the scheme `printbridge://`. Pointing a Local
  Printer's Parameter at `printbridge://<profile-id>` (instead of a real device path) routes
  those `fopen()`/`fwrite()`/`fclose()` calls into this module instead of the filesystem: the
  ESC/POS bytes are buffered in memory and POSTed over HTTPS to the endpoint configured for
  that profile.
- The wrapper is registered through Dolibarr's official hook system (a module claiming the
  `all` hook context gets its `class/actions_<module>.class.php` instantiated on every
  request) - not a core patch.

The built-in Receipt Printers module must stay enabled: it owns the printer list, the ticket
templates, and the TakePOS integration this module plugs into. This module owns PrintBridge
**servers** (base URLs), **profiles** (which server + endpoint token + timeout a printer
uses), the rolling test log, and the stream wrapper.

PrintBridge submits jobs to a real PrintBridge Server's plugin API:

```
POST <server base URL>/api/plugin/jobs
Authorization: Bearer <endpoint token>
Content-Type: application/octet-stream
X-PrintBridge-Metadata: {"source":"dolibarr-printbridge","profile":"<ref>"}

<raw ESC/POS bytes>
```

Per that API's own guidance, the token is only ever sent as a header, never in the URL. If no
server resolves (profile and module default both unset), PrintBridge falls back to a plain
raw endpoint URL with no token - this is only meant for simple test receivers like the bundled
one below, not the real plugin API.

See `the README` for the full technical design, including why a Dummy-type connector cannot be
used for this instead.

## Setup

1. Copy this module's folder into `htdocs/custom/printbridge`, then enable **PrintBridge
   Dolibarr Endpoint** in
   **Home > Setup > Modules/Applications**. Make sure the built-in **Receipt Printers** module
   is also enabled - this module does not replace it.
2. On this module's setup page, add a **PrintBridge server**: a name and your PrintBridge
   Server's base URL (e.g. `https://printbridge.example.com`) - the plugin API path
   (`/api/plugin/jobs`) is added automatically. Optionally, set it (and a default endpoint
   token) as the **module-wide default** at the top of the page - if every printer shares one
   destination, this alone is enough and no per-profile overrides are needed at all.
3. Either:
   - Use **Adopt an existing printer**: it lists the built-in module's printers using
     connector type **Local Printer** (the only type PrintBridge can take over - see "Why
     this exists") with a one-click Adopt action. Adopting creates a matching profile
     automatically (ref `printer_<id>`) and rewrites that printer's Parameter to
     `printbridge://printer_<id>`, **overwriting its previous Parameter value**. An
     **Unadopt** button next to it clears the Parameter back to empty if you change your mind
     - it cannot restore the printer's previous value, since that was never saved, but it lets
     you reconfigure the printer from scratch. The matching profile is left untouched; or
   - Go to **Setup > Receipt Printers** (the built-in module) yourself and create or edit a
     printer with connector type **Local Printer**, using `printbridge://<profile-id>` as its
     Parameter value - then come back to this module's Profiles section, where the ref picker
     lists exactly that printer (any printer already pointed at `printbridge://` that has no
     profile yet) so you never have to type the ref by hand.
4. If this printer needs its own server/token instead of the module-wide default, click edit
   on its profile row and set them there. Timeout can be left blank to use the module-wide
   default too. Until a server resolves (profile or default), the fallback is this module's
   own **bundled receiver** (`printbridgereceiver.php`) - it doesn't print anything, it just
   records what it received (shown on the setup page) so you can verify the round trip before
   a real server exists.
5. Assign that printer to a TakePOS terminal as usual (**TakePOS > Terminals**). Tickets
   printed from that terminal are now submitted as jobs to your PrintBridge Server instead of
   being written to a local file.

Printers using connector type Dummy Printer, Network Printer, Local Windows Printer or Cups
Printer cannot be adopted or manually pointed at `printbridge://` - none of them route through
the `fopen()` call PrintBridge intercepts (see `the README`).

## Data format

PrintBridge always forwards a raw **ESC/POS binary command stream**, never a PDF or an image
file - logos, barcodes and QR codes are ESC/POS raster commands embedded in that same byte
stream by the built-in module, not separate documents. Your print collector needs to
understand/relay ESC/POS bytes to a real or virtual thermal printer.

## Bundled test receiver

`printbridgereceiver.php` at the module root is a minimal, unauthenticated endpoint that
PrintBridge points at automatically until you configure a real one. It never prints anything
- it writes what it received to `documents/printbridge/lastreceived.bin` and records the last
profile ref, byte count and timestamp so the setup page can show proof a print actually
reached *something*. Like the rest of PrintBridge, it has no auth check - only expose it
inside your trusted network, same as any real print collector you point PrintBridge at.

## Recent prints (test log)

The setup page's **Recent prints** section keeps the last 10 tickets that went through
PrintBridge, regardless of what happened to them:

- Forwarded successfully: endpoint and HTTP status shown.
- Forwarded but failed (endpoint unreachable, non-2xx response, etc.): endpoint and HTTP
  status shown.
- No endpoint configured at all (neither the profile nor the module default has one): the
  ticket is still logged, marked "No endpoint configured - stored only", so nothing is
  silently lost even before any endpoint exists.

Each row has a **Preview** button (a small modal) showing the ticket's text with ESC/POS
control bytes and raster image data stripped out - not a real receipt rendering, just enough
to eyeball whether the right content went out - plus the server's own response body, so a
failure like an HTTP 403 shows its explanation right there instead of requiring server-side
log access. Entries beyond the last 10 are pruned
automatically.

## Upgrading an existing test install

Dolibarr only runs a module's `CREATE TABLE` once - it does not add new columns to a table
that already exists. If you installed this module before servers/tokens or response logging
existed, run this once against your existing database:

```sql
ALTER TABLE llx_printbridge_profile
    ADD COLUMN server_id INTEGER DEFAULT 0 NOT NULL,
    ADD COLUMN endpoint_token VARCHAR(255) DEFAULT '' NOT NULL;
ALTER TABLE llx_printbridge_log
    ADD COLUMN response TEXT NOT NULL;
```

(If you also still have the very first version's `token`/`verify_ssl` columns on
`llx_printbridge_profile` from before those were removed, `DROP COLUMN token, DROP COLUMN
verify_ssl` in the same statement.)

## Status

Core mechanism (module descriptor, hook, stream wrapper, HTTP client, servers, profiles,
admin page, bundled test receiver, recent-prints log) is in place and has been tested against
a live Dolibarr instance (module activation, adopt/unadopt, TakePOS test print). The
server/token rework in this session has not been retested live yet.

## License

Licensed under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later). See
[`LICENSE`](LICENSE) for the full text.
