<?php
declare(strict_types=1);

namespace PbdKn\cohSensorcollector\Sensor\Km271;

/** Serializes the collector and web API on the same Raspberry. */
final class Km271ConnectionLock
{
    private $handle;

    public function __construct(string $host, int $port)
    {
        // /run/lock is shared even when Apache uses systemd PrivateTmp.
        $directory = PHP_OS_FAMILY === 'Linux' ? '/run/lock' : sys_get_temp_dir();
        $path = $directory . '/coh-km271-' . hash('sha256', strtolower($host) . ':' . $port) . '.lock';
        $this->handle = @fopen($path, 'x+');
        if (is_resource($this->handle)) {
            @chmod($path, 0644);
        } else {
            // flock also works on a read handle; avoids Linux protected_regular
            // restrictions when the web API and collector have different users.
            $this->handle = @fopen($path, 'r');
        }
        if (!is_resource($this->handle)) throw new \RuntimeException('KM271-Verbindungssperre konnte nicht geöffnet werden.');
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            throw new \RuntimeException('KM271 wird gerade vom Collector oder einem anderen Auftrag verwendet. Bitte später erneut versuchen.');
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
