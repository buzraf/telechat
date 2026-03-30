<?php
require_once __DIR__ . '/config.php';

class Mailer {

    public static function send(string $to, string $subject, string $html): bool {
        // If no SMTP configured, log to error_log and return true (dev mode)
        if (!SMTP_USER || !SMTP_PASS) {
            error_log("[TeleChat Mailer] To: $to | Subject: $subject");
            error_log("[TeleChat Mailer] Body: $html");
            return true;
        }

        $boundary = md5(time());
        $headers  = implode("\r\n", [
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
            "From: " . APP_NAME . " <" . SMTP_FROM . ">",
            "Reply-To: " . SMTP_FROM,
            "X-Mailer: TeleChat/1.0",
        ]);

        $body = "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "\r\n"
              . "--$boundary--";

        // Try SMTP via socket
        try {
            return self::sendSMTP($to, $subject, $html);
        } catch (Exception $e) {
            error_log("[TeleChat Mailer Error] " . $e->getMessage());
            // Fallback to mail()
            return mail($to, $subject, strip_tags($html), $headers);
        }
    }

    private static function sendSMTP(string $to, string $subject, string $html): bool {
        $socket = fsockopen(
            'tls://' . SMTP_HOST,
            (int) SMTP_PORT,
            $errno,
            $errstr,
            30
        );

        if (!$socket) throw new Exception("SMTP connect failed: $errstr ($errno)");

        stream_set_timeout($socket, 30);

        $read = fgets($socket, 512);
        if (!str_starts_with($read, '220')) throw new Exception("SMTP greeting failed");

        $commands = [
            "EHLO " . (gethostname() ?: 'localhost') . "\r\n" => '250',
            "AUTH LOGIN\r\n"                                    => '334',
            base64_encode(SMTP_USER) . "\r\n"                   => '334',
            base64_encode(SMTP_PASS) . "\r\n"                   => '235',
            "MAIL FROM:<" . SMTP_FROM . ">\r\n"                 => '250',
            "RCPT TO:<$to>\r\n"                                 => '250',
            "DATA\r\n"                                          => '354',
        ];

        foreach ($commands as $cmd => $expectedCode) {
            fwrite($socket, $cmd);
            $response = fgets($socket, 512);
            if (!str_starts_with(trim($response), $expectedCode)) {
                fwrite($socket, "QUIT\r\n");
                fclose($socket);
                throw new Exception("SMTP command failed. Expected $expectedCode, got: $response");
            }
        }

        // Send email body
        $message  = "From: " . APP_NAME . " <" . SMTP_FROM . ">\r\n";
        $message .= "To: $to\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($html)) . "\r\n";
        $message .= ".\r\n";

        fwrite($socket, $message);
        $response = fgets($socket, 512);

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return str_starts_with(trim($response), '250');
    }

    // Email templates
    public static function verificationEmail(string $name, string $token): string {
        $url = APP_URL . '/verify?token=' . $token;
        return self::template(
            "Подтверди email — " . APP_NAME,
            $name,
            "Добро пожаловать в <b>" . APP_NAME . "</b>! 🎉",
            "Нажми кнопку ниже чтобы подтвердить свой email адрес и начать общаться.",
            $url,
            "Подтвердить Email",
            "Ссылка действительна 24 часа. Если ты не регистрировался — просто проигнорируй это письмо."
        );
    }

    public static function passwordResetEmail(string $name, string $token): string {
        $url = APP_URL . '/reset-password?token=' . $token;
        return self::template(
            "Сброс пароля — " . APP_NAME,
            $name,
            "Сброс пароля",
            "Мы получили запрос на сброс пароля для твоего аккаунта.",
            $url,
            "Сбросить Пароль",
            "Ссылка действительна 1 час. Если ты не запрашивал сброс — просто проигнорируй это письмо."
        );
    }

    private static function template(
        string $subject,
        string $name,
        string $title,
        string $message,
        string $url,
        string $btnText,
        string $footer
    ): string {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>$subject</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 20px;">
    <tr>
      <td align="center">
        <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
          <!-- Header -->
          <tr>
            <td style="background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);padding:40px;text-align:center;">
              <div style="font-size:40px;margin-bottom:12px;">💬</div>
              <h1 style="margin:0;color:#ffffff;font-size:28px;font-weight:700;letter-spacing:-0.5px;">TeleChat</h1>
              <p style="margin:8px 0 0;color:rgba(255,255,255,0.7);font-size:14px;">Мессенджер нового поколения</p>
            </td>
          </tr>
          <!-- Body -->
          <tr>
            <td style="padding:40px;">
              <p style="margin:0 0 8px;color:#666;font-size:14px;">Привет, $name!</p>
              <h2 style="margin:0 0 20px;color:#1a1a2e;font-size:22px;font-weight:700;">$title</h2>
              <p style="margin:0 0 32px;color:#444;font-size:16px;line-height:1.6;">$message</p>
              <div style="text-align:center;margin:32px 0;">
                <a href="$url" style="display:inline-block;background:linear-gradient(135deg,#1a1a2e,#0f3460);color:#ffffff;text-decoration:none;padding:16px 40px;border-radius:50px;font-size:16px;font-weight:600;letter-spacing:0.3px;">$btnText</a>
              </div>
              <p style="margin:32px 0 0;color:#999;font-size:13px;line-height:1.5;border-top:1px solid #eee;padding-top:24px;">$footer</p>
            </td>
          </tr>
          <!-- Footer -->
          <tr>
            <td style="background:#f9f9f9;padding:24px;text-align:center;border-top:1px solid #eee;">
              <p style="margin:0;color:#bbb;font-size:12px;">© 2024 TeleChat. Все права защищены.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
