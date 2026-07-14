<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Rolling log of the last N Pridge tickets (table llx_pridge_log), kept purely for
 * testing: lets an admin preview what was actually printed and whether/where it was forwarded,
 * without needing a real print collector. Every ticket is logged regardless of whether an
 * endpoint was configured or the forward succeeded - see
 * PridgeStreamWrapper::stream_close(), the only caller of record().
 *
 * Content is stored base64-encoded: raw ESC/POS bytes include control bytes that are simplest
 * to keep out of a text column's charset/collation handling entirely.
 */
class PridgeLog
{
    /**
     * How many entries to keep. Older rows are pruned after every insert.
     */
    const MAX_ENTRIES = 10;

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
     * Maximum length of the stored response body. Just enough to see a server's error message
     * (e.g. why a request got a 403), not meant to store large payloads.
     */
    const MAX_RESPONSE_LENGTH = 2000;

    /**
     * Record one ticket, then prune anything beyond the MAX_ENTRIES most recent rows.
     *
     * @param string $profileref Profile ref the ticket was for (may be unknown/blank)
     * @param string $endpoint   Endpoint it was forwarded to, empty if none was configured
     * @param bool   $success    Whether the forward succeeded (always false if $endpoint is empty)
     * @param int    $httpcode   HTTP status code from the forward attempt, 0 if none was attempted
     * @param string $rawdata    Raw ESC/POS bytes
     * @param string $response   Response body from the forward attempt, empty if none was attempted
     * @return int >0 if OK, <=0 if KO
     */
    public function record($profileref, $endpoint, $success, $httpcode, $rawdata, $response = '')
    {
        global $conf;

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."pridge_log";
        $sql .= " (entity, datec, profile_ref, endpoint, success, httpcode, size, content, response)";
        $sql .= " VALUES (";
        $sql .= ((int) $conf->entity).",";
        $sql .= " '".$this->db->idate(dol_now())."',";
        $sql .= " '".$this->db->escape($profileref)."',";
        $sql .= " '".$this->db->escape($endpoint)."',";
        $sql .= " ".($success ? 1 : 0).",";
        $sql .= " ".((int) $httpcode).",";
        $sql .= " ".((int) strlen($rawdata)).",";
        $sql .= " '".$this->db->escape(base64_encode($rawdata))."',";
        $sql .= " '".$this->db->escape(substr($response, 0, self::MAX_RESPONSE_LENGTH))."'";
        $sql .= ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $this->prune();

        return 1;
    }

    /**
     * Delete every entry beyond the MAX_ENTRIES most recent ones for this entity.
     *
     * @return void
     */
    private function prune()
    {
        global $conf;

        $sql = "DELETE FROM ".MAIN_DB_PREFIX."pridge_log";
        $sql .= " WHERE entity = ".((int) $conf->entity);
        $sql .= " AND rowid NOT IN (";
        $sql .= "SELECT rowid FROM (";
        $sql .= "SELECT rowid FROM ".MAIN_DB_PREFIX."pridge_log";
        $sql .= " WHERE entity = ".((int) $conf->entity);
        $sql .= " ORDER BY rowid DESC LIMIT ".((int) self::MAX_ENTRIES);
        $sql .= ") AS keepids)";

        $this->db->query($sql);
    }

    /**
     * Fetch the most recent entries, without their content (for the list view).
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchLast()
    {
        $list = array();

        $sql = "SELECT rowid, datec, profile_ref, endpoint, success, httpcode, size";
        $sql .= " FROM ".MAIN_DB_PREFIX."pridge_log";
        $sql .= " WHERE entity IN (".getEntity('pridge_log').")";
        $sql .= " ORDER BY rowid DESC";
        $sql .= " LIMIT ".((int) self::MAX_ENTRIES);

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $list[] = array(
                    'rowid' => (int) $obj->rowid,
                    'datec' => $this->db->jdate($obj->datec),
                    'profile_ref' => $obj->profile_ref,
                    'endpoint' => $obj->endpoint,
                    'success' => (bool) $obj->success,
                    'httpcode' => (int) $obj->httpcode,
                    'size' => (int) $obj->size,
                );
            }
        }

        return $list;
    }

    /**
     * Fetch one entry's raw content, decoded, for the preview action.
     *
     * @param int $id Log row id
     * @return string|null Raw ESC/POS bytes, or null if not found
     */
    public function fetchContent($id)
    {
        $sql = "SELECT content FROM ".MAIN_DB_PREFIX."pridge_log";
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity IN (".getEntity('pridge_log').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return null;
        }

        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }

        return base64_decode($obj->content);
    }

    /**
     * Fetch one entry's stored response body, for the preview action.
     *
     * @param int $id Log row id
     * @return string|null Response body, or null if not found
     */
    public function fetchResponse($id)
    {
        $sql = "SELECT response FROM ".MAIN_DB_PREFIX."pridge_log";
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity IN (".getEntity('pridge_log').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return null;
        }

        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }

        return $obj->response;
    }

    /**
     * Strip everything but printable ASCII and newlines, so ESC/POS control codes and raster
     * image bytes don't show up as garbage - leaves roughly what a human would recognize as
     * the ticket's actual text content. Not a real ESC/POS renderer, just enough to eyeball
     * whether the right ticket went out.
     *
     * @param string $rawdata Raw ESC/POS bytes
     * @return string Printable-only approximation
     */
    public static function naiveTextPreview($rawdata)
    {
        $out = '';
        $len = strlen($rawdata);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($rawdata[$i]);
            if ($byte === 0x0A || ($byte >= 0x20 && $byte <= 0x7E)) {
                $out .= $rawdata[$i];
            }
        }

        return $out;
    }
}
