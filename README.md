# Receipt Printers - Extended (PrintBridge)

A companion Dolibarr module that adds an HTTP-based printer driver, **PrintBridge**, usable
from Dolibarr's built-in **Receipt Printers** module and from **TakePOS**, without patching
Dolibarr core and without disabling or conflicting with the built-in module.

## Why this exists

Dolibarr's receipt printer stack (`dolReceiptPrinter` + `mike42/escpos-php`) only ships 5
connector types (Dummy, File, Network, Windows, CUPS) with no hook or plugin point to add a
6th. On top of that, TakePOS hardcodes `isModEnabled('receiptprinter')` and instantiates the
built-in module's own class from a fixed core file path in several places - there is no way
to swap in a different module's printer class, and disabling the built-in module (as an
earlier version of this project did) makes TakePOS's printer UI disappear entirely.

This module works around both limitations without touching any core file:

- It reuses the **existing "File" connector type** unmodified. `FilePrintConnector` (from
  `mike42/escpos-php`) does nothing but `fopen()` / `fwrite()` / `fclose()` on the printer's
  Parameter value - plain PHP stream I/O, no validation.
- It registers a PHP stream wrapper for the scheme `printbridge://`. Pointing a File-type
  printer's Parameter at `printbridge://<profile-id>` routes those `fopen()`/`fwrite()`/
  `fclose()` calls into this module instead of the filesystem: the ESC/POS bytes are buffered
  in memory and POSTed over HTTPS to the endpoint configured for that profile.
- The wrapper is registered through Dolibarr's official hook system (a module claiming the
  `all` hook context gets its `class/actions_<module>.class.php` instantiated on every
  request) - not a core patch.

The built-in Receipt Printers module must stay enabled: it owns the printer list, the ticket
templates, and the TakePOS integration this module plugs into. This module owns nothing but
PrintBridge profiles (endpoint/token/timeout/SSL settings) and the stream wrapper.

See `the README` for the full technical design, including why a Dummy-type connector cannot be
used for this instead.

## Setup

1. Copy this module's folder into `htdocs/custom/receiptprinterextended`, then enable
   **Receipt Printers - Extended** in **Home > Setup > Modules/Applications**. Make sure the
   built-in **Receipt Printers** module is also enabled - this module does not replace it.
2. Go to this module's setup page and create a **PrintBridge profile**: give it a short id
   (e.g. `receipt_1`) and, if needed, override the endpoint URL / auth token / timeout / SSL
   verification for that specific profile. Anything left blank falls back to the module-wide
   defaults set on the same page.
3. Go to **Setup > Receipt Printers** (the built-in module) and create or edit a printer with
   connector type **File**, using `printbridge://<profile-id>` as its Parameter value - the
   admin page shows this exact string next to each profile, ready to copy.
4. Assign that printer to a TakePOS terminal as usual (**TakePOS > Terminals**). Tickets
   printed from that terminal are now forwarded over HTTPS to your print collector instead of
   being written to a local file.

## Data format

PrintBridge always forwards a raw **ESC/POS binary command stream**, never a PDF or an image
file - logos, barcodes and QR codes are ESC/POS raster commands embedded in that same byte
stream by the built-in module, not separate documents. Your print collector needs to
understand/relay ESC/POS bytes to a real or virtual thermal printer.

## Status

Core mechanism (module descriptor, hook, stream wrapper, HTTP client, profile storage and
admin page) is in place. Not yet tested against a live Dolibarr instance / TakePOS terminal.
