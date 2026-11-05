<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Install · Trinix Admission</title>
<style>
    :root { --bg: #f8fafc; --card: #fff; --text: #0f172a; --muted: #475569; --line: #e2e8f0; --brand: #1f5fa8; --ok: #15803d; --bad: #b91c1c; --warn: #b45309; }
    @media (prefers-color-scheme: dark) { :root { --bg: #0f172a; --card: #1e293b; --text: #f1f5f9; --muted: #94a3b8; --line: #334155; --brand: #60a5fa; --ok: #4ade80; --bad: #f87171; --warn: #fbbf24; } }
    * { box-sizing: border-box; }
    body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); }
    main { max-width: 720px; margin: 0 auto; padding: 16px; }
    h1 { font-size: 24px; margin: 8px 0 4px; } h2 { font-size: 18px; margin: 0 0 12px; }
    .muted { color: var(--muted); } .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; margin: 16px 0; }
    .steps { display: flex; flex-wrap: wrap; gap: 6px; padding: 0; margin: 12px 0; list-style: none; font-size: 14px; }
    .steps li { padding: 4px 10px; border-radius: 999px; border: 1px solid var(--line); color: var(--muted); }
    .steps li.on { border-color: var(--brand); color: var(--brand); font-weight: 600; }
    label { display: block; font-weight: 600; margin: 12px 0 4px; } .hint { font-size: 14px; color: var(--muted); margin: 2px 0 0; }
    input, select { width: 100%; min-height: 44px; padding: 8px 12px; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
    .row { display: grid; gap: 0 12px; grid-template-columns: 1fr; } @media (min-width: 560px) { .row { grid-template-columns: 1fr 1fr; } }
    .check { display: flex; gap: 10px; align-items: center; font-weight: 400; } .check input { width: 22px; min-height: 22px; }
    button { min-height: 44px; padding: 8px 18px; font: inherit; font-weight: 600; border-radius: 8px; border: 1px solid var(--brand); cursor: pointer; }
    .primary { background: var(--brand); color: #fff; } .secondary { background: transparent; color: var(--brand); }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; margin-top: 16px; }
    .alert { padding: 12px; border-radius: 8px; margin: 12px 0; border: 1px solid; } .alert.bad { color: var(--bad); border-color: var(--bad); } .alert.ok { color: var(--ok); border-color: var(--ok); }
    table { width: 100%; border-collapse: collapse; } td { padding: 8px 4px; border-bottom: 1px solid var(--line); vertical-align: top; overflow-wrap: anywhere; }
    td.hint { white-space: normal; min-width: 72px; }
    .ok { color: var(--ok); } .bad { color: var(--bad); } .warn { color: var(--warn); }
    pre { white-space: pre-wrap; font-size: 13px; max-height: 240px; overflow: auto; background: var(--bg); padding: 8px; border-radius: 8px; }
</style>
</head>
<body>
<main>
    <h1>Install Trinix Admission</h1>
    <p class="muted">Set up this server. Nothing is saved until the "Save settings" step. This page closes once the first Super Admin exists.</p>
    @php($names = ['check' => 'Check', 'database' => 'Database', 'site' => 'Site and email', 'services' => 'Storage and services', 'review' => 'Save', 'tables' => 'Tables', 'admin' => 'First admin', 'finish' => 'Finish'])
    <ol class="steps">@foreach ($names as $k => $n)<li class="{{ $k === $step ? 'on' : '' }}">{{ $loop->iteration }}. {{ $n }}</li>@endforeach</ol>

    @foreach ($problems as $p)<div class="alert bad" role="alert">{{ $p }}</div>@endforeach
    @if ($info)<div class="alert ok" role="status">{{ $info }}</div>@endif

    <form method="post" action="/install" class="card" autocomplete="off">
        {{-- Answers so far travel with the form (no session before the app is installed). --}}
        @foreach ($a as $k => $v)
            @if (! in_array($k, match ($step) {
                'database' => ['db_driver', 'db_host', 'db_port', 'db_database', 'db_username', 'db_password'],
                'site' => ['app_name', 'app_url', 'app_env', 'timezone', 'mail_mailer', 'mail_scheme', 'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name', 'mail_test_to'],
                'services' => ['storage', 's3_key', 's3_secret', 's3_region', 's3_bucket', 's3_endpoint', 'redis', 'redis_host', 'redis_port', 'redis_password', 'scanner', 'clamav_host', 'clamav_port'],
                default => [],
            }, true))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
        @endforeach

        @switch($step)
            @case('check')
                <h2>1. Server check</h2>
                <table>
                    @foreach ($checks as $c)
                        <tr><td class="{{ $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn') }}">{{ $c['ok'] ? '✓' : ($c['required'] ? '✕' : '!') }}</td>
                            <td>{{ $c['name'] }}@if ($c['note'])<br><span class="hint">{{ $c['note'] }}</span>@endif</td>
                            <td class="hint">{{ $c['required'] ? 'Required' : 'Can be added later' }}</td></tr>
                    @endforeach
                </table>
                @if (! $canContinue)<div class="alert bad">Fix the required items, then reload this page.</div>@endif
                <div class="actions"><button class="primary" name="action" value="database" @disabled(! $canContinue)>Continue</button></div>
                @break

            @case('database')
                <h2>2. Database</h2>
                <label for="db_driver">Database</label>
                <select id="db_driver" name="db_driver">
                    <option value="mysql" @selected($a['db_driver'] === 'mysql')>MySQL 8 (production)</option>
                    <option value="sqlite" @selected($a['db_driver'] === 'sqlite')>SQLite (trial only)</option>
                </select>
                <div class="row">
                    <div><label for="db_host">Host</label><input id="db_host" name="db_host" value="{{ $a['db_host'] }}"></div>
                    <div><label for="db_port">Port</label><input id="db_port" name="db_port" value="{{ $a['db_port'] }}" inputmode="numeric"></div>
                    <div><label for="db_database">Database name</label><input id="db_database" name="db_database" value="{{ $a['db_database'] }}"><p class="hint">For SQLite: a file path, or empty for the default.</p></div>
                    <div><label for="db_username">User</label><input id="db_username" name="db_username" value="{{ $a['db_username'] }}"></div>
                    <div><label for="db_password">Password</label><input id="db_password" type="password" name="db_password" value="{{ $a['db_password'] }}"></div>
                </div>
                <div class="actions"><button class="primary" name="action" value="test_database">Test and continue</button></div>
                @break

            @case('site')
                <h2>3. Site and email</h2>
                <div class="row">
                    <div><label for="app_name">Site name</label><input id="app_name" name="app_name" value="{{ $a['app_name'] }}"></div>
                    <div><label for="app_url">Site address</label><input id="app_url" name="app_url" value="{{ $a['app_url'] }}" placeholder="https://admissions.example.org"></div>
                    <div><label for="app_env">Kind of server</label>
                        <select id="app_env" name="app_env">@foreach (['production' => 'Production', 'staging' => 'Staging (testing)', 'local' => 'Local (development)'] as $v => $l)<option value="{{ $v }}" @selected($a['app_env'] === $v)>{{ $l }}</option>@endforeach</select></div>
                    <div><label for="timezone">Time zone</label><input id="timezone" name="timezone" value="{{ $a['timezone'] }}"></div>
                </div>
                <label for="mail_mailer">Email</label>
                <select id="mail_mailer" name="mail_mailer"><option value="smtp" @selected($a['mail_mailer'] === 'smtp')>Mail server (SMTP)</option><option value="log" @selected($a['mail_mailer'] === 'log')>Write emails to the log (testing only)</option></select>
                <div class="row">
                    <div><label for="mail_host">Mail server</label><input id="mail_host" name="mail_host" value="{{ $a['mail_host'] }}" placeholder="smtp.example.org"></div>
                    <div><label for="mail_port">Port</label><input id="mail_port" name="mail_port" value="{{ $a['mail_port'] }}" inputmode="numeric"></div>
                    <div><label for="mail_scheme">Security</label><select id="mail_scheme" name="mail_scheme"><option value="" @selected($a['mail_scheme'] === '')>Automatic (STARTTLS)</option><option value="smtps" @selected($a['mail_scheme'] === 'smtps')>SSL/TLS (port 465)</option></select></div>
                    <div><label for="mail_username">User</label><input id="mail_username" name="mail_username" value="{{ $a['mail_username'] }}"></div>
                    <div><label for="mail_password">Password</label><input id="mail_password" type="password" name="mail_password" value="{{ $a['mail_password'] }}"></div>
                    <div><label for="mail_from_address">Sender address</label><input id="mail_from_address" name="mail_from_address" value="{{ $a['mail_from_address'] }}" placeholder="admissions@example.org"></div>
                    <div><label for="mail_from_name">Sender name</label><input id="mail_from_name" name="mail_from_name" value="{{ $a['mail_from_name'] }}"></div>
                    <div><label for="mail_test_to">Send a test email to</label><input id="mail_test_to" name="mail_test_to" value="{{ $a['mail_test_to'] }}"></div>
                </div>
                <div class="actions"><button class="secondary" name="action" value="test_mail">Send test email</button><button class="primary" name="action" value="services">Continue</button></div>
                @break

            @case('services')
                <h2>4. Storage and services</h2>
                <label for="storage">Private files (applicants' documents)</label>
                <select id="storage" name="storage"><option value="local" @selected($a['storage'] === 'local')>A folder on this server</option><option value="s3" @selected($a['storage'] === 's3')>S3-compatible bucket (any provider)</option></select>
                <p class="hint">With two or more app servers, use a bucket or a shared folder, so every server sees the same files.</p>
                <div class="row">
                    <div><label for="s3_bucket">Bucket</label><input id="s3_bucket" name="s3_bucket" value="{{ $a['s3_bucket'] }}"></div>
                    <div><label for="s3_region">Region</label><input id="s3_region" name="s3_region" value="{{ $a['s3_region'] }}"></div>
                    <div><label for="s3_key">Access key</label><input id="s3_key" name="s3_key" value="{{ $a['s3_key'] }}"></div>
                    <div><label for="s3_secret">Secret key</label><input id="s3_secret" type="password" name="s3_secret" value="{{ $a['s3_secret'] }}"></div>
                    <div><label for="s3_endpoint">Endpoint (not AWS)</label><input id="s3_endpoint" name="s3_endpoint" value="{{ $a['s3_endpoint'] }}" placeholder="https://storage.example.org"></div>
                </div>
                <label class="check"><input type="checkbox" name="redis" value="1" @checked($a['redis'])> Use Redis for sessions, cache and queues (needed with two or more app servers)</label>
                <div class="row">
                    <div><label for="redis_host">Redis host</label><input id="redis_host" name="redis_host" value="{{ $a['redis_host'] }}"></div>
                    <div><label for="redis_port">Redis port</label><input id="redis_port" name="redis_port" value="{{ $a['redis_port'] }}"></div>
                    <div><label for="redis_password">Redis password</label><input id="redis_password" type="password" name="redis_password" value="{{ $a['redis_password'] }}"></div>
                </div>
                <label for="scanner">Virus scanning of documents</label>
                <select id="scanner" name="scanner"><option value="clamav" @selected($a['scanner'] === 'clamav')>ClamAV</option><option value="eicar" @selected($a['scanner'] === 'eicar')>Test mode (no real scanning; shown as a warning until changed)</option></select>
                <div class="row">
                    <div><label for="clamav_host">ClamAV host</label><input id="clamav_host" name="clamav_host" value="{{ $a['clamav_host'] }}"></div>
                    <div><label for="clamav_port">ClamAV port</label><input id="clamav_port" name="clamav_port" value="{{ $a['clamav_port'] }}"></div>
                </div>
                <div class="actions"><button class="secondary" name="action" value="site">Back</button><button class="primary" name="action" value="review">Continue</button></div>
                @break

            @case('review')
                <h2>5. Save settings</h2>
                <table>
                    <tr><td>Database</td><td>{{ $a['db_driver'] === 'mysql' ? "MySQL {$a['db_username']}@{$a['db_host']}:{$a['db_port']}/{$a['db_database']}" : 'SQLite' }}</td></tr>
                    <tr><td>Site</td><td>{{ $a['app_name'] }} · {{ $a['app_url'] }} · {{ $a['app_env'] }} · {{ $a['timezone'] }}</td></tr>
                    <tr><td>Email</td><td>{{ $a['mail_mailer'] === 'smtp' ? "{$a['mail_host']}:{$a['mail_port']}" : 'log only' }} · from {{ $a['mail_from_address'] }}</td></tr>
                    <tr><td>Files</td><td>{{ $a['storage'] === 's3' ? "Bucket {$a['s3_bucket']}" : 'Folder on this server' }}</td></tr>
                    <tr><td>Redis</td><td>{{ $a['redis'] ? "{$a['redis_host']}:{$a['redis_port']}" : 'Not used yet (database instead)' }}</td></tr>
                    <tr><td>Virus scanning</td><td>{{ $a['scanner'] === 'clamav' ? "ClamAV {$a['clamav_host']}:{$a['clamav_port']}" : 'Test mode' }}</td></tr>
                </table>
                <p class="hint">Writes the .env file. Other lines in it are kept, and an existing application key is never replaced.</p>
                <div class="actions"><button class="secondary" name="action" value="services">Back</button><button class="primary" name="action" value="save">Save settings</button></div>
                @break

            @case('tables')
                <h2>6. Create the tables</h2>
                <p>Creates the database tables and loads the roles, permissions and default settings.</p>
                <div class="actions"><button class="primary" name="action" value="tables">Create tables</button></div>
                @break

            @case('admin')
                <h2>7. First Super Admin</h2>
                @isset($output)<details><summary class="hint">What was done</summary><pre>{{ $output }}</pre></details>@endisset
                <label for="name">Name</label><input id="name" name="name" value="{{ $name ?? '' }}" autocomplete="name">
                <label for="email">Email</label><input id="email" name="email" value="{{ $email ?? '' }}" autocomplete="email">
                <div class="row">
                    <div><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="new-password"><p class="hint">At least 12 characters.</p></div>
                    <div><label for="password_confirmation">Repeat the password</label><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password"></div>
                </div>
                <p class="hint">Two-factor sign-in is set up at the first sign-in.</p>
                <div class="actions"><button class="primary" name="action" value="admin">Create the Super Admin</button></div>
                @break

            @case('finish')
                <h2>Installed</h2>
                <p>The installation is complete and this page is now closed. Sign in as the Super Admin and set up two-factor sign-in, then:</p>
                <ol>
                    <li>Organisation: institution, campuses (branches), courses and the branch–course grid.</li>
                    <li>Cycles: academic year, admission cycle, offerings with intake, Round 1.</li>
                    <li>People: invite staff, and <b>Import faculty</b> from Excel/CSV.</li>
                    <li>Sources: the admission portal, then import applicants (Imports).</li>
                    <li>Documents and checklists, eligibility rules.</li>
                    <li>Selection: stages and criteria (GD/PI marks), call lists, panels.</li>
                </ol>
                <h3>Scheduled tasks (required)</h3>
                <p>Add this cron job in the hosting control panel, to run <b>every minute</b>. It sends reminders, retries scans and, without Redis, also processes imports and emails:</p>
                <pre>* * * * * {{ PHP_BINARY ?: 'php' }} {{ base_path('artisan') }} schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
                <p class="hint">If the panel shows a different PHP path, use the PHP 8.3 command it lists. System → Health shows whether the scheduler is running.</p>
                <p class="hint">Later database updates: System → Maintenance (IT Administrator).</p>
                <div class="actions"><a href="/login"><button type="button" class="primary">Go to sign-in</button></a></div>
                @break
        @endswitch
    </form>
</main>
</body>
</html>
