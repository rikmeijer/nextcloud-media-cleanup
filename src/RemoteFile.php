<?php

namespace Rikmeijer\NCMediaCleaner;

class RemoteFile {

    static function existsTest(callable $attempt, string $directory, array $media_properties): callable {
        return fn(string $available_filename) => $attempt('propfind', $directory . '/' . urlencode($available_filename), $media_properties);
    }

    static function findAvailable(callable $test, string $orig_filename) {
        $lastdotpos = strrpos($orig_filename, '.');
        $filename = substr($orig_filename, 0, $lastdotpos);
        $extension = substr($orig_filename, $lastdotpos + 1);

        if (preg_match('/\(\d+\)$/', $filename, $increment_counter_match) === 1) {
            $filename = substr($filename, 0, 0 - strlen($increment_counter_match[0]));
        }

        $available_filename = $filename . '.' . $extension;
        $tries = 0;
        do {
            try {
                IO::write('Trying ' . $available_filename);
                $existing_file = $test($available_filename);
                $available_filename = $filename . '(' . ++$tries . ').' . $extension;
            } catch (Sabre\HTTP\ClientHttpException $e) {
                $existing_file = null;
            }
        } while (isset($existing_file));

        return $available_filename;
    }

    static function move(callable $attempt): callable {
        return function (string $file_path, string $destination) use ($attempt): bool {
            $result = $attempt('request', 'MOVE', $file_path, headers: [
                'Destination' => $destination,
                'Overwrite' => 'F'
            ]);

            switch ($result['statusCode']) {
                case 409:
                    IO::write('destination is missing');
                    $result = $attempt('request', 'MKCOL', dirname($destination));
                    $move = self::move($attempt);
                    return $result['statusCode'] === 201 ? $move($file_path, $destination) : false;

                case 412:
                    IO::write('destination already exists');
                    return false;

                case 415:
                    IO::write('destination is not a collection');
                    return false;

                case 201:
                    return true;

                default:
                    IO::write($result['statusCode']);
                    return false;
            }
        };
    }
}
