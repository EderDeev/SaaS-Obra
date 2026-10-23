<?php

return [
    // The local disk uses the protected persistent area when running on Railway.
    'attachment_disk' => env('OS_DNIT_ATTACHMENT_DISK', 'local'),
];
