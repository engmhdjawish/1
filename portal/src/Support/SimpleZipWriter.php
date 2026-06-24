<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Minimal ZIP writer (store only) — works without the PHP zip extension.
 */
final class SimpleZipWriter
{
    /** @var resource|null */
    private $handle;

    /** @var list<array{name: string, crc: int, size: int, offset: int}> */
    private array $entries = [];

    public function open(string $path): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('تعذر إنشاء ملف ZIP.');
        }

        $this->handle = $handle;
        $this->entries = [];
    }

    public function addFileFromPath(string $filePath, string $entryName): void
    {
        if ($this->handle === null) {
            throw new \RuntimeException('ملف ZIP غير مفتوح.');
        }
        if (!is_file($filePath)) {
            throw new \RuntimeException('ملف غير موجود للإضافة إلى ZIP.');
        }

        $entryName = str_replace('\\', '/', trim($entryName));
        if ($entryName === '') {
            throw new \RuntimeException('اسم ملف داخل ZIP غير صالح.');
        }

        $offset = (int) ftell($this->handle);
        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new \RuntimeException('تعذر قراءة الملف للضغط.');
        }

        $size = strlen($data);
        $crc = self::crc32Unsigned($data);
        $nameBytes = $entryName;

        fwrite(
            $this->handle,
            pack('V', 0x04034b50)
            . pack('v', 20)
            . pack('v', 0x0800)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('V', $crc)
            . pack('V', $size)
            . pack('V', $size)
            . pack('v', strlen($nameBytes))
            . pack('v', 0)
            . $nameBytes
        );
        fwrite($this->handle, $data);

        $this->entries[] = [
            'name' => $entryName,
            'crc' => $crc,
            'size' => $size,
            'offset' => $offset,
        ];
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        $centralOffset = (int) ftell($this->handle);
        $centralSize = 0;

        foreach ($this->entries as $entry) {
            $nameBytes = $entry['name'];
            $header = pack('V', 0x02014b50)
                . pack('v', 20)
                . pack('v', 20)
                . pack('v', 0x0800)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('V', $entry['crc'])
                . pack('V', $entry['size'])
                . pack('V', $entry['size'])
                . pack('v', strlen($nameBytes))
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('V', 0)
                . pack('V', $entry['offset'])
                . $nameBytes;
            fwrite($this->handle, $header);
            $centralSize += strlen($header);
        }

        fwrite(
            $this->handle,
            pack('V', 0x06054b50)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', count($this->entries))
            . pack('v', count($this->entries))
            . pack('V', $centralSize)
            . pack('V', $centralOffset)
            . pack('v', 0)
        );

        fclose($this->handle);
        $this->handle = null;
    }

    private static function crc32Unsigned(string $data): int
    {
        return crc32($data) & 0xffffffff;
    }
}
