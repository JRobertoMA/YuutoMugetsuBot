# Changelog

Versiones publicadas (tags `vX.Y.Z`). En PROD se despliega siempre un tag.

## 0.2.0 — 2026-11-02

- **`api/send_document.php`:** un proyecto envía un archivo (`sendDocument`), firmado como `api/send.php`.
  - Entrada: `filename` (se sanea a `[A-Za-z0-9._-]`, ≤ 64), `content_base64` (≤ 1 MB decodificado), `caption`
    (HTML, ≤ 1024), `buttons`, `silent`, `ref`, `chat`.
  - El archivo viaja en el payload como `_document`. `App\Telegram` lo envía en multipart desde memoria
    (`CURLStringFile`), sin escribirlo a disco.
  - Si Telegram limita o no responde, va a la bandeja de salida **con el archivo** y `cli/worker.php` lo reintenta
    igual que un mensaje.
- **`App\Http::postMultipart()`.**
- Primer uso: `g9-diagnosticos` (diagnósticos de G9 Control con el JSON adjunto).

## 0.1.1 — 2026-10-26

- **`cli/selftest.php <proyecto>`:** prueba en el servidor real que las cabeceras llegan a PHP. Usa la URL pública
  (`APP_URL`). Comprueba:
  - las firmas `X-Bot-*`: la buena, una alterada y una con la hora vieja;
  - el secreto del webhook: el bueno y uno incorrecto;
  - el `ping` firmado al proyecto.

  No envía nada a Telegram, no imprime la clave ni el secreto, y borra lo que anota.
- **Contrato:** nuevo evento `ping`. El proyecto responde `{"pong": true}`.

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
