<?php

function categorize_message($message) {
    // Default fields
    $default_fields = [
        'event' => '',
        'path' => '',
        'file_folder' => '',
        'size' => '',
        'user' => '',
        'ip' => '',
        'category' => 'Information' // Default to 'Information'
    ];

    // Pattern rules for extracting structured data
    $rules = [
        [
            'pattern' => '/User \[([^\]]+)\] from \[([^\]]+)\] signed in to \[([^\]]+)\] successfully via \[([^\]]+)\]/',
            'extract' => function($m) {
                return [
                    'event' => 'sign-in',
                    'user' => $m[1] ?? '',
                    'ip' => $m[2] ?? '',
                    'path' => $m[3] ?? '',
                    'file_folder' => $m[4] ?? ''
                ];
            }
        ],
        [
            'pattern' => '/Event: mkdir, Path: ([^,]+), File\/Folder: ([^,]+), Size: ([^,]+), User: ([^,]+), IP: ([^,]*)$/',
            'extract' => function($m) {
                return [
                    'event' => 'mkdir',
                    'path' => $m[1] ?? '',
                    'file_folder' => $m[2] ?? '',
                    'size' => $m[3] ?? '',
                    'user' => $m[4] ?? '',
                    'ip' => $m[5] ?? ''
                ];
            }
        ],
        [
            'pattern' => '/Event: delete, Path: ([^,]+), File\/Folder: ([^,]+), Size: ([^,]+), User: ([^,]+), IP: ([^,]*)$/',
            'extract' => function($m) {
                return [
                    'event' => 'delete',
                    'path' => $m[1] ?? '',
                    'file_folder' => $m[2] ?? '',
                    'size' => $m[3] ?? '',
                    'user' => $m[4] ?? '',
                    'ip' => $m[5] ?? ''
                ];
            }
        ],
        [
            'pattern' => '/Event: copy, Path: ([^,]+), File\/Folder: ([^,]+), Size: ([^,]+), User: ([^,]+), IP: ([^,]*)$/',
            'extract' => function($m) {
                return [
                    'event' => 'upload',
                    'path' => $m[1] ?? '',
                    'file_folder' => $m[2] ?? '',
                    'size' => $m[3] ?? '',
                    'user' => $m[4] ?? '',
                    'ip' => $m[5] ?? ''
                ];
            }
        ],
        [
            'pattern' => '/Test message from ([^\s]+(?:\s[^\s]+)*)\s+from \(([^)]+)\)/',
            'extract' => function($m) {
                return [
                    'event' => 'test',
                    'user' => $m[1] ?? '',
                    'ip' => $m[2] ?? ''
                ];
            }
        ],
        [
            'pattern' => '/([^:]+):#011(.+)/',
            'extract' => function($m) {
                return [
                    'event' => 'system',
                    'user' => $m[1] ?? '',
                    'path' => $m[2] ?? ''
                ];
            }
        ]
    ];

    // Try each rule
    foreach ($rules as $rule) {
        if (preg_match($rule['pattern'], $message, $matches)) {
            $fields = $rule['extract']($matches);
            $fields['category'] = classify_severity($message);
            return array_merge($default_fields, $fields);
        }
    }

    // Fallback (uncategorized message)
    $default_fields['path'] = $message;
    $default_fields['category'] = classify_severity($message);
    return $default_fields;
}

function classify_severity($message) {
    $message = strtolower($message);

    if (
        strpos($message, 'failed to send email') !== false ||
        strpos($message, 'failed to update') !== false ||
        strpos($message, 'failed to get') !== false ||
        strpos($message, 'disk failure') !== false ||
        strpos($message, 'unrecoverable') !== false ||
        strpos($message, 'critical') !== false
    ) {
        return 'Critical';
    }

    if (
        strpos($message, 'improper shutdown') !== false ||
        strpos($message, 'error') !== false ||
        strpos($message, 'not responding') !== false ||
        strpos($message, 'overheating') !== false
    ) {
        return 'Warning';
    }

    if (
        strpos($message, 'shutdown') !== false ||
        strpos($message, 'filesystem scrubbing') !== false ||
        strpos($message, 'was stopped') !== false ||
        strpos($message, 'uninstalled') !== false ||
        strpos($message, 'abnormal login') !== false
    ) {
        return 'HighPriority';
    }

    return 'Information';
}
?>
