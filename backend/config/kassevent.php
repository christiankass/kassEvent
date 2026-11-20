<?php

return [
    // Disque des images (affiches d'événements) : « public » en local, un disque S3 (MinIO) en production.
    'media_disk' => env('MEDIA_DISK', 'public'),
];
