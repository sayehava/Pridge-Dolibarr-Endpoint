<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Read/write access to the built-in Receipt Printers module's own printer table
 * (llx_printer_receipt). This module never manages that table's rows in general - the only
 * exception is "adopting" an existing "Local Printer"-type printer, which rewrites its
 * Parameter to pridge://<ref> so Pridge can take over its stream.
 *
 * Only "Local Printer"-type printers can ever be adopted. Dummy has no I/O to intercept;
 * Network (fsockopen), Windows (regex-validated destination, then shell/copy) and CUPS
 * (lpstat/lp via proc_open) never go through fopen(), so none of them can be routed through
 * our pridge:// stream wrapper. See the README.
 */
class PridgeBuiltinPrinter
{
    /**
     * fk_type value used by the built-in module for its "Local Printer" connector type
     * (internally still named CONNECTOR_FILE_PRINT / FilePrintConnector in Dolibarr/escpos-php).
     */
    const CONNECTOR_FILE = 2;

    /**
     * @var DoliDB Database handler
     */
    private $db;

    /**
     * @var string Last error message
     */
    public $error = '';

    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Fetch all "Local Printer"-type printers defined in the built-in module - the only ones that can
     * ever be adopted by Pridge.
     *
     * @return array<int,array<string,mixed>> List of printers as plain arrays
     */
    public function fetchFileTypePrinters()
    {
        $list = array();

        $sql = "SELECT rowid, name, parameter";
        $sql .= " FROM ".MAIN_DB_PREFIX."printer_receipt";
        $sql .= " WHERE fk_type = ".((int) self::CONNECTOR_FILE);
        $sql .= " AND entity IN (".getEntity('printer_receipt').")";
        $sql .= " ORDER BY name ASC";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $list[] = array(
                    'rowid' => (int) $obj->rowid,
                    'name' => $obj->name,
                    'parameter' => $obj->parameter,
                );
            }
        }

        return $list;
    }

    /**
     * Point an existing "Local Printer"-type printer's Parameter at pridge://<ref>, overwriting
     * whatever local path it held before.
     *
     * @param int    $printerid Row id in llx_printer_receipt
     * @param string $ref       Pridge profile ref to point it at
     * @return int >0 if OK, <=0 if KO
     */
    public function setParameterToProfile($printerid, $ref)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."printer_receipt";
        $sql .= " SET parameter = '".$this->db->escape('pridge://'.$ref)."'";
        $sql .= " WHERE rowid = ".((int) $printerid);
        $sql .= " AND fk_type = ".((int) self::CONNECTOR_FILE);
        $sql .= " AND entity IN (".getEntity('printer_receipt').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }

    /**
     * Undo adopt: clear an adopted printer's Parameter back to empty. The value it held
     * before being adopted was never saved, so this cannot restore it - it just decouples the
     * printer from Pridge so it can be reconfigured from scratch. The matching
     * PridgeProfile row (if any) is left untouched.
     *
     * @param int $printerid Row id in llx_printer_receipt
     * @return int >0 if OK, <=0 if KO
     */
    public function clearParameter($printerid)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."printer_receipt";
        $sql .= " SET parameter = ''";
        $sql .= " WHERE rowid = ".((int) $printerid);
        $sql .= " AND fk_type = ".((int) self::CONNECTOR_FILE);
        $sql .= " AND entity IN (".getEntity('printer_receipt').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }
}
