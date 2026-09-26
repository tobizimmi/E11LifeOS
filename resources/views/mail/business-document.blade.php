<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>{{ $mailSubject }}</title></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px;">
        <p style="margin:0 0 16px;font-size:18px;font-weight:600;">{{ $profile->company_name }}</p>
        <div style="font-size:15px;line-height:1.6;">{!! nl2br(e($body)) !!}</div>
        <p style="margin:24px 0;">
            <a href="{{ $portalUrl }}" style="display:inline-block;background:{{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $profile->accent_color) ? $profile->accent_color : '#4338ca' }};color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:14px;">{{ __('View document online') }}</a>
        </p>
        <p style="margin:0;font-size:12px;color:#6b7280;">{{ __('The document is attached as PDF.') }}</p>
    </div>
    <p style="max-width:560px;margin:12px auto 0;font-size:11px;color:#9ca3af;text-align:center;">{{ $profile->senderLine() }}</p>
</body>
</html>
