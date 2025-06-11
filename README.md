# nextcloud-media-cleanup
Simple script that moves nextcloud media to a directory (Y/m) based on their filename or creation date

```.env
NEXTCLOUD_URL=<NEXTCLOUD_URL> # e.g. https://user@s3cr3t:cloud.example.com/Photos
NEXTCLOUD_USER=usernamehere # Optional, overwrites user in NEXTCLOUD_URL
NEXTCLOUD_PASSWORD=secret # Optional, overwrites password in NEXTCLOUD_URL
```
```
$ php nc-mediacleaner.php
```