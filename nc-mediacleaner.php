<?php

require __DIR__ . '/vendor/autoload.php';

use Rikmeijer\NCMediaCleaner\Attempt;
use Rikmeijer\NCMediaCleaner\IO;

ini_set('memory_limit', '3G');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

if (isset($_ENV['NEXTCLOUD_URL']) === false) {
    exit('Nextcloud URL missing, please set in .env or as environment variable');
}
$parsed_url = parse_url($_ENV['NEXTCLOUD_URL']);

$origin = [$parsed_url['scheme'] . '://', $parsed_url['host']];
if (isset($parsed_url['port'])) {
    $origin[] = $parsed_url['port'];
}
define('NEXTCLOUD_URL', implode($origin));
IO::write('Working on ' . NEXTCLOUD_URL);

define('NEXTCLOUD_USER', $_ENV['NEXTCLOUD_USER'] ?? $parsed_url['user'] ?? null);
IO::write('Identifing as ' . NEXTCLOUD_USER ?? 'anonymous');

define('NEXTCLOUD_PASSWORD', $_ENV['NEXTCLOUD_PASSWORD'] ?? $parsed_url['pass'] ?? null);
if (NEXTCLOUD_PASSWORD !== null) {
    IO::write('Identifing with a password.');
} else {
    IO::write('Not using a password.');
}

define('NEXTCLOUD_UPLOAD_PATH', $parsed_url['path']);

$attempt = new Attempt(new Sabre\DAV\Client([
    'baseUri' => NEXTCLOUD_URL . '/remote.php/dav',
            'userName' => NEXTCLOUD_USER,
            'password' => NEXTCLOUD_PASSWORD
        ]));

$files_base_path = '/remote.php/dav/files/' . NEXTCLOUD_USER;
$regex_range = fn(string $start, string $end): string => join('|', array_map(fn($val) => str_pad($val, 2, '0', STR_PAD_LEFT), range($start, $end)));

$years = $regex_range('00', date('y'));
$months = $regex_range('01', '12');
$hours = $regex_range('00', '23');
$seconds = $minutes = $regex_range('00', '59');

$time = '(_(?<time>(?<hour>' . $hours . ')(?<minute>' . $minutes . ')(?<second>' . $seconds . ')))?';

$file_regexes = [
    '(^|\D)(?<year>(19|20)(' . $years . '))(?<month>' . $months . ')(?<day>[0-2][1-9]|3[0-1])' . $time,
    '(^|\D)(?<year>(19|20)(' . $years . '))(?<sep>[-_:])(?<month>' . $months . ')(?P=sep)(?<day>[0-2][1-9]|3[0-1])' . $time,
    '(^|\D)(?<day>[0-2][1-9]|3[0-1])(?<month>' . $months . ')(?<year>(19|20)(' . $years . '))' . $time
];

$media_properties = [
'{DAV:}displayname',
 '{DAV:}getcontentlength',
    '{DAV:}getcontenttype',
    '{DAV:}resourcetype',
    '{http://owncloud.org/ns}checksums',
    '{http://nextcloud.org/ns}creation_time'
];


IO::write("Finding similar files and moving files to the right location based on creation time or basename");

$options = $attempt('options');
if (in_array("nextcloud-checksum-update", $options) === false) {
    return IO::write('Cannot update checksum, so media comparison is impossible');
}

$cleanpath = fn(string $fullpath) => str_replace($files_base_path, '', $fullpath);

$move = function (string $file_path, string $destination) use ($attempt, &$move): bool {
    $result = $attempt('request', 'MOVE', $file_path, headers: [
        'Destination' => $destination,
        'Overwrite' => 'F'
    ]);

    switch ($result['statusCode']) {
        case 409:
            IO::write('destination is missing');
            $result = $attempt('request', 'MKCOL', dirname($destination));
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

$duplicate_candidates = [];
foreach ($attempt('propfind', $files_base_path . NEXTCLOUD_UPLOAD_PATH, $media_properties, 3) as $file_path => $available_media_file) {
    if (isset($available_media_file['{DAV:}getcontentlength']) === false) {
        continue;
    }

    $file_name = basename($file_path);

    $expected_location = date('Y/m', $available_media_file['{http://nextcloud.org/ns}creation_time']);
    foreach ($file_regexes as $file_regex) {
        if (preg_match('/' . $file_regex . '/', $available_media_file['{DAV:}displayname'], $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            continue;
        }

        switch (strlen($match['year'])) {
            case 4:
                $year = $match['year'];
                break;

            case 2:
                $year = '20' . $match['year'];
                break;

            default:
                IO::write('Unexpected year ' . $match['year']);
                return;
        }


        $expected_location = $year . '/' . $match['month'];
        if ($expected_location !== date('Y/m', $available_media_file['{http://nextcloud.org/ns}creation_time'])) {
            IO::write('[' . $available_media_file['{DAV:}displayname'] . '] Mismatch with creation time:' . date('r', $available_media_file['{http://nextcloud.org/ns}creation_time']));

            if (isset($match['time'])) {
                $time = $match['hour'] . ':' . $match['minute'] . ':' . $match['second'];
            } else {
                $time = '00:00:00';
            }

            $available_media_file['{http://nextcloud.org/ns}creation_time'] = strtotime($match['year'] . '-' . $match['month'] . '-' . $match['day'] . ' ' . $time);
            IO::write('Updating to ' . date('r', $available_media_file['{http://nextcloud.org/ns}creation_time']));
            $attempt('proppatch', $file_path, [
                '{http://nextcloud.org/ns}creation_time' => $available_media_file['{http://nextcloud.org/ns}creation_time']
            ]);
        }
        break;
    }
    $destination = $files_base_path . NEXTCLOUD_UPLOAD_PATH . '/' . $expected_location . '/' . $file_name;

    if ($file_path !== $destination) {
        IO::write('Actual locaton (' . $cleanpath($file_path) . ') differs from expected ' . $cleanpath($destination) . ', moving file.');
        if ($move($file_path, $destination)) {
            IO::write('moved');
            $file_path = $destination;
        } else {
            IO::write('failed');
        }
    }


    $hash = null;

    if (array_key_exists('{http://owncloud.org/ns}checksums', $available_media_file)) {
        foreach ($available_media_file['{http://owncloud.org/ns}checksums'] as $checksum) {
            list($algo, $possible_hash) = explode(':', $checksum['value'], 2);
            if (strcasecmp($algo, 'md5') === 0) {
                $hash = $possible_hash;
                break;
            }
        }
    }


    if (isset($hash) === false) {
        IO::write($file_path . ": hash recalc");
        $attempt('request', 'PATCH', $file_path, headers: [
            'X-Recalculate-Hash' => 'md5'
        ]);
        $file = $attempt('request', 'HEAD', $file_path, headers: [
            'X-Hash' => 'md5'
        ]);
        foreach ($file['headers']['oc-checksum'] as $checksum) {
            list($algo, $hash) = explode(':', $checksum, 2);
            if (strcasecmp($algo, 'md5') === 0) {
                break;
            }
        }
    }

    if (isset($duplicate_candidates[$hash]) === false) {
        $duplicate_candidates[$hash] = [];
    }
    $duplicate_candidates[$hash][$file_path] = $available_media_file;

    IO::write($file_path . ": " . $hash);
}

$duplicates = array_filter($duplicate_candidates, fn(array $item) => count($item) > 1);
if (count($duplicates) === 0) {
    return IO::write("Found no duplicate files");
}

IO::write("Found " . count($duplicates) . " duplicate files");
foreach ($duplicates as $duplicate_hash => $duplicate_group) {
    IO::write('Duplicate group ' . $duplicate_hash);
    $selection = [];
    foreach ($duplicate_group as $duplicate_path => $duplicate) {
        IO::write('[' . count($selection) . '] ' . $duplicate_path . ' (created ' . date('r', $duplicate['{http://nextcloud.org/ns}creation_time']) . '; ' . $duplicate['{DAV:}getcontentlength'] . ' B)');
        $selection[] = $duplicate_path;
    }

    while (($selected = (int) IO::numeric('Please select file to keep:')) >= count($selection)) {
        IO::write('Invalid option (0 - ' . count($selection) . ')');
    }

    IO::write('Keeping ' . $selection[$selected]);
    unset($selection[$selected]);
    foreach ($selection as $delete_path) {
        IO::write('Deleting ' . $delete_path);
        $attempt('request', 'DELETE', $delete_path);
    }
}