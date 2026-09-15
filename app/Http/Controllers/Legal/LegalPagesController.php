<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * The public pages an app store asks for, served by the API host.
 *
 * This service has no `web` middleware group and ships no Blade views, so
 * the pages are plain HTML from here: no sessions, no assets, nothing to
 * break. Google Play requires a public URL describing how an account is
 * deleted and what happens to the data; that is the page below, and it
 * describes AccountDeletionController exactly -- if one changes, change the
 * other.
 */
final class LegalPagesController extends Controller
{
    public function accountDeletion(): Response
    {
        $support = (string) config('legal.support_email', '');
        $contact = $support !== ''
            ? '<p>If you no longer have the app, email <a href="mailto:'.e($support).'">'.e($support).'</a> from the address on your account and we will delete it for you within 7 days.</p>'
            : '';

        return $this->page('Delete your Komiut account', <<<HTML
<h1>Delete your Komiut account</h1>
<p>You can delete your Komiut passenger account yourself, from the app, at any time.</p>

<h2>How</h2>
<ol>
  <li>Open the Komiut app and sign in.</li>
  <li>Go to <strong>Settings</strong> &rarr; <strong>Delete account</strong>.</li>
  <li>Type the phone number on your account to confirm.</li>
</ol>
<p>Deletion is immediate. You are signed out on every device and cannot sign in again with that account.</p>
{$contact}

<h2>What is deleted</h2>
<ul>
  <li>Your name, phone number, email address, national ID number and date of birth</li>
  <li>Your profile photo</li>
  <li>Your Google or Apple sign-in link</li>
  <li>Every sign-in session and every device registered for notifications</li>
  <li>Any unspent loyalty points -- these are forfeited with the account</li>
</ul>

<h2>What is kept</h2>
<p>Records of bookings and fares you paid are kept by the SACCO you travelled with, because they are that operator's financial records. After deletion those records no longer carry your name, phone number or any other identifying detail -- only a reference to a deleted account.</p>

<h2>Driver and SACCO accounts</h2>
<p>Driver, conductor and SACCO office accounts are created and removed by the SACCO. If you hold one of these, ask your SACCO office to remove it.</p>
HTML);
    }

    private function page(string $title, string $body): Response
    {
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} · Komiut</title>
<style>
  body{margin:0;background:#fff;color:#1a1a1a;font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  main{max-width:640px;margin:0 auto;padding:48px 24px 72px}
  h1{font-size:28px;line-height:1.2;margin:0 0 20px}
  h2{font-size:18px;margin:32px 0 8px}
  p,li{margin:0 0 10px}
  ol,ul{padding-left:22px}
  a{color:#0a5c36}
  footer{margin-top:48px;padding-top:16px;border-top:1px solid #e5e5e5;color:#666;font-size:14px}
</style>
</head>
<body>
<main>
{$body}
<footer>Komiut &middot; Nairobi, Kenya</footer>
</main>
</body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
