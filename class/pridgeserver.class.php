<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * CRUD for Pridge servers (table llx_pridge_server).
 *
 * A server is just a base URL for a real PrintBridge Server instance (see README.md for
 * its plugin API). Profiles pick a server plus their own endpoint token; the
 * submission URL is always <base_url>/api/plugin/jobs.
 */
class PridgeServer
{
    /**
     * @var DoliDB Database handler
     */
    private $db;

    /**
     * @var int Row id
     */
    public $id = 0;

    /**
     * @var string Display name
     */
    public $name = '';

    /**
     * @var string Base URL, e.g. https://pridge.example.com
     */
    public $baseUrl = '';

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
     * Load a server by its row id.
     *
     * @param int $id Row id
     * @return int 1 if found, 0 if not found, -1 on error
     */
    public function fetch($id)
    {
        $sql = "SELECT rowid, name, base_url";
        $sql .= " FROM ".MAIN_DB_PREFIX."pridge_server";
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity IN (".getEntity('pridge_server').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return 0;
        }

        $this->id = (int) $obj->rowid;
        $this->name = $obj->name;
        $this->baseUrl = $obj->base_url;

        return 1;
    }

    /**
     * Fetch all servers, for dropdowns and the admin page listing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll()
    {
        $list = array();

        $sql = "SELECT rowid, name, base_url";
        $sql .= " FROM ".MAIN_DB_PREFIX."pridge_server";
        $sql .= " WHERE entity IN (".getEntity('pridge_server').")";
        $sql .= " ORDER BY name ASC";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $list[] = array(
                    'rowid' => (int) $obj->rowid,
                    'name' => $obj->name,
                    'base_url' => $obj->base_url,
                );
            }
        }

        return $list;
    }

    /**
     * Create a server.
     *
     * @param string $name    Display name
     * @param string $baseurl Base URL, e.g. https://pridge.example.com
     * @return int >0 if OK, <=0 if KO
     */
    public function create($name, $baseurl)
    {
        global $conf;

        if (empty($name)) {
            $this->error = 'ServerNameEmpty';
            return -1;
        }

        if (empty($baseurl)) {
            $this->error = 'ServerBaseUrlEmpty';
            return -1;
        }

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."pridge_server";
        $sql .= " (entity, name, base_url, datec)";
        $sql .= " VALUES (";
        $sql .= ((int) $conf->entity).",";
        $sql .= " '".$this->db->escape($name)."',";
        $sql .= " '".$this->db->escape(rtrim($baseurl, '/'))."',";
        $sql .= " '".$this->db->idate(dol_now())."'";
        $sql .= ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'pridge_server');

        return 1;
    }

    /**
     * Update a server.
     *
     * @param int    $id      Row id
     * @param string $name    Display name
     * @param string $baseurl Base URL
     * @return int >0 if OK, <=0 if KO
     */
    public function update($id, $name, $baseurl)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."pridge_server SET";
        $sql .= " name = '".$this->db->escape($name)."',";
        $sql .= " base_url = '".$this->db->escape(rtrim($baseurl, '/'))."'";
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity IN (".getEntity('pridge_server').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }

    /**
     * Delete a server.
     *
     * @param int $id Row id
     * @return int >0 if OK, <=0 if KO
     */
    public function delete($id)
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."pridge_server";
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity IN (".getEntity('pridge_server').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }

    /**
     * Build the plugin job submission URL for this server.
     *
     * @return string
     */
    public function getJobsUrl()
    {
        return rtrim($this->baseUrl, '/').'/api/plugin/jobs';
    }

    /**
     * Build <option> tags for a server picker, with a leading "no server" choice.
     *
     * @param array<int,array<string,mixed>> $servers   Servers from fetchAll()
     * @param int                             $selected  Currently selected server id, 0 for none
     * @param string                          $nonelabel Label for the "no server" option
     * @return string
     */
    public static function buildOptions($servers, $selected, $nonelabel)
    {
        $html = '<option value="0"'.($selected == 0 ? ' selected' : '').'>'.dol_escape_htmltag($nonelabel).'</option>';
        foreach ($servers as $s) {
            $html .= '<option value="'.((int) $s['rowid']).'"'.($selected == $s['rowid'] ? ' selected' : '').'>'.dol_escape_htmltag($s['name']).'</option>';
        }

        return $html;
    }
}
