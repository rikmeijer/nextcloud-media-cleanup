<?php

namespace Rikmeijer\NCMediaCleaner;

class IO {

    static function write(string $line): void {
        print PHP_EOL . '[' . date('Y-m-d H:i:s') . '] - ' . $line;
    }

    static function readJson(string $path): mixed {
        return json_decode(file_get_contents($path), true);
    }

}
