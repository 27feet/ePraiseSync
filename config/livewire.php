<?php

return [
    // Match the import page's 64 MB limit instead of Livewire's default 12 MB.
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:65536'],
        'max_upload_time' => 10,
    ],
];
