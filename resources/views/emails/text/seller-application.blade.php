@php
    $isAdminAlert = $event === 'admin_alert';
    $link = rtrim($isAdminAlert ? config('app.admin_url') : config('app.frontend_url'), '/').($isAdminAlert ? '/' : '/become-seller');
@endphp
{{ $headline }}

{{ strip_tags($body) }}
@if ($event === 'more_info' && $application->review_notes)

WHAT WE NEED
{{ $application->review_notes }}
@endif
@if ($event === 'rejected' && $application->rejection_reason)

REASON
{{ $application->rejection_reason }}
@endif

Reference: {{ $application->reference }}
Shop: {{ $application->business_name }}
Sells: {{ $application->product_category }}
Location: {{ trim($application->city.', '.$application->region, ', ') }}
@if ($isAdminAlert)
Applicant: {{ $application->user?->name }} ({{ $application->user?->email }})
Phone: {{ $application->phone }}
@endif

{{ $isAdminAlert ? 'Review the application' : ($event === 'approved' ? 'Open your seller dashboard' : 'View your application') }}: {{ $link }}

The Antenkayume team
