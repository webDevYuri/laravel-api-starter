<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }} · Reset your password</title>
</head>
<body style="margin:0; padding:0; background:#f3f2f1; color:#323130; font-family:'Segoe UI',Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center" style="padding:44px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:480px; background:#ffffff; border:1px solid #e4eaf1;">
                    <tr>
                        <td align="center" style="padding:38px 36px 30px; border-bottom:1px solid #eef2f6;">
                            <img src="{{ $message->embed(public_path('images/logo.svg')) }}" alt="{{ config('app.name') }}" width="62" style="display:block; width:62px; height:auto; margin:0 auto 16px;">
                            <p style="margin:0; color:#323130; font-size:17px; line-height:1.4; font-weight:600;">{{ config('app.name') }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:34px 36px 38px;">
                            <h1 style="margin:0 0 12px; color:#323130; font-size:24px; line-height:1.3; font-weight:600;">Reset your password</h1>
                            <p style="margin:0 0 26px; color:#605e5c; font-size:15px; line-height:1.6;">Click the button below to choose a new password.</p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="background:#0078d4;">
                                        <a href="{{ $resetUrl }}" style="display:inline-block; padding:12px 22px; color:#ffffff; font-size:15px; line-height:1.4; font-weight:600; text-decoration:none;">Reset password</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:22px 0 0; color:#605e5c; font-size:14px; line-height:1.5;">This link expires in {{ $expires }} minutes and can only be used once.</p>
                            <p style="margin:28px 0 0; color:#7a8795; font-size:13px; line-height:1.5;">Didn't request a password reset? You can ignore this email.</p>
                        </td>
                    </tr>
                </table>
                <p style="margin:20px 0 0; color:#8b98a6; font-size:12px; line-height:1.5;">{{ config('app.name') }} · Automated message</p>
            </td>
        </tr>
    </table>
</body>
</html>
