@props(['title', 'preview' => 'A message from Antenkayume Shop'])
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#fff8ed;color:#292524;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;-webkit-text-size-adjust:100%;">
<span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;">{{ $preview }}</span>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#fff8ed;">
<tr><td align="center" style="padding:32px 14px;">
    <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;">
        <tr><td align="center" style="padding:0 0 22px;">
            <a href="{{ config('app.frontend_url') }}" style="display:inline-block;text-decoration:none;color:#1c1917;">
                <span style="display:inline-block;vertical-align:middle;width:44px;height:44px;line-height:44px;border-radius:14px;background:#f59e0b;color:#ffffff;font-size:22px;font-weight:800;text-align:center;box-shadow:0 8px 20px rgba(245,158,11,.28);">A</span>
                <span style="display:inline-block;vertical-align:middle;margin-left:10px;text-align:left;"><strong style="display:block;font-size:20px;line-height:22px;letter-spacing:-.4px;">Antenkayume</strong><small style="display:block;color:#a16207;font-size:11px;line-height:16px;letter-spacing:1.2px;text-transform:uppercase;">Shop with joy</small></span>
            </a>
        </td></tr>
        <tr><td style="overflow:hidden;border:1px solid #fde7bf;border-radius:24px;background:#ffffff;box-shadow:0 18px 45px rgba(120,53,15,.10);">
            <div style="height:6px;background:linear-gradient(90deg,#f59e0b,#fb923c,#f97316);font-size:0;line-height:0;">&nbsp;</div>
            <div style="padding:42px 44px 36px;">{{ $slot }}</div>
        </td></tr>
        <tr><td align="center" style="padding:24px 20px 0;color:#a8a29e;font-size:12px;line-height:19px;">
            <p style="margin:0 0 5px;">Need help? Reply to this email or contact <a href="mailto:{{ config('mail.from.address') }}" style="color:#b45309;text-decoration:none;">{{ config('mail.from.address') }}</a></p>
            <p style="margin:0;">© {{ date('Y') }} Antenkayume Shop. Made for a happier shopping experience.</p>
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
