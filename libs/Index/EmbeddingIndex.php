<?php

declare(strict_types=1);

require_once __DIR__ . '/../Providers/LLMProvider.php';

/**
 * Flat-file embedding index over the Symcon object tree.
 *
 * Stock IP-Symcon on Windows does not ship the SQLite3 PHP extension, so
 * this index uses a single binary file:
 *
 *   header: "AISM" (4) | version uint16 LE | dim uint16 LE
 *         | modelLen uint16 LE | model bytes
 *   records: id int32 LE | type uint8 | nameLen uint16 LE | name
 *          | pathLen uint16 LE | path | profileLen uint16 LE | profile
 *          | vector (dim * float32 LE)
 *
 * Cosine similarity is computed in PHP. For typical Symcon installs
 * (<10k objects) this is fine. Loaded once per search call.
 */
class EmbeddingIndex
{
    private const MAGIC = 'AISM';
    private const VERSION = 1;

    private string $path;
    private LLMProvider $embedder;
    private string $embedModel;

    /** @var array<int,array{name:string,type:int,path:string,profile:string,vector:array<int,float>}>|null */
    private ?array $cache = null;
    private int $dim = 0;

    public function __construct(string $path, LLMProvider $embedder, string $embedModel = '')
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->embedder = $embedder;
        $this->embedModel = $embedModel;
    }

    public function count(): int
    {
        $this->load();
        return $this->cache === null ? 0 : count($this->cache);
    }

    public function getStoredModel(): string
    {
        $meta = $this->readHeader();
        return $meta['model'] ?? '';
    }

    /** Walk the entire object tree and rebuild the index. */
    public function rebuild(int $batchSize = 32): int
    {
        if (!function_exists('IPS_GetObject')) {
            throw new \RuntimeException('IPS_GetObject not available; rebuild must run inside Symcon');
        }

        $ids = $this->collectAllIds(0);
        $entries = [];
        foreach ($ids as $id) {
            $entry = $this->describe($id);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        $dim = 0;
        $records = [];
        foreach (array_chunk($entries, $batchSize) as $chunk) {
            $texts = array_column($chunk, 'text');
            $vectors = $this->embedder->embed($texts, ['model' => $this->embedModel]);
            foreach ($chunk as $i => $e) {
                $vec = $vectors[$i] ?? [];
                if ($dim === 0) {
                    $dim = count($vec);
                }
                if (count($vec) !== $dim) {
                    continue;
                }
                $records[$e['id']] = [
                    'name' => $e['name'],
                    'type' => $e['type'],
                    'path' => $e['path'],
                    'profile' => $e['profile'],
                    'vector' => $vec,
                ];
            }
        }

        $this->writeFile($records, $dim);
        $this->cache = $records;
        $this->dim = $dim;
        return count($records);
    }

    public function upsert(int $objectID): void
    {
        $entry = $this->describe($objectID);
        if ($entry === null) {
            $this->delete($objectID);
            return;
        }
        $this->load();
        $vec = $this->embedder->embed([$entry['text']], ['model' => $this->embedModel])[0] ?? [];
        if ($this->dim === 0) {
            $this->dim = count($vec);
        }
        if (count($vec) !== $this->dim) {
            return;
        }
        $this->cache[$entry['id']] = [
            'name' => $entry['name'],
            'type' => $entry['type'],
            'path' => $entry['path'],
            'profile' => $entry['profile'],
            'vector' => $vec,
        ];
        $this->writeFile($this->cache, $this->dim);
    }

    public function delete(int $objectID): void
    {
        $this->load();
        if ($this->cache === null) {
            return;
        }
        unset($this->cache[$objectID]);
        $this->writeFile($this->cache, $this->dim);
    }

    /**
     * @return array<int,array{id:int,name:string,type:int,path:string,score:float}>
     */
    public function search(string $query, int $k = 8, ?int $typeFilter = null): array
    {
        $this->load();
        if (empty($this->cache)) {
            return [];
        }
        $vec = $this->embedder->embed([$query], ['model' => $this->embedModel])[0] ?? [];
        if (count($vec) !== $this->dim || $this->dim === 0) {
            return [];
        }
        $qNorm = self::norm($vec);
        if ($qNorm == 0.0) {
            return [];
        }

        $out = [];
        foreach ($this->cache as $id => $rec) {
            if ($typeFilter !== null && $rec['type'] !== $typeFilter) {
                continue;
            }
            $score = self::dot($vec, $rec['vector']) / ($qNorm * self::norm($rec['vector']) ?: 1.0);
            $out[] = [
                'id' => $id,
                'name' => $rec['name'],
                'type' => $rec['type'],
                'path' => $rec['path'],
                'score' => $score,
            ];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $k);
    }

    // ---------- internals ----------

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        if (!is_file($this->path)) {
            $this->cache = [];
            $this->dim = 0;
            return;
        }
        $fp = @fopen($this->path, 'rb');
        if ($fp === false) {
            $this->cache = [];
            $this->dim = 0;
            return;
        }
        try {
            $magic = fread($fp, 4);
            if ($magic !== self::MAGIC) {
                $this->cache = [];
                return;
            }
            $hdr = unpack('vver/vdim/vmlen', (string) fread($fp, 6));
            $modelStored = $hdr['mlen'] > 0 ? (string) fread($fp, $hdr['mlen']) : '';
            $dim = (int) $hdr['dim'];
            if ($this->embedModel !== '' && $modelStored !== '' && $modelStored !== $this->embedModel) {
                // Model changed → index is stale.
                $this->cache = [];
                $this->dim = 0;
                return;
            }
            $this->dim = $dim;
            $records = [];
            while (!feof($fp)) {
                $idBin = fread($fp, 4);
                if ($idBin === '' || $idBin === false || strlen($idBin) < 4) {
                    break;
                }
                $id = unpack('lid', $idBin)['id'];
                $type = ord((string) fread($fp, 1));
                $nameLen = unpack('v', (string) fread($fp, 2))[1];
                $name = $nameLen > 0 ? (string) fread($fp, $nameLen) : '';
                $pathLen = unpack('v', (string) fread($fp, 2))[1];
                $path = $pathLen > 0 ? (string) fread($fp, $pathLen) : '';
                $profLen = unpack('v', (string) fread($fp, 2))[1];
                $profile = $profLen > 0 ? (string) fread($fp, $profLen) : '';
                $vecBin = fread($fp, $dim * 4);
                if ($vecBin === false || strlen($vecBin) < $dim * 4) {
                    break;
                }
                $vector = array_values(unpack('g*', $vecBin) ?: []);
                $records[(int) $id] = [
                    'name' => $name,
                    'type' => $type,
                    'path' => $path,
                    'profile' => $profile,
                    'vector' => $vector,
                ];
            }
            $this->cache = $records;
        } finally {
            fclose($fp);
        }
    }

    private function readHeader(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $fp = @fopen($this->path, 'rb');
        if ($fp === false) {
            return [];
        }
        try {
            $magic = fread($fp, 4);
            if ($magic !== self::MAGIC) {
                return [];
            }
            $hdr = unpack('vver/vdim/vmlen', (string) fread($fp, 6));
            $model = $hdr['mlen'] > 0 ? (string) fread($fp, $hdr['mlen']) : '';
            return ['version' => $hdr['ver'], 'dim' => $hdr['dim'], 'model' => $model];
        } finally {
            fclose($fp);
        }
    }

    /** @param array<int,array{name:string,type:int,path:string,profile:string,vector:array<int,float>}> $records */
    private function writeFile(array $records, int $dim): void
    {
        $tmp = $this->path . '.tmp';
        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open $tmp for writing");
        }
        try {
            fwrite($fp, self::MAGIC);
            fwrite($fp, pack('vvv', self::VERSION, $dim, strlen($this->embedModel)));
            if ($this->embedModel !== '') {
                fwrite($fp, $this->embedModel);
            }
            foreach ($records as $id => $r) {
                fwrite($fp, pack('l', $id));
                fwrite($fp, chr($r['type'] & 0xFF));
                fwrite($fp, pack('v', strlen($r['name'])));
                fwrite($fp, $r['name']);
                fwrite($fp, pack('v', strlen($r['path'])));
                fwrite($fp, $r['path']);
                fwrite($fp, pack('v', strlen($r['profile'])));
                fwrite($fp, $r['profile']);
                fwrite($fp, pack('g*', ...$r['vector']));
            }
        } finally {
            fclose($fp);
        }
        @rename($tmp, $this->path);
    }

    /** @return int[] */
    private function collectAllIds(int $root): array
    {
        $out = [];
        $stack = [$root];
        while (!empty($stack)) {
            $cur = array_pop($stack);
            $children = @IPS_GetChildrenIDs($cur);
            if (!is_array($children)) {
                continue;
            }
            foreach ($children as $c) {
                $out[] = (int) $c;
                $stack[] = (int) $c;
            }
        }
        return $out;
    }

    /**
     * @return array{id:int,name:string,type:int,path:string,profile:string,text:string}|null
     */
    private function describe(int $id): ?array
    {
        $obj = @IPS_GetObject($id);
        if (!is_array($obj)) {
            return null;
        }
        $name = (string) ($obj['ObjectName'] ?? '');
        $type = (int) ($obj['ObjectType'] ?? 0);
        $path = self::pathOf($id);
        $profile = '';
        $extra = '';
        if ($type === 2 && function_exists('IPS_GetVariable')) {
            $var = @IPS_GetVariable($id);
            if (is_array($var)) {
                $profile = (string) ($var['VariableCustomProfile'] ?? $var['VariableProfile'] ?? '');
                $extra = ' variable profile=' . $profile . ' type=' . ($var['VariableType'] ?? '');
            }
        } elseif ($type === 1 && function_exists('IPS_GetInstance')) {
            $inst = @IPS_GetInstance($id);
            if (is_array($inst)) {
                $extra = ' module=' . (string) ($inst['ModuleInfo']['ModuleName'] ?? '');
            }
        }
        $text = trim($name . ' | ' . $path . $extra);
        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'path' => $path,
            'profile' => $profile,
            'text' => $text,
        ];
    }

    private static function pathOf(int $id): string
    {
        $parts = [];
        $cur = $id;
        $guard = 0;
        while ($cur > 0 && $guard++ < 50) {
            $o = @IPS_GetObject($cur);
            if (!is_array($o)) {
                break;
            }
            array_unshift($parts, (string) ($o['ObjectName'] ?? ''));
            $cur = (int) ($o['ParentID'] ?? 0);
        }
        return implode(' / ', $parts);
    }

    /** @param float[] $a @param float[] $b */
    private static function dot(array $a, array $b): float
    {
        $s = 0.0;
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $s += $a[$i] * $b[$i];
        }
        return $s;
    }

    /** @param float[] $v */
    private static function norm(array $v): float
    {
        return sqrt(self::dot($v, $v));
    }
}
