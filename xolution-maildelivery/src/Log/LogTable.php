<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Log;

use Xolution\MailDelivery\Api\SendResult;

/**
 * One row per send attempt. Metadata only: no body, no headers, so never a login link.
 */
final class LogTable
{
    public const RETENTION_DAYS = 7;
    public const PER_PAGE       = 50;

    private const DB_VERSION        = '1';
    private const DB_VERSION_OPTION = 'xmd_log_db_version';

    public function name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'xmd_log';
    }

    public function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = $this->name();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  email_type varchar(100) NOT NULL,
  recipient varchar(255) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL,
  http_status smallint(5) unsigned DEFAULT NULL,
  message_id varchar(64) DEFAULT NULL,
  error text DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY status (status)
) {$charset};");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Activation hooks don't run on updates, so the table is also checked on boot.
     */
    public function maybeUpgrade(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            $this->install();
        }
    }

    public function record(string $emailType, string $recipient, string $subject, SendResult $result, bool $sandbox): void
    {
        global $wpdb;

        $status = match (true) {
            !$result->ok => 'failed',
            $sandbox     => 'sandbox',
            default      => 'sent',
        };

        $wpdb->insert($this->name(), [
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'email_type'  => mb_substr($emailType, 0, 100),
            'recipient'   => mb_substr($recipient, 0, 255),
            'subject'     => mb_substr($subject, 0, 255),
            'status'      => $status,
            'http_status' => $result->httpStatus,
            'message_id'  => $result->messageId,
            'error'       => $result->error === '' ? null : mb_substr($result->error, 0, 2000),
        ]);
    }

    public function purge(): void
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->name()} WHERE created_at < %s", $cutoff));
    }

    /**
     * @return array{rows: list<object>, total: int}
     */
    public function page(int $page, string $status = '', string $emailType = ''): array
    {
        global $wpdb;

        $where = [];
        $args  = [];
        if ($status !== '') {
            $where[] = 'status = %s';
            $args[]  = $status;
        }
        if ($emailType !== '') {
            $where[] = 'email_type = %s';
            $args[]  = $emailType;
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $offset   = max(0, $page - 1) * self::PER_PAGE;

        $countSql = "SELECT COUNT(*) FROM {$this->name()} {$sqlWhere}";
        $rowsSql  = "SELECT * FROM {$this->name()} {$sqlWhere} ORDER BY id DESC LIMIT %d OFFSET %d";

        $total = (int) $wpdb->get_var($args === [] ? $countSql : $wpdb->prepare($countSql, ...$args));
        $rows  = $wpdb->get_results($wpdb->prepare($rowsSql, ...[...$args, self::PER_PAGE, $offset]));

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /** @return list<string> */
    public function emailTypes(): array
    {
        global $wpdb;

        return array_map('strval', (array) $wpdb->get_col("SELECT DISTINCT email_type FROM {$this->name()} ORDER BY email_type"));
    }

    public function drop(): void
    {
        global $wpdb;

        $wpdb->query("DROP TABLE IF EXISTS {$this->name()}");
        delete_option(self::DB_VERSION_OPTION);
    }
}
