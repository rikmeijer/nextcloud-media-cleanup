<?php

namespace Rikmeijer\NCMediaCleaner;

class IO {

    static function write(string $line): void {
        print PHP_EOL . '[' . date('Y-m-d H:i:s') . '] - ' . $line;
    }

    static function read(string $line): string {
        IO::write($line);
        return fgets(STDIN);
    }

    static function numeric(string $line): string {
        while (is_numeric($value = IO::read($line)) === false) {
            IO::write('Value must be numeric');
        }
        return $value;
    }

    static function readJson(string $path): mixed {
        return json_decode(file_get_contents($path), true);
    }

}
