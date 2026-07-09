<?php
/**
 * PHP stream wrapper that intercepts the printbridge:// scheme.
 *
 * Dolibarr's built-in Receipt Printers module (and TakePOS) send tickets through
 * Mike42\Escpos\PrintConnectors\FilePrintConnector, whose entire implementation is a plain
 * fopen()/fwrite()/fclose() sequence on the printer's "Parameter" value. Pointing that value
 * at printbridge://<profile-ref> routes those calls here instead of the filesystem: bytes are
 * buffered in memory and, on stream_close(), POSTed over HTTPS to the PrintBridge endpoint
 * configured for that profile.
 *
 * This class is instantiated once per request by class/actions_printbridge.class.php
 * (a Dolibarr hook), which is what registers the scheme via stream_wrapper_register(). See
 * the README "Technical Design" for the full mechanism and why Dummy connector cannot be used
 * instead.
 */
class PrintBridgeStreamWrapper
{
    /**
     * @var string Scheme this wrapper is registered for
     */
    const PROTOCOL = 'printbridge';

    /**
     * @var resource|null Stream context, set by PHP itself
     */
    public $context;

    /**
     * @var string Profile ref parsed out of printbridge://<ref>
     */
    private $profileRef = '';

    /**
     * @var string Bytes written so far
     */
    private $buffer = '';

    /**
     * @var int Read position into $buffer, only used if something reads back from the stream
     */
    private $position = 0;

    /**
     * Called by PHP on fopen(). Mode is ignored: this stream only ever buffers writes and
     * forwards them on close, regardless of whether escpos-php opened it "wb+", "rb" or "ab".
     *
     * @param string $path       The printbridge://<ref> URL passed to fopen()
     * @param string $mode       fopen() mode (unused)
     * @param int    $options    fopen() options (unused)
     * @param string $openedPath Set to the opened path (unused)
     * @return bool True if a profile ref could be parsed out of $path
     */
    public function stream_open($path, $mode, $options, &$openedPath)
    {
        $parts = parse_url($path);
        $this->profileRef = isset($parts['host']) ? $parts['host'] : '';
        $this->buffer = '';
        $this->position = 0;

        dol_syslog("PrintBridgeStreamWrapper::stream_open: path='".$path."' profileRef='".$this->profileRef."'");

        return $this->profileRef !== '';
    }

    /**
     * Called by PHP on fwrite(). Just buffers the bytes; nothing is sent until stream_close().
     *
     * @param string $data Bytes to buffer
     * @return int Number of bytes buffered
     */
    public function stream_write($data)
    {
        $this->buffer .= $data;

        return strlen($data);
    }

    /**
     * Called by PHP on fread(). Not expected to be used in the ticket-printing flow (thermal
     * printers don't talk back over this connector), kept only so fopen("...", "wb+") does not
     * break if something ever calls fread() on the handle.
     *
     * @param int $count Number of bytes requested
     * @return string Bytes from the current read position
     */
    public function stream_read($count)
    {
        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    /**
     * @return bool True once the read position reaches the end of the buffer
     */
    public function stream_eof()
    {
        return $this->position >= strlen($this->buffer);
    }

    /**
     * @return int Current read position
     */
    public function stream_tell()
    {
        return $this->position;
    }

    /**
     * @return bool Always true: there is nothing to flush ahead of stream_close()
     */
    public function stream_flush()
    {
        return true;
    }

    /**
     * Called by PHP on fclose(). This is where the buffered ESC/POS ticket is actually
     * forwarded to the PrintBridge endpoint configured for the parsed profile ref - and,
     * regardless of whether an endpoint exists or the forward succeeds, recorded into the
     * rolling test log (PrintBridgeLog), so a ticket is never silently lost.
     *
     * @return void
     */
    public function stream_close()
    {
        if ($this->buffer === '' || $this->profileRef === '') {
            dol_syslog(
                "PrintBridgeStreamWrapper::stream_close: nothing to do (buffer="
                .strlen($this->buffer)." bytes, profileRef='".$this->profileRef."')"
            );
            return;
        }

        dol_syslog("PrintBridgeStreamWrapper::stream_close: closing, ".strlen($this->buffer)." byte(s) for profile '".$this->profileRef."'");

        global $db;

        require_once __DIR__.'/printbridgeprofile.class.php';
        require_once __DIR__.'/printbridgeclient.class.php';
        require_once __DIR__.'/printbridgelog.class.php';

        $profile = new PrintBridgeProfile($db);
        $endpoint = '';
        $success = false;
        $httpcode = 0;

        if ($profile->fetchByRef($this->profileRef) > 0) {
            $endpoint = $profile->getEndpoint();
            if ($endpoint !== '') {
                $client = new PrintBridgeClient();
                $success = $client->send($profile, $this->buffer);
                $httpcode = $client->lastHttpCode;
            } else {
                dol_syslog(
                    "PrintBridgeStreamWrapper::stream_close: no endpoint configured for profile '".$this->profileRef."', logging only",
                    LOG_WARNING
                );
            }
        } else {
            dol_syslog("PrintBridgeStreamWrapper::stream_close: unknown profile ref '".$this->profileRef."'", LOG_WARNING);
        }

        $log = new PrintBridgeLog($db);
        $logresult = $log->record($this->profileRef, $endpoint, $success, $httpcode, $this->buffer);

        dol_syslog(
            "PrintBridgeStreamWrapper::stream_close: logged (record() returned ".$logresult.")"
            .($logresult <= 0 ? ", error=".$log->error : '')
        );

        $this->buffer = '';
    }

    /**
     * @return array<string,int> Minimal stat info, enough for callers that check file size
     */
    public function stream_stat()
    {
        return array(
            'dev' => 0, 'ino' => 0, 'mode' => 0100666, 'nlink' => 0,
            'uid' => 0, 'gid' => 0, 'rdev' => 0,
            'size' => strlen($this->buffer),
            'atime' => 0, 'mtime' => 0, 'ctime' => 0,
            'blksize' => -1, 'blocks' => -1,
        );
    }
}
