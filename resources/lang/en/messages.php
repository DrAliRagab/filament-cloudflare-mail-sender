<?php

declare(strict_types=1);

return [
    'navigation' => [
        'compose' => 'Compose Email',
        'messages' => 'Sent Emails',
    ],
    'compose' => [
        'sections' => [
            'delivery' => 'Sender',
            'recipients' => 'Recipients',
            'message' => 'Message',
            'attachments' => 'Attachments',
        ],
        'cc_bcc' => 'CC and BCC',
        'unique_recipients' => '{0} No recipients yet|{1} :count unique recipient|[2,*] :count unique recipients',
        'duplicate_recipient' => ':email is already in :kept and will be removed from :discarded.',
    ],
    'fields' => [
        'from_address' => 'From address',
        'from_name' => 'From name',
        'reply_to_address' => 'Reply-To address',
        'reply_to_name' => 'Reply-To name',
        'to' => 'To',
        'cc' => 'CC',
        'bcc' => 'BCC',
        'subject' => 'Subject',
        'body' => 'Body',
        'attachments' => 'Attachments',
        'status' => 'Status',
        'actor' => 'Sent by',
        'created_at' => 'Created',
        'queued_at' => 'Queued',
        'sent_at' => 'Accepted',
        'failed_at' => 'Failed',
        'cancelled_at' => 'Cancelled',
        'attempt_count' => 'Attempts',
        'failure_class' => 'Failure type',
        'failure_message' => 'Failure details',
    ],
    'placeholders' => [
        'from_address' => 'sender@example.com',
        'from_name' => 'Sender name',
        'reply_to_address' => 'replies@example.com',
        'reply_to_name' => 'Reply name',
        'recipient' => 'Add email address',
        'subject' => 'Email subject',
    ],
    'actions' => [
        'send' => 'Send email',
        'send_confirmation' => 'Queue this transactional email for sending through Cloudflare?',
        'duplicate' => 'Duplicate',
    ],
    'validation' => [
        'rate_limited' => 'You can send at most five emails per minute. Wait a moment and try again.',
        'reply_to_name_requires_address' => 'Add a Reply-To address when using a Reply-To name.',
    ],
    'notifications' => [
        'email_queued' => 'Email queued for sending',
        'email_not_queued' => 'Email could not be queued',
    ],
    'audit' => [
        'accepted_help' => 'Accepted by Cloudflare means Laravel received a successful provider response. It does not mean the email was delivered to the inbox.',
        'body_unavailable' => 'The message body is unavailable because body storage is disabled or this record was scrubbed.',
        'markdown_source' => 'Markdown source',
        'html' => 'HTML preview',
        'text' => 'Plain text',
        'no_attachments' => 'No attachments',
        'empty_value' => '—',
        'sections' => [
            'lifecycle' => 'Delivery status',
            'envelope' => 'Envelope',
            'body' => 'Message body',
            'attachments' => 'Attachments',
            'failure' => 'Failure details',
        ],
    ],
    'filters' => [
        'created_from' => 'Created from',
        'created_until' => 'Created until',
    ],
];
