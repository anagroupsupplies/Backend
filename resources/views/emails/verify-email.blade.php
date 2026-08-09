<x-email-layout title="Verify your email" preview="One quick tap and your Antenkayume account is ready.">
    <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#fff7df;color:#b45309;font-size:12px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;">Welcome to the shop ✨</div>
    <h1 style="margin:22px 0 12px;color:#1c1917;font-size:28px;line-height:35px;letter-spacing:-.7px;">Hi {{ $customer->name }}, let’s verify your email</h1>
    <p style="margin:0 0 16px;color:#57534e;font-size:16px;line-height:26px;">Your Antenkayume account is almost ready. Confirm that <strong style="color:#292524;">{{ $customer->email }}</strong> belongs to you so we can keep your shopping and checkout experience secure.</p>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:18px 0 24px;">
        <a href="{{ $url }}" style="display:inline-block;padding:15px 26px;border-radius:12px;background:#f59e0b;color:#ffffff;font-size:16px;font-weight:750;text-decoration:none;box-shadow:0 8px 20px rgba(245,158,11,.25);">Verify my email →</a>
    </td></tr></table>
    <div style="padding:16px 18px;border-radius:14px;background:#fafaf9;color:#78716c;font-size:13px;line-height:21px;">For your security, this link expires in <strong>60 minutes</strong>. If you did not create this account, you can safely ignore this message.</div>
    <p style="margin:25px 0 0;color:#57534e;font-size:15px;line-height:24px;">Happy shopping,<br><strong style="color:#b45309;">The Antenkayume team</strong></p>
    <p style="margin:24px 0 0;padding-top:18px;border-top:1px solid #f5e7d3;color:#a8a29e;font-size:11px;line-height:18px;word-break:break-all;">Button not working? Copy this link into your browser:<br><a href="{{ $url }}" style="color:#b45309;">{{ $url }}</a></p>
</x-email-layout>
