<?php
$string['pluginname'] = 'Course Versioning';
$string['admin_menu'] = 'Versioning Control';
$string['task_name'] = 'Process nightly classroom backups';
$string['request_success'] = 'Request received. It will be processed at midnight.';
$string['my_courses'] = 'My Available Courses';
$string['my_backups'] = 'My Ready Backups';
$string['messageprovider:backup_completed'] = 'Course backup completion notification';
$string['errorzstdcompression'] = 'Error compressing MBZ backup with Zstandard.';
$string['errorzstddecompression'] = 'Error decompressing Zstandard backup for restore.';
$string['invalidrepositorypath'] = 'External repository path is invalid or not writable: {$a}';
$string['errorrepositorycopy'] = 'Unable to copy compressed backup to external repository: {$a}';
$string['invalidrepositoryhost'] = 'You must configure the remote repository host/IP to enable external copy.';
$string['invalidrepositoryuser'] = 'You must configure the remote repository user.';
$string['invalidrepositorypassword'] = 'You must configure the remote user password for password authentication.';
$string['invalidrepositorykey'] = 'SSH private key path is invalid or unreadable: {$a}';
$string['errorrepositorytransport'] = 'Required remote transport tool is not available: {$a}';
$string['errorrepositoryconnect'] = 'Could not connect to the remote repository host: {$a}';
