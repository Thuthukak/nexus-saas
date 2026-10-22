<?php

/*
| Inbound reply mailbox (the catch-all for reply.<domain>). Credentials live in environment secrets, never in tenant data.
*/

return [
    'imap' => [
        'host' => env('INBOUND_IMAP_HOST'),
        'port' => (int) env('INBOUND_IMAP_PORT', 993),
        'encryption' => env('INBOUND_IMAP_ENCRYPTION', 'ssl'),   // ssl | starttls ; 'none' is refused
        'username' => env('INBOUND_IMAP_USERNAME'),
        'password' => env('INBOUND_IMAP_PASSWORD'),
        'inbox' => env('INBOUND_IMAP_INBOX', 'INBOX'),
        'processed_folder' => env('INBOUND_IMAP_PROCESSED', 'Processed'),
        'rejected_folder' => env('INBOUND_IMAP_REJECTED', 'Rejected'),
    ],
    'max_message_bytes' => (int) env('INBOUND_MAX_MESSAGE_BYTES', 1_000_000),
    'max_attempts' => 5,         // a message whose handling keeps failing is parked in the Rejected folder after this many tries
    'log_retention_days' => 60,
];
