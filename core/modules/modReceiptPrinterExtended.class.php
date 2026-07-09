<?php
/*
 * Module descriptor for Receipt Printers - Extended (PrintBridge).
 *
 * This module does not clone or conflict with Dolibarr's built-in Receipt Printers module.
 * It only adds an HTTP transport ("PrintBridge") that the built-in module's existing "Local
 * Printer" connector type can be pointed at, via a PHP stream wrapper registered through
 * Dolibarr's official hook system. See the README for the full design.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation file for module Receipt Printers - Extended.
 */
class modReceiptPrinterExtended extends DolibarrModules
{
    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->numero = 502500;
        $this->family = 'interface';
        $this->module_position = '53';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'ReceiptPrinterExtendedDesc';
        $this->descriptionlong = 'ReceiptPrinterExtendedDescLong';
        $this->editor_name = 'Sayeh Ava Pazouki';
        $this->editor_url = '';
        $this->version = '0.1.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'printer';

        // Where the bundled PrintBridge receiver (printbridgereceiver.php) records the last
        // ticket it got, so the admin page can show proof the round trip actually worked.
        $this->dirs = array('/receiptprinterextended');

        $this->config_page_url = array('receiptprinterextended.php@receiptprinterextended');

        $this->hidden = false;
        $this->depends = array();
        $this->requiredby = array();
        // This module intentionally never conflicts with, and never disables, Dolibarr's
        // built-in Receipt Printers module. That module must stay enabled: it owns the
        // printer list and the TakePOS integration that PrintBridge plugs into.
        $this->conflictwith = array();
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(16, 0);
        $this->langfiles = array('receiptprinterextended@receiptprinterextended');

        // Claim the 'all' hook context so class/actions_receiptprinterextended.class.php is
        // instantiated on every request, early enough to register the printbridge:// stream
        // wrapper before any page can attempt to print a receipt. See the README.
        $this->module_parts = array(
            'hooks' => array('all'),
        );

        $this->const = array();

        $r = 0;
        $r++;
        $this->const[$r][0] = 'PRINTBRIDGE_DEFAULT_TIMEOUT';
        $this->const[$r][1] = 'chaine';
        $this->const[$r][2] = '5';
        $this->const[$r][3] = 'Default PrintBridge request timeout in seconds, used when a profile leaves it blank';
        $this->const[$r][4] = 0;

        $r++;
        $this->const[$r][0] = 'PRINTBRIDGE_DEFAULT_VERIFY_SSL';
        $this->const[$r][1] = 'chaine';
        $this->const[$r][2] = '1';
        $this->const[$r][3] = 'Default SSL certificate verification (1 or 0), used when a profile leaves it blank';
        $this->const[$r][4] = 0;

        $this->boxes = array();

        // No custom rights: this module exposes a single admin/setup page, already
        // restricted to Dolibarr administrators like any other module configuration page.
        $this->rights = array();

        $this->menu = array();
    }

    /**
     * Module init.
     *
     * @param string $options Options when enabling module
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        global $conf;

        $result = $this->_load_tables('/receiptprinterextended/install/mysql/');
        if ($result < 0) {
            return -1;
        }

        // Give PRINTBRIDGE_DEFAULT_ENDPOINT a working value out of the box: the bundled
        // receiver bundled with this module (printbridgereceiver.php), so adopting/testing a
        // printer produces a real HTTP round trip immediately instead of a blank page (empty
        // Parameter) or a connection error (endpoint that doesn't exist yet). Only set if
        // still empty, so reactivating the module never clobbers an admin's real endpoint.
        if (getDolGlobalString('PRINTBRIDGE_DEFAULT_ENDPOINT') === '') {
            dolibarr_set_const(
                $this->db,
                'PRINTBRIDGE_DEFAULT_ENDPOINT',
                dol_buildpath('/receiptprinterextended/printbridgereceiver.php', 2),
                'chaine',
                0,
                '',
                $conf->entity
            );
        }

        return $this->_init(array(), $options);
    }

    /**
     * Module remove.
     *
     * @param string $options Options when disabling module
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
