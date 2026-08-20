<?php

namespace App\Models;

use App\Core\App;

class RecipientConsent extends Model
{
    protected string $table = 'recipient_consents';

    public static function listForInstance(int $instanceId, int $limit = 100): array
    {
        $stmt = App::$app->db->prepare("
            SELECT rc.*, u.name AS granted_by_name
            FROM recipient_consents rc
            LEFT JOIN users u ON u.id = rc.granted_by_user_id
            WHERE rc.instance_id = :instance_id
            ORDER BY rc.updated_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue('instance_id', $instanceId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', min(max($limit, 1), 500), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function status(int $instanceId, string $jid): ?string
    {
        $stmt = App::$app->db->prepare('SELECT status FROM recipient_consents WHERE instance_id = :instance_id AND jid = :jid LIMIT 1');
        $stmt->execute(['instance_id' => $instanceId, 'jid' => $jid]);
        $status = $stmt->fetchColumn();
        return $status ? (string) $status : null;
    }

    public static function grant(int $instanceId, string $jid, string $source, ?int $userId = null, ?string $note = null): void
    {
        $stmt = App::$app->db->prepare("
            INSERT INTO recipient_consents
                (instance_id, jid, status, source, note, granted_by_user_id, consented_at, revoked_at)
            VALUES
                (:instance_id, :jid, 'opted_in', :source, :note, :user_id, NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                status = 'opted_in', source = VALUES(source), note = VALUES(note),
                granted_by_user_id = VALUES(granted_by_user_id), consented_at = NOW(), revoked_at = NULL
        ");
        $stmt->execute([
            'instance_id' => $instanceId,
            'jid' => $jid,
            'source' => substr(trim($source), 0, 50) ?: 'manual',
            'note' => $note !== null ? substr(trim($note), 0, 500) : null,
            'user_id' => $userId,
        ]);
    }

    public static function revoke(int $instanceId, string $jid, string $source = 'manual', ?string $note = null): void
    {
        $pdo = App::$app->db->pdo;
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = App::$app->db->prepare("
                INSERT INTO recipient_consents
                    (instance_id, jid, status, source, note, consented_at, revoked_at)
                VALUES
                    (:instance_id, :jid, 'opted_out', :source, :note, NULL, NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'opted_out', source = VALUES(source), note = VALUES(note), revoked_at = NOW()
            ");
            $stmt->execute([
                'instance_id' => $instanceId,
                'jid' => $jid,
                'source' => substr(trim($source), 0, 50) ?: 'manual',
                'note' => $note !== null ? substr(trim($note), 0, 500) : null,
            ]);

            $cancel = App::$app->db->prepare("
                UPDATE send_queue sq
                JOIN messages m ON m.id = sq.message_id
                SET sq.status = 'cancelled', sq.processed_at = NOW(),
                    sq.error_message = 'Recipient opted out',
                    m.status = 'failed', m.error_message = 'Recipient opted out'
                WHERE sq.instance_id = :instance_id
                  AND sq.to_jid = :jid
                  AND sq.status = 'pending'
            ");
            $cancel->execute(['instance_id' => $instanceId, 'jid' => $jid]);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function processInboundChoice(int $instanceId, string $jid, string $text): ?string
    {
        $choice = self::normalizeChoice($text);
        $optOut = ['sair', 'stop', 'cancelar', 'pare', 'parar', 'remover', 'descadastrar', 'unsubscribe', 'nao quero receber', 'nao quero mais'];
        $optIn = ['sim', 'aceito', 'autorizo', 'iniciar', 'start', 'quero receber'];

        if (in_array($choice, $optOut, true)) {
            self::revoke($instanceId, $jid, 'inbound_keyword', 'Opt-out recebido pelo WhatsApp');
            return 'opted_out';
        }

        if (in_array($choice, $optIn, true)) {
            self::grant($instanceId, $jid, 'inbound_keyword', null, 'Opt-in recebido pelo WhatsApp');
            return 'opted_in';
        }

        return null;
    }

    private static function normalizeChoice(string $text): string
    {
        $text = strtr(trim($text), ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Õ'=>'O','Ô'=>'O','Ú'=>'U','Ç'=>'C','á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ç'=>'c']);
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9 ]+/', '', $text);
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
