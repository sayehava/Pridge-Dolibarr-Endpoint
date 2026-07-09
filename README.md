# Print Bridge

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
templates, and the TakePOS integration this module plugs into. This module owns nothing but
PrintBridge profiles (endpoint/token/timeout/SSL settings) and the stream wrapper.

See `the README` for the full technical design, including why a Dummy-type connector cannot be
used for this instead.

## Setup

1. Copy this module's folder into `htdocs/custom/printbridge`, then enable **Print Bridge** in
   **Home > Setup > Modules/Applications**. Make sure the built-in **Receipt Printers** module
   is also enabled - this module does not replace it.
2. Go to this module's setup page and create a **PrintBridge profile**: give it a short id
   (e.g. `receipt_1`) and, if needed, override the endpoint URL / auth token / timeout / SSL
   verification for that specific profile. Anything left blank falls back to the module-wide
   defaults set on the same page. On activation, the default endpoint is automatically set to
   this module's own **bundled receiver** (`printbridgereceiver.php`) - it doesn't print
   anything, it just records what it received (profile ref, byte count, timestamp - shown on
   the setup page) so you can verify the whole round trip works before your real print
   collector exists. Replace `PRINTBRIDGE_DEFAULT_ENDPOINT` (or a profile's own endpoint
   override) with your real collector's URL when it's ready.
3. Either:
   - Go to **Setup > Receipt Printers** (the built-in module) and create or edit a printer
     with connector type **Local Printer**, using `printbridge://<profile-id>` as its
     Parameter value - the admin page shows this exact string next to each profile, ready to
     copy; or
   - On this module's setup page, use **Adopt an existing printer**: it lists the built-in
     module's printers that use connector type **Local Printer** (the only type PrintBridge
     can take over - see "Why this exists") with a one-click Adopt action. Adopting creates a
     matching profile automatically (ref `printer_<id>`) and rewrites that printer's Parameter
     to `printbridge://printer_<id>`, **overwriting its previous Parameter value**.
4. Assign that printer to a TakePOS terminal as usual (**TakePOS > Terminals**). Tickets
   printed from that terminal are now forwarded over HTTPS to your print collector instead of
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

`printbridgereceiver.php` at the module root is a minimal, unauthenticated-by-default (see
below) endpoint that PrintBridge points at automatically until you configure a real one. It
never prints anything - it writes what it received to
`documents/printbridge/lastreceived.bin` and records the last profile ref, byte count and
timestamp so the setup page can show proof a print actually reached *something*.
If you set `PRINTBRIDGE_DEFAULT_TOKEN`, the bundled receiver enforces it like a real collector
would; if you leave it blank, it accepts any POST - fine for local testing, not for exposing
to the internet unset.

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
to eyeball whether the right content went out. Entries beyond the last 10 are pruned
automatically.

## Status

Core mechanism (module descriptor, hook, stream wrapper, HTTP client, profile storage, admin
page, bundled test receiver, recent-prints log) is in place. Not yet tested against a live
Dolibarr instance / TakePOS terminal.
