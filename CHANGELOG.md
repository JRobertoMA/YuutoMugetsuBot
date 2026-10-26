# Changelog

Versiones publicadas (tags `vX.Y.Z`). En PROD se despliega siempre un tag.

## 0.1.0 — 2026-10-26

- **Webhook:**
  - comprueba el secreto de Telegram;
  - ignora los updates repetidos (`update_id`);
  - limita a 30 updates por minuto por usuario.
- **Enrutado:**
  - comandos por ruta;
  - botones por prefijo de `callback_data` (también los antiguos `fulfill:` y `decline:` de coupon-gift);
  - reacciones por el mensaje al que responden;
  - el bot resuelve `/start`, `/ayuda` y `/vincular <código>`.
- **Registro de chats**, también de los grupos y canales donde se añade o se expulsa al bot.
- **API firmada con HMAC para los proyectos:** `api/send.php` (envío, edición, botones de datos, URL y copiar
  texto), `api/chats.php`, `api/me.php`, `api/test.php`.
- **Seguridad de los botones:** un proyecto no puede crear botones que acaben en otro proyecto.
- **Bandeja de salida:** reintentos tras un 429 o un fallo de red, y envíos programados (`send_after`).
- **Consola:** `migrate`, `project`, `commands`, `webhook`, `chats`, `worker`, `poll` (long-polling para local).
