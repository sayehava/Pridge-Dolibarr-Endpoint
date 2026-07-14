<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Hook actions for module Pridge Dolibarr Endpoint.
 *
 * Loaded by Dolibarr's HookManager on every request, because the module descriptor claims
 * the 'all' hook context. Its only job is to register the pridge:// stream wrapper in
 * the constructor, early enough (before any print action runs) for
 * Mike42\Escpos\PrintConnectors\FilePrintConnector's fopen() call to be intercepted. See
 * the README "Technical Design" for why this is necessary and why it works.
 *
 * Class name must be exactly "Actions".ucfirst($module) where $module is the module's
 * lowercase technical name ("pridge") - this is how Dolibarr's HookManager::initHooks()
 * derives the class it instantiates. Do not rename this class without also matching whatever
 * modPridge::$name resolves to.
 */

require_once __DIR__.'/pridgestreamwrapper.class.php';

/**
 * Actions class for module Pridge Dolibarr Endpoint.
 */
class ActionsPridge
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Last error message
     */
    public $error = '';

    /**
     * @var string[] Last error messages
     */
    public $errors = array();

    /**
     * Constructor. Registers the pridge:// stream wrapper, guarded so repeated
     * instantiation within the same request (or across multiple initHooks() contexts) never
     * tries to register it twice.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;

        if (!in_array(PridgeStreamWrapper::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(PridgeStreamWrapper::PROTOCOL, 'PridgeStreamWrapper');
            dol_syslog("ActionsPridge: registered pridge:// stream wrapper on ".$_SERVER['PHP_SELF']);
        }
    }
}
