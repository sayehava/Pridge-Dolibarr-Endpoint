<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * CRUD for Pridge profiles (table llx_pridge_profile).
 *
 * A profile is what a printer's Parameter value pridge://<ref> resolves to. It picks a
 * PridgeServer to submit jobs to (the real Pridge Server's plugin API always lives
 * at <server base_url>/api/plugin/jobs) plus its own endpoint token - the bearer token that
 * identifies which destination on that server the job belongs to. If no server is selected
 * (profile or module default), the raw `endpoint` field is used instead as a fallback
 * (e.g. for the bundled test receiver, which doesn't implement the real plugin API at all).
 * Any field left empty/unset falls back to the module-wide PRIDGE_DEFAULT_* constants.
 */
class PridgeProfile
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
     * @var string Short id used as pridge://<ref>
     */
    public $ref = '';

    /**
     * @var int PridgeServer row id to submit jobs to, 0 means "use module default"
     */
    public $serverId = 0;

    /**
     * @var string Endpoint token (bearer) for this profile's destination on that server,
     *             empty string means "use module default"
     */
    public $endpointToken = '';

    /**
     * @var string Raw endpoint URL fallback, only used when no server resolves. Empty string
     *             means "use module default"
     */
    public $endpoint = '';

    /**
     * @var int Timeout override in seconds, 0 means "use module default"
     */
    public $timeout = 0;

    /**
     * @var string Last error message
     */
    public $error = '';

    /**
     * @var string[] Last error messages
     */
    public $errors = array();

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
     * Load a profile by its ref (the id used after pridge://).
     *
     * @param string $ref Profile ref
     * @return int 1 if found, 0 if not found, -1 on error
     */
    public function fetchByRef($ref)
    {
        $sql = "SELECT rowid, ref, server_id, endpoint_token, endpoint, timeout";
        $sql .= " FROM ".MAIN_DB_PREFIX."pridge_profile";
        $sql .= " WHERE ref = '".$this->db->escape($ref)."'";
        $sql .= " AND entity IN (".getEntity('pridge_profile').")";

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
        $this->ref = $obj->ref;
        $this->serverId = (int) $obj->server_id;
        $this->endpointToken = $obj->endpoint_token;
        $this->endpoint = $obj->endpoint;
        $this->timeout = (int) $obj->timeout;

        return 1;
    }

    /**
     * Fetch all profiles, for the admin page listing.
     *
     * @return array<int,array<string,mixed>> List of profiles as plain arrays
     */
    public function fetchAll()
    {
        $list = array();

        $sql = "SELECT rowid, ref, server_id, endpoint_token, endpoint, timeout";
        $sql .= " FROM ".MAIN_DB_PREFIX."pridge_profile";
        $sql .= " WHERE entity IN (".getEntity('pridge_profile').")";
        $sql .= " ORDER BY ref ASC";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $list[] = array(
                    'rowid' => (int) $obj->rowid,
                    'ref' => $obj->ref,
                    'server_id' => (int) $obj->server_id,
                    'endpoint_token' => $obj->endpoint_token,
                    'endpoint' => $obj->endpoint,
                    'timeout' => (int) $obj->timeout,
                );
            }
        }

        return $list;
    }

    /**
     * Create a profile.
     *
     * @param string $ref           Short id used as pridge://<ref>
     * @param int    $serverid      PridgeServer row id, 0 to use PRIDGE_DEFAULT_SERVER_ID
     * @param string $endpointtoken Bearer token override, empty to use PRIDGE_DEFAULT_TOKEN
     * @param string $endpoint      Raw endpoint URL fallback, empty to use PRIDGE_DEFAULT_ENDPOINT
     * @param int    $timeout       Timeout override in seconds, 0 to use PRIDGE_DEFAULT_TIMEOUT
     * @return int >0 if OK, <=0 if KO
     */
    public function create($ref, $serverid, $endpointtoken, $endpoint, $timeout)
    {
        global $conf;

        if (empty($ref)) {
            $this->error = 'ProfileRefEmpty';
            return -1;
        }

        if ($this->fetchByRef($ref) > 0) {
            $this->error = 'ProfileRefAlreadyExists';
            return -1;
        }

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."pridge_profile";
        $sql .= " (entity, ref, server_id, endpoint_token, endpoint, timeout, datec)";
        $sql .= " VALUES (";
        $sql .= ((int) $conf->entity).",";
        $sql .= " '".$this->db->escape($ref)."',";
        $sql .= " ".((int) $serverid).",";
        $sql .= " '".$this->db->escape($endpointtoken)."',";
        $sql .= " '".$this->db->escape($endpoint)."',";
        $sql .= " ".((int) $timeout).",";
        $sql .= " '".$this->db->idate(dol_now())."'";
        $sql .= ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'pridge_profile');

        return 1;
    }

    /**
     * Update a profile's settings. The ref itself is not editable once created, so the
     * pridge://<ref> value already wired into a printer's Parameter field never breaks.
     *
     * @param int    $id            Profile row id
     * @param int    $serverid      PridgeServer row id, 0 to use module default
     * @param string $endpointtoken Bearer token override
     * @param string $endpoint      Raw endpoint URL fallback
     * @param int    $timeout       Timeout override
     * @return int >0 if OK, <=0 if KO
     */
    public function update($id, $serverid, $endpointtoken, $endpoint, $timeout)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."pridge_profile SET";
        $sql .= " server_id = ".((int) $serverid).",";
        $sql .= " endpoint_token = '".$this->db->escape($endpointtoken)."',";
        $sql .= " endpoint = '".$this->db->escape($endpoint)."',";
        $sql .= " timeout = ".((int) $timeout);
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity IN (".getEntity('pridge_profile').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }

    /**
     * Delete a profile.
     *
     * @param int $id Profile row id
     * @return int >0 if OK, <=0 if KO
     */
    public function delete($id)
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."pridge_profile";
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity IN (".getEntity('pridge_profile').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }

    /**
     * Resolve which server row id to submit jobs to: profile override or module default.
     * 0 means "no server - use the raw endpoint fallback instead".
     *
     * @return int
     */
    public function resolveServerId()
    {
        return $this->serverId > 0 ? $this->serverId : getDolGlobalInt('PRIDGE_DEFAULT_SERVER_ID');
    }

    /**
     * Resolve the final job submission URL: the resolved server's plugin API URL if one
     * resolves, otherwise the raw endpoint fallback (profile override or module default).
     *
     * @return string
     */
    public function resolveEndpointUrl()
    {
        $serverid = $this->resolveServerId();
        if ($serverid > 0) {
            require_once __DIR__.'/pridgeserver.class.php';
            $server = new PridgeServer($this->db);
            if ($server->fetch($serverid) > 0) {
                return $server->getJobsUrl();
            }
        }

        return $this->endpoint !== '' ? $this->endpoint : getDolGlobalString('PRIDGE_DEFAULT_ENDPOINT');
    }

    /**
     * Resolve the bearer token to use: profile override or module default. Empty when using
     * the raw endpoint fallback with no token configured (e.g. the bundled test receiver).
     *
     * @return string
     */
    public function resolveToken()
    {
        return $this->endpointToken !== '' ? $this->endpointToken : getDolGlobalString('PRIDGE_DEFAULT_TOKEN');
    }

    /**
     * Resolve the timeout to use: profile override or module default.
     *
     * @return int
     */
    public function getTimeout()
    {
        return $this->timeout > 0 ? $this->timeout : max(1, getDolGlobalInt('PRIDGE_DEFAULT_TIMEOUT', 5));
    }
}
