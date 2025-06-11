<?php

require __DIR__ . '/vendor/autoload.php';

use Rikmeijer\NCMediaCleaner\Attempt;
use Rikmeijer\NCMediaCleaner\IO;

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
$padleft = fn(array $arr) => array_map(fn($val) => str_pad($val, 2, '0', STR_PAD_LEFT), $arr);

$years = join('|', $padleft(range('00', date('y'))));
$months = join('|', $padleft(range('01', '12')));
$file_regexes = [
    '(^|\D)(?<year>(19|20)(' . $years . '))(?<month>' . $months . ')(?<day>[0-2][1-9]|3[0-1])',
    '(^|\D)(?<year>(19|20)(' . $years . '))(?<sep>[-_:])(?<month>' . $months . ')(?P=sep)(?<day>[0-2][1-9]|3[0-1])',
    '(^|\D)(?<day>[0-2][1-9]|3[0-1])(?<month>' . $months . ')(?<year>(19|20)(' . $years . '))'
];

$propfind = fn(string $path) => array_slice($attempt('propfind', $path, [
            '{DAV:}displayname',
            '{DAV:}getcontentlength'
                        ], 1), 1);

foreach ($propfind($files_base_path . NEXTCLOUD_UPLOAD_PATH) as $year_directory_id => $available_year_directory) {
    $year = basename($year_directory_id);

    foreach ($propfind($year_directory_id) as $month_directory_id => $available_month_directory) {
        $month = basename($month_directory_id);
        $actual_location = $year . '/' . $month;
        IO::write('Actual locaton: ' . $actual_location);
        $available_media_files = array_slice($attempt('propfind', $month_directory_id, [
            '{DAV:}displayname',
            '{DAV:}getcontentlength',
            '{http://nextcloud.org/ns}creation_time'
                        ], 1), 1);

        IO::write(count($available_media_files) . ' files');
        foreach ($available_media_files as $file_id => $available_media_file) {
            $expected_location = date('Y/m', $available_media_file['{http://nextcloud.org/ns}creation_time']);

            foreach ($file_regexes as $file_regex) {
                if (preg_match('/' . $file_regex . '/', basename($file_id), $match, PREG_UNMATCHED_AS_NULL) !== 1) {
                    continue;
                }

                $expected_location = $match['year'] . '/' . $match['month'];
                break;
            }

            if ($actual_location !== $expected_location) {
                IO::write('Moving ' . $file_id . ' to ' . $expected_location);
                $attempt('request', 'MOVE', $file_id, headers: [
                    'Destination' => $files_base_path . NEXTCLOUD_UPLOAD_PATH . '/' . $expected_location
                ]);
            }
        }
    }
}