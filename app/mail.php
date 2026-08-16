<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;


/* =========================================================
   PHPMailer
   ========================================================= */

require_once dirname(__DIR__, 2) . '/private/phpmailer/Exception.php';
require_once dirname(__DIR__, 2) . '/private/phpmailer/PHPMailer.php';
require_once dirname(__DIR__, 2) . '/private/phpmailer/SMTP.php';


/* =========================================================
   MAIL CONFIG
   ========================================================= */

function llama_mail_config(): array
{
$path =
    dirname(__DIR__, 2) .
    '/private/mail.php';

    if (!is_file($path)) {
        throw new RuntimeException(
            'Llama Scout mail configuration is missing.'
        );
    }

    $config =
        require $path;

    if (!is_array($config)) {
        throw new RuntimeException(
            'Llama Scout mail configuration is invalid.'
        );
    }

    return $config;
}


/* =========================================================
   SEND MAIL
   ========================================================= */

function send_llama_mail(
    string $to,
    string $subject,
    string $message,
    ?string $html = null
): bool {

    $config =
        llama_mail_config();

    $mail =
        new PHPMailer(true);

    try {

        $mail->isSMTP();

        $mail->Host =
            $config['host'];

        $mail->Port =
            (int) $config['port'];

        $mail->SMTPAuth =
            true;

        $mail->Username =
            $config['username'];

        $mail->Password =
            $config['password'];

        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_STARTTLS;


        $mail->CharSet =
            'UTF-8';

        $mail->Encoding =
            'base64';


        $mail->setFrom(
            $config['from_email'],
            $config['from_name']
        );

        $mail->addReplyTo(
            $config['from_email'],
            $config['from_name']
        );

        $mail->addAddress(
            $to
        );


        $mail->Subject =
            $subject;


        if ($html !== null) {

            $mail->isHTML(true);

            $mail->Body =
                $html;

            $mail->AltBody =
                $message;

        } else {

            $mail->isHTML(false);

            $mail->Body =
                $message;
        }


        $mail->send();

        return true;


    } catch (Exception $error) {

        error_log(
            'Llama Scout mail error: ' .
            $mail->ErrorInfo
        );

        return false;
    }
}


/* =========================================================
   VERIFICATION EMAIL
   ========================================================= */

function send_verification_email(
    array $user,
    string $token
): bool {

    $verificationUrl =
        'https://account.llamascout.com/verify-email.php?token=' .
        urlencode($token);


    $name =
        $user['display_name']
        ?: $user['username']
        ?: 'there';


    $subject =
        'Verify your Llama Scout email';


    $textMessage =
        "Hi {$name},\n\n" .

        "Welcome to Llama Scout.\n\n" .

        "Verify your email address using the link below:\n\n" .

        "{$verificationUrl}\n\n" .

        "This verification link expires in 24 hours.\n\n" .

        "If you did not create a Llama Scout account, you can ignore this email.\n\n" .

        "Llama Scout\n" .
        "Know the place before you go.\n";


    $safeName =
        htmlspecialchars(
            $name,
            ENT_QUOTES,
            'UTF-8'
        );

    $safeUrl =
        htmlspecialchars(
            $verificationUrl,
            ENT_QUOTES,
            'UTF-8'
        );


    $htmlMessage = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
</head>

<body style="
  margin:0;
  padding:0;
  background:#f4efe6;
  font-family:Arial,Helvetica,sans-serif;
  color:#172822;
">

  <div style="
    max-width:600px;
    margin:0 auto;
    padding:40px 20px;
  ">

    <div style="
      background:#ffffff;
      border-radius:14px;
      padding:32px;
    ">

      <h1 style="
        margin:0 0 18px;
        font-size:28px;
      ">
        Welcome to Llama Scout
      </h1>

      <p>
        Hi {$safeName},
      </p>

      <p>
        Verify your email address to finish setting up your
        Llama Scout account.
      </p>

      <p style="
        margin:30px 0;
      ">

        <a
          href="{$safeUrl}"
          style="
            display:inline-block;
            background:#172822;
            color:#ffffff;
            padding:14px 22px;
            border-radius:8px;
            text-decoration:none;
            font-weight:bold;
          "
        >
          Verify Email
        </a>

      </p>

      <p style="
        color:#667069;
        font-size:14px;
        line-height:1.6;
      ">
        This verification link expires in 24 hours.
      </p>

      <p style="
        color:#667069;
        font-size:14px;
        line-height:1.6;
      ">
        If you did not create a Llama Scout account,
        you can ignore this email.
      </p>

      <hr style="
        border:0;
        border-top:1px solid #e4e4e0;
        margin:28px 0;
      ">

      <p style="
        margin:0;
        font-size:14px;
        color:#667069;
      ">
        Llama Scout<br>
        Know the place before you go.
      </p>

    </div>

  </div>

</body>
</html>
HTML;


    return send_llama_mail(
        $user['email'],
        $subject,
        $textMessage,
        $htmlMessage
    );
}
