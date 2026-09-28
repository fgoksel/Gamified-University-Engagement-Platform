<?php

use App\Mail\TestMail;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mail:test {email}', function (string $email) {
    if (Validator::make(['email' => $email], ['email' => 'email'])->fails()) {
        $this->error("Not a valid email address: {$email}");

        return 1;
    }

    $mailer = config('mail.default');
    $host = config("mail.mailers.{$mailer}.host");

    try {
        // Sent straight away (not queued) so connection problems show up here.
        Mail::to($email)->send(new TestMail);
    } catch (Throwable $e) {
        $this->error("The email could not be sent ({$mailer}".($host ? " via {$host}" : '').'): '.$e->getMessage());

        return 1;
    }

    $this->info("Test email sent to {$email} ({$mailer}".($host ? " via {$host}" : '').').');

    return 0;
})->purpose('Send a test email to check the mail settings');
