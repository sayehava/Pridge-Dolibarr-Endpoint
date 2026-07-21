<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Checks GitHub for new releases of this module and lets an admin update it from the
 * setup page. Updating is deliberately a two-step, explicit process: prepare() takes a
 * full backup and downloads/stages the release without touching any live file, and
 * apply() - a separate action - is what actually overlays the staged files onto the
 * module. Nothing here ever runs without an explicit admin click; there is no automatic
 * or scheduled update.
 */
require_once __DIR__.'/pridgeclient.class.php';

class PridgeUpdater
{
    /**
     * @var DoliDB
     */
    protected $db;

    const RELEASES_API = 'https://api.github.com/repos/sayehava/Pridge-Dolibarr-Endpoint/releases/latest';
    const CHECK_INTERVAL_SECONDS = 3600;
    const MAX_BACKUPS_KEPT = 5;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Module root directory (the folder containing admin/, class/, core/, ...).
     *
     * @return string
     */
    public function moduleRoot()
    {
        return dirname(__DIR__);
    }

    /**
     * Writable directory for backups and staged downloads, outside the module tree so an
     * update never has to worry about overwriting its own working files.
     *
     * @return string
     */
    public function dataDir()
    {
        $dir = DOL_DATA_ROOT.'/pridge/updates';
        if (!is_dir($dir)) {
            dol_mkdir($dir);
        }

        return $dir;
    }

    public function backupsDir()
    {
        $dir = $this->dataDir().'/backups';
        if (!is_dir($dir)) {
            dol_mkdir($dir);
        }

        return $dir;
    }

    public function stagingDir()
    {
        return $this->dataDir().'/staging';
    }

    /**
     * @return array{tag:string, version:string, notes:string, published_at:string, zip_url:string}|null
     */
    public function latestKnown()
    {
        $version = getDolGlobalString('PRIDGE_UPDATE_LATEST_VERSION');
        if ($version === '') {
            return null;
        }

        return array(
            'tag' => getDolGlobalString('PRIDGE_UPDATE_LATEST_TAG'),
            'version' => $version,
            'notes' => getDolGlobalString('PRIDGE_UPDATE_LATEST_NOTES'),
            'published_at' => getDolGlobalString('PRIDGE_UPDATE_LATEST_PUBLISHED_AT'),
            'zip_url' => getDolGlobalString('PRIDGE_UPDATE_LATEST_ZIP_URL'),
        );
    }

    public function lastCheckError()
    {
        $error = getDolGlobalString('PRIDGE_UPDATE_LAST_CHECK_ERROR');

        return $error === '' ? null : $error;
    }

    public function lastCheckedAt()
    {
        $value = getDolGlobalString('PRIDGE_UPDATE_LAST_CHECK_AT');

        return $value === '' ? null : $value;
    }

    public function isUpdateAvailable()
    {
        $known = $this->latestKnown();

        return $known !== null && version_compare($known['version'], PridgeClient::MODULE_VERSION) > 0;
    }

    public function checkForUpdate($force = false)
    {
        global $conf;

        $lastChecked = getDolGlobalString('PRIDGE_UPDATE_LAST_CHECK_AT');
        if (!$force && $lastChecked !== '' && (time() - strtotime($lastChecked)) < self::CHECK_INTERVAL_SECONDS) {
            return;
        }

        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_LAST_CHECK_AT', dol_print_date(dol_now(), 'dayhourrfc'), 'chaine', 0, '', $conf->entity);

        try {
            $release = $this->fetchLatestRelease();
        } catch (Exception $exception) {
            dolibarr_set_const($this->db, 'PRIDGE_UPDATE_LAST_CHECK_ERROR', $exception->getMessage(), 'chaine', 0, '', $conf->entity);
            return;
        }

        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_LAST_CHECK_ERROR', '', 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_LATEST_TAG', $release['tag'], 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_LATEST_VERSION', $release['version'], 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_LATEST_NOTES', $release['notes'], 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_LATEST_PUBLISHED_AT', $release['published_at'], 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_LATEST_ZIP_URL', $release['zip_url'], 'chaine', 0, '', $conf->entity);
    }

    /**
     * @return array<int, array{name:string, path:string, size:int, created_at:string}>
     */
    public function listBackups()
    {
        $files = glob($this->backupsDir().'/backup-*.zip') ?: array();
        rsort($files);

        $backups = array();
        foreach ($files as $file) {
            $backups[] = array(
                'name' => basename($file),
                'path' => $file,
                'size' => (int) filesize($file),
                'created_at' => gmdate('Y-m-d H:i:s', (int) filemtime($file)),
            );
        }

        return $backups;
    }

    public function createBackup()
    {
        global $conf;

        if (!class_exists('ZipArchive')) {
            throw new Exception('The PHP zip extension is required to create a backup.');
        }

        $path = $this->backupsDir().'/backup-'.gmdate('Ymd-His').'-v'.PridgeClient::MODULE_VERSION.'.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Could not create the backup archive.');
        }

        $this->addDirectoryToZip($zip, $this->moduleRoot(), $this->moduleRoot());
        $zip->close();

        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_LAST_BACKUP_PATH', $path, 'chaine', 0, '', $conf->entity);

        foreach (array_slice($this->listBackups(), self::MAX_BACKUPS_KEPT) as $old) {
            @unlink($old['path']);
        }

        return $path;
    }

    /**
     * @return array{version:string, staged_at:string, backup_path:string}|null
     */
    public function stagedInfo()
    {
        $version = getDolGlobalString('PRIDGE_UPDATE_STAGED_VERSION');
        if ($version === '' || !is_dir($this->stagingDir())) {
            return null;
        }

        return array(
            'version' => $version,
            'staged_at' => getDolGlobalString('PRIDGE_UPDATE_STAGED_AT'),
            'backup_path' => getDolGlobalString('PRIDGE_UPDATE_STAGED_BACKUP_PATH'),
        );
    }

    /**
     * Takes a backup, then downloads and extracts the latest known release into a staging
     * directory. Nothing here touches the live module - apply() is a separate step.
     *
     * @return string Staged version
     */
    public function prepareUpdate()
    {
        global $conf;

        $known = $this->latestKnown();
        if ($known === null || $known['zip_url'] === '') {
            throw new Exception('No known update to prepare. Check for updates first.');
        }

        if (!class_exists('ZipArchive')) {
            throw new Exception('The PHP zip extension is required to install updates.');
        }

        $backupPath = $this->createBackup();
        $this->clearStaging();

        $tempZip = $this->dataDir().'/staging-download.zip';
        $this->downloadToFile($known['zip_url'], $tempZip);

        $zip = new ZipArchive();
        if ($zip->open($tempZip) !== true) {
            @unlink($tempZip);
            throw new Exception('The downloaded update is not a valid archive.');
        }

        dol_mkdir($this->stagingDir());
        $extracted = $zip->extractTo($this->stagingDir());
        $zip->close();
        @unlink($tempZip);

        if (!$extracted) {
            $this->clearStaging();
            throw new Exception('Could not extract the downloaded update.');
        }

        $stagedRoot = $this->findStagedRoot();
        if ($stagedRoot === null) {
            $this->clearStaging();
            throw new Exception('The downloaded update does not look like a valid Pridge Dolibarr Endpoint release.');
        }

        $stagedVersion = $this->readStagedVersion($stagedRoot);
        if ($stagedVersion === null) {
            $this->clearStaging();
            throw new Exception('Could not determine the version of the downloaded update.');
        }

        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_STAGED_VERSION', $stagedVersion, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_STAGED_AT', dol_print_date(dol_now(), 'dayhourrfc'), 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_STAGED_BACKUP_PATH', $backupPath, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_STAGED_ROOT', $stagedRoot, 'chaine', 0, '', $conf->entity);

        return $stagedVersion;
    }

    /**
     * Overlays the staged release onto the live module. Any file that fails to copy is
     * reported, since a partial copy can leave the module in a mixed state that only a
     * restore from backup can safely resolve.
     */
    public function applyStaged()
    {
        global $conf;

        $staged = $this->stagedInfo();
        $stagedRoot = getDolGlobalString('PRIDGE_UPDATE_STAGED_ROOT');
        if ($staged === null || $stagedRoot === '' || !is_dir($stagedRoot)) {
            throw new Exception('There is no staged update to apply. Prepare it again.');
        }

        $failures = $this->copyDirectory($stagedRoot, $this->moduleRoot());

        $this->clearStaging();
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_STAGED_VERSION', '', 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_STAGED_ROOT', '', 'chaine', 0, '', $conf->entity);

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (!empty($failures)) {
            throw new Exception(
                'The update finished, but '.count($failures).' file(s) could not be written (a permission '
                .'issue?), so the module may be in a mixed state. Restore the backup taken before this update '
                .'immediately. First failed file: '.$failures[0]
            );
        }
    }

    public function discardStaged()
    {
        global $conf;

        $this->clearStaging();
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_STAGED_VERSION', '', 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'PRIDGE_UPDATE_STAGED_ROOT', '', 'chaine', 0, '', $conf->entity);
    }

    /**
     * Restores this module's files from a backup taken by createBackup(). $backupPath
     * must be a file inside backupsDir(); anything else is rejected.
     */
    public function rollback($backupPath)
    {
        $backupsDir = realpath($this->backupsDir());
        $realBackupPath = realpath($backupPath);

        if ($backupsDir === false || $realBackupPath === false || strpos($realBackupPath, $backupsDir.DIRECTORY_SEPARATOR) !== 0) {
            throw new Exception('Invalid backup file.');
        }

        if (!class_exists('ZipArchive')) {
            throw new Exception('The PHP zip extension is required to restore a backup.');
        }

        $restoreDir = $this->dataDir().'/restore-tmp';
        if (is_dir($restoreDir)) {
            $this->removeDirectory($restoreDir);
        }
        dol_mkdir($restoreDir);

        $zip = new ZipArchive();
        if ($zip->open($realBackupPath) !== true) {
            $this->removeDirectory($restoreDir);
            throw new Exception('Could not open the backup archive.');
        }
        $extracted = $zip->extractTo($restoreDir);
        $zip->close();

        if (!$extracted) {
            $this->removeDirectory($restoreDir);
            throw new Exception('Could not extract the backup archive.');
        }

        $failures = $this->copyDirectory($restoreDir, $this->moduleRoot());
        $this->removeDirectory($restoreDir);

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (!empty($failures)) {
            throw new Exception('The restore finished, but '.count($failures).' file(s) could not be written. First failed file: '.$failures[0]);
        }
    }

    protected function fetchLatestRelease()
    {
        $body = $this->httpGet(self::RELEASES_API);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['tag_name']) || empty($data['zipball_url'])) {
            throw new Exception('GitHub did not return a valid release.');
        }

        $tag = (string) $data['tag_name'];

        return array(
            'tag' => $tag,
            'version' => ltrim($tag, 'v'),
            'notes' => isset($data['body']) && is_string($data['body']) ? substr($data['body'], 0, 4000) : '',
            'published_at' => isset($data['published_at']) && is_string($data['published_at']) ? $data['published_at'] : '',
            'zip_url' => (string) $data['zipball_url'],
        );
    }

    protected function httpGet($url)
    {
        if (!function_exists('curl_init')) {
            throw new Exception('The PHP curl extension is required to check for updates.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Pridge-Dolibarr-Endpoint-Updater/'.PridgeClient::MODULE_VERSION,
            CURLOPT_HTTPHEADER => array('Accept: application/vnd.github+json'),
        ));
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new Exception('Could not reach GitHub: '.$error);
        }
        if ($status < 200 || $status >= 300) {
            throw new Exception('GitHub returned HTTP '.$status.'.');
        }

        return (string) $result;
    }

    protected function downloadToFile($url, $destination)
    {
        if (!function_exists('curl_init')) {
            throw new Exception('The PHP curl extension is required to download updates.');
        }

        $fp = fopen($destination, 'wb');
        if ($fp === false) {
            throw new Exception('Could not create a temporary file for the download.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Pridge-Dolibarr-Endpoint-Updater/'.PridgeClient::MODULE_VERSION,
        ));
        $ok = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($destination);
            throw new Exception('Download failed: '.($error !== '' ? $error : ('HTTP '.$status)));
        }
    }

    protected function clearStaging()
    {
        if (is_dir($this->stagingDir())) {
            $this->removeDirectory($this->stagingDir());
        }
    }

    /**
     * GitHub's auto-generated release zip is a single prefixed folder; find it.
     *
     * @return string|null
     */
    protected function findStagedRoot()
    {
        $dir = $this->stagingDir();
        $entries = array_values(array_diff(scandir($dir) ?: array(), array('.', '..')));

        if (count($entries) === 1 && is_dir($dir.'/'.$entries[0])) {
            return $dir.'/'.$entries[0];
        }

        return is_file($dir.'/core/modules/modPridge.class.php') ? $dir : null;
    }

    protected function readStagedVersion($stagedRoot)
    {
        $contents = @file_get_contents($stagedRoot.'/core/modules/modPridge.class.php');
        if ($contents === false) {
            return null;
        }

        if (preg_match("/\\\$this->version\\s*=\\s*'([^']+)'/", $contents, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function addDirectoryToZip(ZipArchive $zip, $sourceRoot, $currentDir)
    {
        $entries = scandir($currentDir) ?: array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $currentDir.'/'.$entry;
            $relativePath = ltrim(substr($fullPath, strlen($sourceRoot)), '/');

            if (is_dir($fullPath)) {
                $zip->addEmptyDir($relativePath);
                $this->addDirectoryToZip($zip, $sourceRoot, $fullPath);
            } else {
                $zip->addFile($fullPath, $relativePath);
            }
        }
    }

    /**
     * @return array<int, string> Destination paths that could not be written
     */
    protected function copyDirectory($source, $destination)
    {
        $failures = array();
        $entries = scandir($source) ?: array();

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source.'/'.$entry;
            $destinationPath = $destination.'/'.$entry;

            if (is_dir($sourcePath)) {
                if (!is_dir($destinationPath) && !@mkdir($destinationPath, 0750, true) && !is_dir($destinationPath)) {
                    $failures[] = $destinationPath;
                    continue;
                }
                $failures = array_merge($failures, $this->copyDirectory($sourcePath, $destinationPath));
            } elseif (!@copy($sourcePath, $destinationPath)) {
                $failures[] = $destinationPath;
            }
        }

        return $failures;
    }

    protected function removeDirectory($directory)
    {
        $entries = scandir($directory) ?: array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
