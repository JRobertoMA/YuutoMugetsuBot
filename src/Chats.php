<?php

declare(strict_types=1);

namespace App;

/**
 * Registro de chats: cada chat que escribe al bot o donde lo añaden (my_chat_member). Es lo que permite «Detectar
 * chat» en los proyectos sin getUpdates, que deja de funcionar en cuanto hay webhook.
 */
final class Chats
{
    /** Guarda o actualiza un chat a partir del objeto Chat de Telegram. */
    public static function record(array $chat, ?string $status = null): void
    {
        if (!isset($chat['id'])) {
            return;
        }
        $title = $chat['title']
            ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))
            ?: ($chat['username'] ?? (string) $chat['id']);
        $now = time();
        Database::run(
            'INSERT INTO chats (id, type, title, username, status, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE type = VALUES(type), title = VALUES(title), username = VALUES(username),
                                     status = ' . ($status === null ? 'status' : 'VALUES(status)') . ', last_seen = VALUES(last_seen)',
            [(int) $chat['id'], (string) ($chat['type'] ?? ''), mb_substr((string) $title, 0, 255),
             isset($chat['username']) ? mb_substr((string) $chat['username'], 0, 64) : null, $status ?? 'active', $now, $now]
        );
    }

    /**
     * Chats activos vistos en los últimos $days días, el más reciente primero.
     *
     * @return list<array{id:int, type:string, title:string, username:?string, last_seen:int}>
     */
    public static function recent(int $days = 30, int $limit = 50): array
    {
        $rows = Database::all(
            "SELECT id, type, title, username, last_seen FROM chats
             WHERE status = 'active' AND last_seen >= ? ORDER BY last_seen DESC LIMIT " . max(1, min(200, $limit)),
            [time() - $days * 86400]
        );
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'type' => $r['type'], 'title' => $r['title'],
            'username' => $r['username'], 'last_seen' => (int) $r['last_seen']], $rows);
    }
}
