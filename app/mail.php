<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';


function send_llama_mail(
    string $to,
    string $subject,
    string $message
): bool {

    $config = llama_config();

    $fromEmail =
        $config['mail']['from_email']
        ?? 'noreply@llamascout.com';

    $fromName =
        $config['mail']['from_name']
        ?? 'Llama Scout';


    $headers = [
        'From' =>
            $fromName . ' <' . $fromEmail . '>',

        'Reply-To' =>
            $fromEmail,

        'Content-Type' =>
            'text/plain; charset=UTF-8',

        'X-Mailer' =>
            'Llama Scout',
    ];


    return mail(
        $to,
        $subject,
        $message,
        $headers
    );
}


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


    $message =
        "Hi {$name},\n\n" .

        "Welcome to Llama Scout.\n\n" .

        "Verify your email address using the link below:\n\n" .

        "{$verificationUrl}\n\n" .

        "This verification link expires in 24 hours.\n\n" .

        "If you did not create a Llama Scout account, you can ignore this email.\n\n" .

        "Llama Scout\n" .
        "Know the place before you go.\n";


    return send_llama_mail(
        $user['email'],
        $subject,
        $message
    );
}
