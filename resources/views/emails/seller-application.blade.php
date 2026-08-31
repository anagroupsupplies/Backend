@php
    $isAdminAlert = $event === 'admin_alert';
    $link = rtrim($isAdminAlert ? config('app.admin_url') : config('app.frontend_url'), '/').($isAdminAlert ? '/' : '/become-seller');
    $accent = $event === 'approved' ? '#047857' : ($event === 'rejected' ? '#b91c1c' : '#b45309');
    $tint = $event === 'approved' ? '#ecfdf5' : ($event === 'rejected' ? '#fef2f2' : '#fff7df');
@endphp
<x-email-layout title="Seller application {{ $application->reference }}" preview="{{ $application->business_name }}">
    <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:{{ $tint }};color:{{ $accent }};font-size:12px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;">{{ $application->reference }}</div>
    <h1 style="margin:22px 0 12px;color:#1c1917;font-size:27px;line-height:34px;letter-spacing:-.6px;">{{ $headline }}</h1>
    <p style="margin:0 0 16px;color:#57534e;font-size:16px;line-height:26px;">{!! $body !!}</p>

    @if ($event === 'more_info' && $application->review_notes)
        <div style="margin:18px 0;padding:16px 18px;border-left:3px solid #f59e0b;background:#fffdf7;color:#292524;font-size:15px;line-height:24px;white-space:pre-line;">{{ $application->review_notes }}</div>
    @endif

    @if ($event === 'rejected' && $application->rejection_reason)
        <div style="margin:18px 0;padding:16px 18px;border-left:3px solid #b91c1c;background:#fef7f7;color:#292524;font-size:15px;line-height:24px;white-space:pre-line;">{{ $application->rejection_reason }}</div>
    @endif

    <div style="padding:16px 18px;border-radius:14px;background:#fafaf9;color:#57534e;font-size:14px;line-height:22px;">
        <strong style="color:#292524;">Shop</strong> {{ $application->business_name }}<br>
        <strong style="color:#292524;">Sells</strong> {{ $application->product_category }}<br>
        <strong style="color:#292524;">Location</strong> {{ trim($application->city.', '.$application->region, ', ') }}
        @if ($isAdminAlert)
            <br><strong style="color:#292524;">Applicant</strong> {{ $application->user?->name }} ({{ $application->user?->email }})<br>
            <strong style="color:#292524;">Phone</strong> {{ $application->phone }}
        @endif
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:20px 0 0;">
        <a href="{{ $link }}" style="display:inline-block;padding:14px 26px;border-radius:12px;background:{{ $accent }};color:#ffffff;font-size:15px;font-weight:750;text-decoration:none;">
            {{ $isAdminAlert ? 'Review the application →' : ($event === 'approved' ? 'Open my seller dashboard →' : 'View my application →') }}
        </a>
    </td></tr></table>

    <p style="margin:25px 0 0;color:#57534e;font-size:15px;line-height:24px;"><strong style="color:#b45309;">The Antenkayume team</strong></p>
</x-email-layout>
