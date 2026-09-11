<?php

declare(strict_types=1);

return [
    'attachments.title' => 'Attachments',
    'attachments.help' => 'Up to {size} MiB per file. Allowed: {extensions}. Files are stored privately and can only be downloaded through TMS.',
    'attachments.choose' => 'Choose files',
    'attachments.upload' => 'Upload',
    'attachments.empty' => 'No attachments yet.',
    'attachments.download' => 'Download',
    'attachments.delete' => 'Delete',
    'attachments.delete_confirm' => 'Delete this attachment?',
    'validation.attachment_missing' => 'Choose at least one file to upload.',
    'validation.attachment_too_many' => 'No more than 10 files can be uploaded at once.',
    'validation.attachment_too_large' => 'The attachment exceeds the configured size limit.',
    'validation.attachment_empty' => 'Empty files cannot be attached.',
    'validation.attachment_extension' => 'This file extension is not allowed.',
    'validation.attachment_mime' => 'The file content does not match an allowed file type.',
    'validation.attachment_upload_failed' => 'The attachment could not be uploaded.',
    'validation.attachment_not_found' => 'Attachment not found.',
];
