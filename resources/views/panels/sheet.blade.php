<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Attendance sheet</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; }
    h1 { font-size: 14pt; margin: 0 0 4px; }
    h2 { font-size: 11pt; margin: 14px 0 4px; }
    p { margin: 0 0 6px; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #888; padding: 6px 5px; text-align: left; vertical-align: top; }
    th { background: #eee; }
    .box { display: inline-block; width: 11px; height: 11px; border: 1px solid #333; margin-right: 3px; }
    .sign { width: 26%; }
    .foot { margin-top: 18px; }
    .foot td { border: 0; padding: 18px 5px 0; }
</style>
</head>
<body>
<h1>{{ $panel->stage->code }} · {{ $panel->name }}: attendance sheet</h1>
<p>{{ $panel->stage->name }} · {{ $panel->round->name }} · {{ $panel->panel_date->format('l, j F Y') }} ·
    @if ($panel->mode === 'online') Online @else {{ collect([$panel->venue, $panel->location?->name, $panel->location?->city])->filter()->implode(', ') }} @endif</p>
<p>Panel: {{ $members->map(fn ($m) => $m->user->name.($m->role === 'chair' ? ' (chair)' : ''))->implode(', ') ?: 'no members yet' }}</p>
@foreach ($slots as $slot)
    <h2>{{ $slot->starts_at->setTimezone($tz)->format('g:i A') }}–{{ $slot->ends_at->setTimezone($tz)->format('g:i A') }}</h2>
    <table>
        <thead><tr><th style="width:4%">#</th><th style="width:18%">Application number</th><th>Name</th><th style="width:20%">Present / Absent</th><th class="sign">Candidate's signature</th></tr></thead>
        <tbody>
        @forelse ($slot->candidates as $i => $c)
            <tr><td>{{ $i + 1 }}</td><td>{{ $c->application->portal_application_no }}</td><td>{{ $c->application->applicant->full_name }}</td>
                <td><span class="box"></span> Present &nbsp; <span class="box"></span> Absent</td><td></td></tr>
        @empty
            <tr><td colspan="5">No candidates booked.</td></tr>
        @endforelse
        </tbody>
    </table>
@endforeach
<table class="foot"><tr><td>Chair's signature: ____________________</td><td>Date and time: ____________________</td></tr></table>
<p style="margin-top:12px;font-size:8pt;color:#666">Printed {{ now($tz)->format('j M Y, g:i A') }} from Trinix Admission. Contains personal data: keep safe and destroy after use.</p>
</body>
</html>
