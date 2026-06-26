@if (! empty($rows))
    <table style="width: 100%; margin: 18px 0; border-collapse: collapse;">
        @foreach ($rows as $label => $value)
            @if (filled($value))
                <tr>
                    <td style="width: 38%; padding: 10px 0; border-bottom: 1px solid #eef2f7; color: #64748b; font-size: 13px;">{{ $label }}</td>
                    <td style="padding: 10px 0; border-bottom: 1px solid #eef2f7; color: #0f172a; font-size: 14px; font-weight: 600;">{{ $value }}</td>
                </tr>
            @endif
        @endforeach
    </table>
@endif
