<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 *      \file       pridgereceiver.php
 *      \ingroup    pridge
 *      \brief      Bundled Pridge receiver, used as the zero-config default endpoint.
 *
 * A real deployment should point Pridge profiles at an external print collector. Before
 * one exists, PRIDGE_DEFAULT_ENDPOINT is set to this script automatically on module
 * activation (see modPridge::init()), so adopting/testing a printer produces
 * a genuine, verifiable HTTP round trip instead of an empty Parameter (blank page) or a
 * Parameter pointing nowhere (connection error). This script just records what it received
 * and returns 200 - it never actually prints anything.
 *
 * Not a normal Dolibarr page: called server-to-server by PridgeClient (and, once a
 * profile is repointed at a real collector, never called again), so it skips the usual
 * login/session/menu bootstrap.
 */

if (!defined('NOLOGINREQUIRED')) {
    define('NOLOGINREQUIRED', '1');
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("main.inc.php")) {
    $res = @include "main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    echo "Method not allowed - Pridge receiver only accepts POST";
    exit;
}

// No auth token: Pridge is meant to reach a collector inside the same trusted Dolibarr
// environment, not an arbitrary internet endpoint (see PridgeClient/PridgeProfile).

$data = file_get_contents('php://input');
$profileref = isset($_SERVER['HTTP_X_PRIDGE_PROFILE']) ? $_SERVER['HTTP_X_PRIDGE_PROFILE'] : '';

$outputdir = DOL_DATA_ROOT.'/pridge';
dol_mkdir($outputdir);
file_put_contents($outputdir.'/lastreceived.bin', $data);

dolibarr_set_const(
    $db,
    'PRIDGE_LASTRECEIVED',
    $profileref.'|'.strlen($data).'|'.dol_now(),
    'chaine',
    0,
    '',
    $conf->entity
);

dol_syslog("pridgereceiver: received ".strlen($data)." byte(s) for profile '".$profileref."'");

header('Content-Type: text/plain');
http_response_code(200);
echo "OK";
