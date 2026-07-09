<?php
/**
 * CRUD for PrintBridge profiles (table llx_printbridge_profile).
 *
 * A profile is what a printer's Parameter value printbridge://<ref> resolves to: which
 * PrintBridge endpoint/timeout to use. Any field left empty/unset falls back to the
 * module-wide PRINTBRIDGE_DEFAULT_* constants (see admin page). No auth token and no SSL
 * verification toggle - PrintBridge is meant to reach a collector inside the same trusted
 * Dolibarr environment, not an arbitrary internet endpoint.
 */
class PrintBridgeProfile
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
     * @var string Short id used as printbridge://<ref>
     */
    public $ref = '';

    /**
     * @var string Endpoint override, empty string means "use module default"
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
     * Load a profile by its ref (the id used after printbridge://).
     *
     * @param string $ref Profile ref
     * @return int 1 if found, 0 if not found, -1 on error
     */
    public function fetchByRef($ref)
    {
        $sql = "SELECT rowid, ref, endpoint, timeout";
        $sql .= " FROM ".MAIN_DB_PREFIX."printbridge_profile";
        $sql .= " WHERE ref = '".$this->db->escape($ref)."'";
        $sql .= " AND entity IN (".getEntity('printbridge_profile').")";

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

        $sql = "SELECT rowid, ref, endpoint, timeout";
        $sql .= " FROM ".MAIN_DB_PREFIX."printbridge_profile";
        $sql .= " WHERE entity IN (".getEntity('printbridge_profile').")";
        $sql .= " ORDER BY ref ASC";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $list[] = array(
                    'rowid' => (int) $obj->rowid,
                    'ref' => $obj->ref,
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
     * @param string $ref      Short id used as printbridge://<ref>
     * @param string $endpoint Endpoint override, empty to use PRINTBRIDGE_DEFAULT_ENDPOINT
     * @param int    $timeout  Timeout override in seconds, 0 to use PRINTBRIDGE_DEFAULT_TIMEOUT
     * @return int >0 if OK, <=0 if KO
     */
    public function create($ref, $endpoint, $timeout)
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

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."printbridge_profile";
        $sql .= " (entity, ref, endpoint, timeout, datec)";
        $sql .= " VALUES (";
        $sql .= ((int) $conf->entity).",";
        $sql .= " '".$this->db->escape($ref)."',";
        $sql .= " '".$this->db->escape($endpoint)."',";
        $sql .= " ".((int) $timeout).",";
        $sql .= " '".$this->db->idate(dol_now())."'";
        $sql .= ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'printbridge_profile');

        return 1;
    }

    /**
     * Update a profile's settings. The ref itself is not editable once created, so the
     * printbridge://<ref> value already wired into a printer's Parameter field never breaks.
     *
     * @param int    $id       Profile row id
     * @param string $endpoint Endpoint override
     * @param int    $timeout  Timeout override
     * @return int >0 if OK, <=0 if KO
     */
    public function update($id, $endpoint, $timeout)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."printbridge_profile SET";
        $sql .= " endpoint = '".$this->db->escape($endpoint)."',";
        $sql .= " timeout = ".((int) $timeout);
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity IN (".getEntity('printbridge_profile').")";

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
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."printbridge_profile";
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity IN (".getEntity('printbridge_profile').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }

    /**
     * Resolve the endpoint to use: profile override or module default.
     *
     * @return string
     */
    public function getEndpoint()
    {
        return $this->endpoint !== '' ? $this->endpoint : getDolGlobalString('PRINTBRIDGE_DEFAULT_ENDPOINT');
    }

    /**
     * Resolve the timeout to use: profile override or module default.
     *
     * @return int
     */
    public function getTimeout()
    {
        return $this->timeout > 0 ? $this->timeout : max(1, getDolGlobalInt('PRINTBRIDGE_DEFAULT_TIMEOUT', 5));
    }
}
