<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Merit list</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; }
    h1 { font-size: 14pt; margin: 0 0 4px; }
    p { margin: 0 0 10px; color: #444; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #999; padding: 3px 5px; text-align: left; }
    th { background: #eee; }
    td.n { text-align: right; }
</style>
</head>
<body>
<h1>{{ $list->course->name }}: merit list, {{ $list->round->name }}</h1>
<p>Version {{ $list->version }} · formula version {{ $list->formula->version }} · published {{ $list->published_at?->setTimezone(config('app.display_timezone'))->format('j M Y') ?? 'not yet (draft)' }}</p>
<table>
    <thead><tr><th>Rank</th><th>Application number</th><th>Name</th><th>Category</th><th>Category rank</th><th>PwBD rank</th><th>Total</th></tr></thead>
    <tbody>
    @foreach ($entries as $e)
        <tr>
            <td class="n">{{ $e->overall_rank }}{{ $e->tie_shared ? '=' : '' }}</td>
            <td>{{ $e->application->portal_application_no }}</td>
            <td>{{ $e->application->applicant->full_name }}</td>
            <td>{{ $e->application->category }}</td>
            <td class="n">{{ $e->category_rank }}</td>
            <td class="n">{{ $e->pwbd_rank ?? '' }}</td>
            <td class="n">{{ number_format((float) $e->total, $list->formula->rounding['total'] ?? 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
