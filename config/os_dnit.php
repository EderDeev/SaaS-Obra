<?php

return [
    // Use a private disk with persistent storage before deploying to Railway.
    'attachment_disk' => env('OS_DNIT_ATTACHMENT_DISK', 'local'),
];
