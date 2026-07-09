<?php
/**
 *      \file       admin/printbridge.php
 *      \ingroup    printbridge
 *      \brief      Setup page for the Print Bridge module.
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
require_once __DIR__.'/../class/printbridgeprofile.class.php';
require_once __DIR__.'/../class/printbridgebuiltinprinter.class.php';
require_once __DIR__.'/../class/printbridgelog.class.php';

// Load translation files required by the page. The @printbridge suffix is required for
// custom-module lang files - without it Dolibarr looks in core's own langs/ directory,
// silently fails to find it, and every key below falls back to its raw (unspaced PascalCase)
// name.
$langs->loadLangs(array('admin', 'printbridge@printbridge'));

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$profileid = GETPOSTINT('profileid');
$ref = GETPOST('ref', 'alphanohtml');
$endpoint = GETPOST('endpoint', 'alphanohtml');
$timeout = GETPOSTINT('timeout');
$builtinprinterid = GETPOSTINT('builtinprinterid');

$profile = new PrintBridgeProfile($db);
$builtinprinter = new PrintBridgeBuiltinPrinter($db);
$printbridgelog = new PrintBridgeLog($db);


/*
 * Actions
 */

if ($action == 'setconst') {
    dolibarr_set_const($db, 'PRINTBRIDGE_DEFAULT_ENDPOINT', GETPOST('defaultendpoint', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'PRINTBRIDGE_DEFAULT_TIMEOUT', GETPOSTINT('defaulttimeout'), 'chaine', 0, '', $conf->entity);

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
        $result = $profile->create($ref, $endpoint, $timeout);
        if ($result > 0) {
            setEventMessages($langs->trans('ProfileAdded', $ref), null);
        } else {
            setEventMessages($langs->trans($profile->error), null, 'errors');
        }
    }

    $action = '';
}

if ($action == 'updateprofile') {
    $result = $profile->update($profileid, $endpoint, $timeout);
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

if ($action == 'adopt') {
    $adoptref = 'printer_'.$builtinprinterid;
    $error = 0;

    if ($profile->fetchByRef($adoptref) <= 0) {
        $result = $profile->create($adoptref, '', 0);
        if ($result <= 0) {
            $error++;
            setEventMessages($langs->trans($profile->error), null, 'errors');
        }
    }

    if (!$error) {
        $result = $builtinprinter->setParameterToProfile($builtinprinterid, $adoptref);
        if ($result > 0) {
            setEventMessages($langs->trans('PrinterAdopted', $adoptref), null);
        } else {
            setEventMessages($langs->trans($builtinprinter->error), null, 'errors');
        }
    }

    $action = '';
}

if ($action == 'unadopt') {
    $result = $builtinprinter->clearParameter($builtinprinterid);
    if ($result > 0) {
        setEventMessages($langs->trans('PrinterUnadopted'), null);
    } else {
        setEventMessages($langs->trans($builtinprinter->error), null, 'errors');
    }

    $action = '';
}


/*
 * View
 */

$title = $langs->trans('PrintBridgeDesc');
llxHeader('', $title);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print $langs->trans('PrintBridgeDescLong').'<br><br>';


// Module-wide defaults

print load_fiche_titre($langs->trans('PrintBridgeDefaults'), '', '');

print '<form method="post" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setconst">';

print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DefaultEndpoint').'</td>';
print '<td>'.$langs->trans('DefaultTimeout').'</td>';
print '<td></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td><input class="minwidth200" type="text" name="defaultendpoint" value="'.dol_escape_htmltag(getDolGlobalString('PRINTBRIDGE_DEFAULT_ENDPOINT')).'"></td>';
print '<td><input class="width50" type="text" name="defaulttimeout" value="'.dol_escape_htmltag((string) getDolGlobalInt('PRINTBRIDGE_DEFAULT_TIMEOUT', 5)).'"></td>';
print '<td><input type="submit" class="button small" value="'.$langs->trans('Save').'"></td>';
print '</tr>';

print '</table>';
print '</form>';

$bundledendpoint = dol_buildpath('/printbridge/printbridgereceiver.php', 2);
if (getDolGlobalString('PRINTBRIDGE_DEFAULT_ENDPOINT') === $bundledendpoint) {
    print '<br>'.info_admin($langs->trans('UsingBundledReceiver'));
}

$lastreceived = getDolGlobalString('PRINTBRIDGE_LASTRECEIVED');
if ($lastreceived !== '') {
    list($lastref, $lastsize, $lastdate) = array_pad(explode('|', $lastreceived), 3, '');
    print '<br><span class="opacitymedium">'.$langs->trans('LastReceivedTicket', dol_escape_htmltag($lastref), (int) $lastsize, dol_print_date((int) $lastdate, 'dayhour')).'</span>';
}

print '<br>';


// Existing printers that can be adopted

print load_fiche_titre($langs->trans('AdoptExistingPrinters'), '', '');

print '<span class="opacitymedium">'.$langs->trans('AdoptExistingPrintersHelp').'</span><br><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Name').'</td>';
print '<td>'.$langs->trans('Parameters').'</td>';
print '<td></td>';
print '</tr>';

$builtinprinters = $builtinprinter->fetchFileTypePrinters();
if (empty($builtinprinters)) {
    print '<tr class="oddeven"><td colspan="3">'.$langs->trans('NoFileTypePrinterFound').'</td></tr>';
}

foreach ($builtinprinters as $line) {
    $isadopted = (strpos($line['parameter'], 'printbridge://') === 0);

    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($line['name']).'</td>';
    print '<td><code>'.dol_escape_htmltag($line['parameter']).'</code></td>';
    print '<td class="right">';
    if ($isadopted) {
        print '<span class="opacitymedium marginrightonly">'.$langs->trans('AlreadyAdopted').'</span>';
        print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=unadopt&token='.newToken().'&builtinprinterid='.((int) $line['rowid']).'">'.$langs->trans('Unadopt').'</a>';
    } else {
        print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=adopt&token='.newToken().'&builtinprinterid='.((int) $line['rowid']).'">'.$langs->trans('Adopt').'</a>';
    }
    print '</td>';
    print '</tr>';
}

print '</table>';

print '<br>';


// Profiles

print load_fiche_titre($langs->trans('PrintBridgeProfiles'), '', '');

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
print '<td>'.$langs->trans('Timeout').'</td>';
print '<td>'.$langs->trans('ProfileParameterValue').'</td>';
print '<td></td>';
print '</tr>';

$profiles = $profile->fetchAll();
foreach ($profiles as $line) {
    print '<tr class="oddeven">';

    if ($editingprofileid && $line['rowid'] == $editingprofileid) {
        print '<td>'.dol_escape_htmltag($line['ref']).'</td>';
        print '<td><input class="minwidth200" type="text" name="endpoint" value="'.dol_escape_htmltag($line['endpoint']).'"></td>';
        print '<td><input class="width50" type="text" name="timeout" value="'.((int) $line['timeout']).'"></td>';
        print '<td><code>printbridge://'.dol_escape_htmltag($line['ref']).'</code></td>';
        print '<td><input type="submit" class="button small" value="'.$langs->trans('Save').'"></td>';
    } else {
        print '<td>'.dol_escape_htmltag($line['ref']).'</td>';
        print '<td>'.dol_escape_htmltag($line['endpoint']).'</td>';
        print '<td>'.($line['timeout'] > 0 ? (int) $line['timeout'] : $langs->trans('UseDefaultValue')).'</td>';
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
    print '<td><input class="width50" type="text" name="timeout"></td>';
    print '<td></td>';
    print '<td><input type="submit" class="button small" value="'.$langs->trans('Add').'"></td>';
    print '</tr>';
}

print '</table>';
print '</form>';

print '<br>';
print '<span class="opacitymedium">'.$langs->trans('ProfileParameterInstructions').'</span>';

print '<br><br>';


// Recent prints (test log)

print load_fiche_titre($langs->trans('RecentPrints'), '', '');

print '<span class="opacitymedium">'.$langs->trans('RecentPrintsHelp', PrintBridgeLog::MAX_ENTRIES).'</span><br><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans('ProfileRef').'</td>';
print '<td>'.$langs->trans('Endpoint').'</td>';
print '<td>'.$langs->trans('Result').'</td>';
print '<td>'.$langs->trans('Size').'</td>';
print '<td></td>';
print '</tr>';

$logentries = $printbridgelog->fetchLast();
if (empty($logentries)) {
    print '<tr class="oddeven"><td colspan="6">'.$langs->trans('NoLogEntries').'</td></tr>';
}

foreach ($logentries as $logline) {
    if ($logline['endpoint'] === '') {
        $result = $langs->trans('LogResultNoEndpoint');
    } elseif ($logline['success']) {
        $result = $langs->trans('LogResultOk', $logline['httpcode']);
    } else {
        $result = $langs->trans('LogResultFailed', $logline['httpcode']);
    }

    $previewtext = PrintBridgeLog::naiveTextPreview($printbridgelog->fetchContent($logline['rowid']));

    print '<tr class="oddeven">';
    print '<td>'.dol_print_date($logline['datec'], 'dayhour').'</td>';
    print '<td>'.dol_escape_htmltag($logline['profile_ref']).'</td>';
    print '<td>'.dol_escape_htmltag($logline['endpoint']).'</td>';
    print '<td>'.$result.'</td>';
    print '<td>'.((int) $logline['size']).'</td>';
    print '<td><button type="button" class="button smallpaddingimp" data-preview="'.dol_escape_htmltag($previewtext).'" onclick="printbridgeShowPreview(this)">'.$langs->trans('Preview').'</button></td>';
    print '</tr>';
}

print '</table>';

print '<dialog id="printbridgepreviewdialog" style="max-width:600px;width:90%;">';
print '<form method="dialog">';
print '<pre id="printbridgepreviewbody" style="white-space:pre-wrap;word-break:break-word;max-height:60vh;overflow:auto;"></pre>';
print '<button type="submit" class="button">'.$langs->trans('Close').'</button>';
print '</form>';
print '</dialog>';
print '<script>
function printbridgeShowPreview(btn) {
    document.getElementById("printbridgepreviewbody").textContent = btn.getAttribute("data-preview");
    document.getElementById("printbridgepreviewdialog").showModal();
}
</script>';

llxFooter();
