<?php
/**
 *      \file       admin/receiptprinterextended.php
 *      \ingroup    receiptprinterextended
 *      \brief      Setup page for the Receipt Printers - Extended (PrintBridge) module.
 *
 * Manages PrintBridge profiles and module-wide defaults only. This page does not manage
 * printers or ticket templates - those stay owned by Dolibarr's built-in Receipt Printers
 * module (Setup > Receipt Printers). See README.md for the two-step setup.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../class/receiptprinterextendedprofile.class.php';

// Load translation files required by the page
$langs->loadLangs(array('admin', 'receiptprinterextended'));

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$profileid = GETPOSTINT('profileid');
$ref = GETPOST('ref', 'alphanohtml');
$endpoint = GETPOST('endpoint', 'alphanohtml');
$token = GETPOST('token', 'alphanohtml');
$timeout = GETPOSTINT('timeout');
$verifyssl = GETPOSTINT('verifyssl');

$profile = new ReceiptPrinterExtendedProfile($db);


/*
 * Actions
 */

if ($action == 'setconst') {
    dolibarr_set_const($db, 'PRINTBRIDGE_DEFAULT_ENDPOINT', GETPOST('defaultendpoint', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'PRINTBRIDGE_DEFAULT_TOKEN', GETPOST('defaulttoken', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'PRINTBRIDGE_DEFAULT_TIMEOUT', GETPOSTINT('defaulttimeout'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'PRINTBRIDGE_DEFAULT_VERIFY_SSL', GETPOSTINT('defaultverifyssl'), 'chaine', 0, '', $conf->entity);

    setEventMessages($langs->trans('SetupSaved'), null);
    $action = '';
}

if ($action == 'add') {
    $error = 0;

    if (empty($ref)) {
        $error++;
        setEventMessages($langs->trans('ProfileRefEmpty'), null, 'errors');
    }

    if (!$error) {
        $result = $profile->create($ref, $endpoint, $token, $timeout, $verifyssl);
        if ($result > 0) {
            setEventMessages($langs->trans('ProfileAdded', $ref), null);
        } else {
            setEventMessages($langs->trans($profile->error), null, 'errors');
        }
    }

    $action = '';
}

if ($action == 'updateprofile') {
    $result = $profile->update($profileid, $endpoint, $token, $timeout, $verifyssl);
    if ($result > 0) {
        setEventMessages($langs->trans('ProfileUpdated'), null);
    } else {
        setEventMessages($langs->trans($profile->error), null, 'errors');
    }

    $action = '';
}

if ($action == 'delete') {
    $result = $profile->delete($profileid);
    if ($result > 0) {
        setEventMessages($langs->trans('ProfileDeleted'), null);
    } else {
        setEventMessages($langs->trans($profile->error), null, 'errors');
    }

    $action = '';
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('ReceiptPrinterExtendedDesc');
llxHeader('', $title);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print $langs->trans('ReceiptPrinterExtendedDescLong').'<br><br>';


// Module-wide defaults

print load_fiche_titre($langs->trans('PrintBridgeDefaults'), '', '');

print '<form method="post" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setconst">';

print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DefaultEndpoint').'</td>';
print '<td>'.$langs->trans('DefaultToken').'</td>';
print '<td>'.$langs->trans('DefaultTimeout').'</td>';
print '<td>'.$langs->trans('DefaultVerifySSL').'</td>';
print '<td></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td><input class="minwidth200" type="text" name="defaultendpoint" value="'.dol_escape_htmltag(getDolGlobalString('PRINTBRIDGE_DEFAULT_ENDPOINT')).'"></td>';
print '<td><input class="minwidth150" type="text" name="defaulttoken" value="'.dol_escape_htmltag(getDolGlobalString('PRINTBRIDGE_DEFAULT_TOKEN')).'"></td>';
print '<td><input class="width50" type="text" name="defaulttimeout" value="'.dol_escape_htmltag((string) getDolGlobalInt('PRINTBRIDGE_DEFAULT_TIMEOUT', 5)).'"></td>';
print '<td>'.$form->selectyesno('defaultverifyssl', getDolGlobalInt('PRINTBRIDGE_DEFAULT_VERIFY_SSL', 1), 1).'</td>';
print '<td><input type="submit" class="button small" value="'.$langs->trans('Save').'"></td>';
print '</tr>';

print '</table>';
print '</form>';

print '<br>';


// Profiles

print load_fiche_titre($langs->trans('PrintBridgeProfiles'), '', '');

$verifysslchoices = array(
    -1 => $langs->trans('UseDefaultValue'),
    1 => $langs->trans('Yes'),
    0 => $langs->trans('No'),
);

$editingprofileid = ($action == 'editprofile') ? $profileid : 0;

print '<form method="post" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="'.($editingprofileid ? 'updateprofile' : 'add').'">';
if ($editingprofileid) {
    print '<input type="hidden" name="profileid" value="'.((int) $editingprofileid).'">';
}

print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td>'.$langs->trans('ProfileRef').'</td>';
print '<td>'.$langs->trans('Endpoint').'</td>';
print '<td>'.$langs->trans('Token').'</td>';
print '<td>'.$langs->trans('Timeout').'</td>';
print '<td>'.$langs->trans('VerifySSL').'</td>';
print '<td>'.$langs->trans('ProfileParameterValue').'</td>';
print '<td></td>';
print '</tr>';

$profiles = $profile->fetchAll();
foreach ($profiles as $line) {
    print '<tr class="oddeven">';

    if ($editingprofileid && $line['rowid'] == $editingprofileid) {
        print '<td>'.dol_escape_htmltag($line['ref']).'</td>';
        print '<td><input class="minwidth200" type="text" name="endpoint" value="'.dol_escape_htmltag($line['endpoint']).'"></td>';
        print '<td><input class="minwidth150" type="text" name="token" value="'.dol_escape_htmltag($line['token']).'"></td>';
        print '<td><input class="width50" type="text" name="timeout" value="'.((int) $line['timeout']).'"></td>';
        print '<td>'.$form->selectarray('verifyssl', $verifysslchoices, $line['verify_ssl']).'</td>';
        print '<td><code>printbridge://'.dol_escape_htmltag($line['ref']).'</code></td>';
        print '<td><input type="submit" class="button small" value="'.$langs->trans('Save').'"></td>';
    } else {
        print '<td>'.dol_escape_htmltag($line['ref']).'</td>';
        print '<td>'.dol_escape_htmltag($line['endpoint']).'</td>';
        print '<td>'.($line['token'] !== '' ? '••••••••' : '').'</td>';
        print '<td>'.($line['timeout'] > 0 ? (int) $line['timeout'] : $langs->trans('UseDefaultValue')).'</td>';
        print '<td>'.$verifysslchoices[$line['verify_ssl'] >= 0 ? ($line['verify_ssl'] ? 1 : 0) : -1].'</td>';
        print '<td><code>printbridge://'.dol_escape_htmltag($line['ref']).'</code></td>';
        print '<td class="nowraponall">';
        print '<a class="editfielda marginrightonly" href="'.$_SERVER['PHP_SELF'].'?action=editprofile&token='.newToken().'&profileid='.((int) $line['rowid']).'">'.img_edit().'</a>';
        print '<a class="marginrightonly" href="'.$_SERVER['PHP_SELF'].'?action=delete&token='.newToken().'&profileid='.((int) $line['rowid']).'">'.img_delete().'</a>';
        print '</td>';
    }

    print '</tr>';
}

// Add line, hidden while editing an existing profile (the form's hidden action is
// "updateprofile" in that case, so this row's inputs would never be read anyway)
if (!$editingprofileid) {
    print '<tr class="oddeven">';
    print '<td><input class="minwidth100" type="text" name="ref" placeholder="'.dol_escape_htmltag($langs->trans('ProfileRefHelp')).'"></td>';
    print '<td><input class="minwidth200" type="text" name="endpoint"></td>';
    print '<td><input class="minwidth150" type="text" name="token"></td>';
    print '<td><input class="width50" type="text" name="timeout"></td>';
    print '<td>'.$form->selectarray('verifyssl', $verifysslchoices, -1).'</td>';
    print '<td></td>';
    print '<td><input type="submit" class="button small" value="'.$langs->trans('Add').'"></td>';
    print '</tr>';
}

print '</table>';
print '</form>';

print '<br>';
print '<span class="opacitymedium">'.$langs->trans('ProfileParameterInstructions').'</span>';

llxFooter();
