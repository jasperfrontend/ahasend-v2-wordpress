<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Mail;

use WP_Error;

final class Attachments
{
    /**
     * wp_mail()'s $attachments (file paths; string keys are custom file names) as AhaSend attachments.
     *
     * @return list<array<string, mixed>>|WP_Error
     */
    public static function fromWpMail(string|array $attachments): array|WP_Error
    {
        if (is_string($attachments)) {
            $attachments = $attachments === '' ? [] : explode("\n", str_replace("\r\n", "\n", $attachments));
        }

        $result = [];
        foreach ($attachments as $name => $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (!is_file($path) || !is_readable($path)) {
                return new WP_Error('xmd_attachment', sprintf('Attachment not readable: %s', basename($path)));
            }
            $data = file_get_contents($path);
            if ($data === false) {
                return new WP_Error('xmd_attachment', sprintf('Attachment not readable: %s', basename($path)));
            }
            $fileName = is_string($name) && $name !== '' ? $name : basename($path);
            $type     = wp_check_filetype($fileName)['type'] ?: 'application/octet-stream';

            $result[] = [
                'file_name'    => $fileName,
                'content_type' => $type,
                'data'         => base64_encode($data),
                'base64'       => true,
            ];
        }

        return $result;
    }
}
