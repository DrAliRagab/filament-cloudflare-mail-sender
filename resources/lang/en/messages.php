<?php

declare(strict_types=1);

return [
    'navigation' => [
        'compose' => 'Compose Email',
        'messages' => 'Sent Emails',
    ],
    'compose' => [
        'sections' => [
            'delivery' => 'Delivery',
            'recipients' => 'Recipients',
            'message' => 'Message',
            'attachments' => 'Attachments',
        ],
        'cc_bcc' => 'CC and BCC',
        'unique_recipients' => '{0} No valid recipients|{1} :count unique recipient|[2,*] :count unique recipients',
        'duplicate_recipient' => ':email is already in :kept and will be removed from :discarded.',
    ],
    'fields' => [
        'from_address' => 'From',
        'from_name' => 'From name',
        'reply_to_address' => 'Reply-To',
        'reply_to_name' => 'Reply-To name',
        'to' => 'To',
        'cc' => 'CC',
        'bcc' => 'BCC',
        'subject' => 'Subject',
        'body' => 'Message',
        'attachments' => 'Attachments',
        'status' => 'Status',
        'actor' => 'Actor',
        'created_at' => 'Created',
    ],
    'actions' => [
        'send' => 'Send email',
        'send_confirmation' => 'Send this transactional email to the queue?',
        'duplicate' => 'Duplicate',
    ],
    'validation' => [
        'rate_limited' => 'Five emails have already been sent in the last minute. Wait before trying again.',
        'reply_to_name_requires_address' => 'Enter a Reply-To address when using a Reply-To name.',
    ],
    'notifications' => [
        'email_queued' => 'Email queued',
        'email_not_queued' => 'Email could not be queued',
    ],
    'audit' => [
        'accepted_help' => 'Accepted by Cloudflare means Laravel received a successful provider response. It does not mean delivered.',
        'body_unavailable' => 'Body storage is disabled or this terminal record has been scrubbed.',
        'markdown_source' => 'Markdown source',
        'sections' => [
            'lifecycle' => 'Lifecycle',
            'envelope' => 'Envelope',
            'body' => 'Message body',
            'attachments' => 'Attachment ledger',
            'failure' => 'Failure details',
        ],
    ],
    'filters' => [
        'created_from' => 'Created from',
        'created_until' => 'Created until',
    ],
];
