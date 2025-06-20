<?php

namespace Rikmeijer\NCMediaCleaner;

class RemoteFile {

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
