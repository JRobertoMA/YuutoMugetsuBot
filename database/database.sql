-- Esquema COMPLETO de referencia (estado actual). No se ejecuta: la base se crea y actualiza con
-- php cli/migrate.php, que aplica database/migrations/*.sql en orden. Mantén este fichero al día al añadir migraciones.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(100) NOT NULL,
    applied_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Proyectos conectados. key_enc = clave HMAC compartida, cifrada con APP_KEY (App\Crypto).
CREATE TABLE IF NOT EXISTS projects (
    name            VARCHAR(32)  NOT NULL,
    url             VARCHAR(500) NOT NULL,
    key_enc         VARCHAR(255) NOT NULL,
    default_chat_id BIGINT       NULL,
    created_at      INT UNSIGNED NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué va a cada proyecto: comandos (/pendientes), prefijos de callback_data (rel, fulfill…) y prefijos de código
-- de vinculación (REL).
CREATE TABLE IF NOT EXISTS routes (
    kind        ENUM('command','callback','link') NOT NULL,
    value       VARCHAR(64)  NOT NULL,
    project     VARCHAR(32)  NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (kind, value),
    KEY idx_routes_project (project),
    CONSTRAINT fk_routes_project FOREIGN KEY (project) REFERENCES projects (name) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de chats: quién escribió al bot y en qué grupos/canales está. Sustituye a getUpdates para «Detectar chat».
CREATE TABLE IF NOT EXISTS chats (
    id         BIGINT       NOT NULL,
    type       VARCHAR(16)  NOT NULL,
    title      VARCHAR(255) NOT NULL,
    username   VARCHAR(64)  NULL,
    status     ENUM('active','left') NOT NULL DEFAULT 'active',
    first_seen INT UNSIGNED NOT NULL,
    last_seen  INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_chats_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- update_id ya procesados (Telegram reintenta el webhook si no recibe 200) y base del límite por usuario.
-- Se podan a los 7 días (cli/worker.php).
CREATE TABLE IF NOT EXISTS updates (
    update_id   BIGINT       NOT NULL,
    from_id     BIGINT       NULL,
    kind        VARCHAR(24)  NOT NULL,
    received_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (update_id),
    KEY idx_updates_from (from_id, received_at),
    KEY idx_updates_time (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Envíos pendientes: 429 de Telegram, fallo de red o envío programado (send_after). Los vacía cli/worker.php.
CREATE TABLE IF NOT EXISTS outbox (
    id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    project    VARCHAR(32)      NULL,
    method     VARCHAR(40)      NOT NULL,
    payload    JSON             NOT NULL,
    ref        VARCHAR(64)      NULL,
    attempts   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    send_after INT UNSIGNED     NOT NULL,
    last_error VARCHAR(500)     NULL,
    created_at INT UNSIGNED     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_outbox_due (send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mensajes enviados por cuenta de un proyecto: a qué ref (repo, cupón…) corresponde cada uno, para enrutar
-- reacciones y ediciones. Se podan a los 90 días.
CREATE TABLE IF NOT EXISTS sent_messages (
    chat_id    BIGINT       NOT NULL,
    message_id BIGINT       NOT NULL,
    project    VARCHAR(32)  NOT NULL,
    ref        VARCHAR(64)  NULL,
    created_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (chat_id, message_id),
    KEY idx_sent_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Errores y avisos del bot (fallos al reenviar a un proyecto, envíos descartados…). Se poda a los 30 días.
CREATE TABLE IF NOT EXISTS app_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level      VARCHAR(10)     NOT NULL,
    message    TEXT            NOT NULL,
    created_at INT UNSIGNED    NOT NULL,
    PRIMARY KEY (id),
    KEY idx_log_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
