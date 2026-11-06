// Trinix Admission acceptance walk-through in a real browser.
// Needs a running app with the demo seed, mail written to the log, and the queue running synchronously:
//   BASE_URL=http://127.0.0.1:8000 LOG_FILE=storage/logs/laravel.log node tests/e2e/walkthrough.mjs
// Screenshots go to SHOTS_DIR (default storage/e2e).
import { chromium } from 'playwright';
import { authenticator } from 'otplib';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const LOG = process.env.LOG_FILE ?? 'storage/logs/laravel.log';
const SHOTS = process.env.SHOTS_DIR ?? 'storage/e2e';
// LIMITED=1: the server runs PHP without zip/dom/xmlreader/fileinfo (shared hosting); ZIP uploads are refused.
const LIMITED = process.env.LIMITED === '1';
const ADMIN = { email: process.env.DEMO_ADMIN_EMAIL ?? 'admin@example.test', password: process.env.DEMO_ADMIN_PASSWORD ?? 'change-me-please-123' };
mkdirSync(SHOTS, { recursive: true });

const step = (m) => console.log(`• ${m}`);
const today = new Date();
const year = today.getUTCFullYear() + 1;

async function shot(page, name) {
    await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
    // No sideways scrolling on any screen.
    const [scroll, view] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    if (scroll > view) throw new Error(`${name}: page is ${scroll}px wide on a ${view}px screen`);
}

// Field labels may end with a required marker (" *").
const label = (text) => new RegExp(`^${text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}( \\*)?$`);

async function expectText(page, text) {
    await page.getByText(text, { exact: false }).filter({ visible: true }).first().waitFor({ timeout: 10000 });
}

async function waitForCode(secret) {
    // Avoid reusing a code from the same 30-second window (Fortify refuses replays).
    const remaining = authenticator.timeRemaining();
    if (remaining < 5) await new Promise((r) => setTimeout(r, (remaining + 1) * 1000));
    return authenticator.generate(secret);
}

async function addViaModal(page, button, values, save = 'Save') {
    await page.getByRole('button', { name: button }).click();
    const dialog = page.getByRole('dialog');
    for (const [name, value] of Object.entries(values)) {
        const field = dialog.getByLabel(label(name));
        if ((await field.evaluate((el) => el.tagName)) === 'SELECT') await field.selectOption({ label: value });
        else await field.fill(String(value));
    }
    await dialog.getByRole('button', { name: save, exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
}

const browser = await chromium.launch();
try {
    const ctx = await browser.newContext({ viewport: { width: 360, height: 780 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.error('page error:', e.message));
    const watchResponses = (p, who) => p.on('response', (r) => {
        if (r.status() >= 500 || (r.status() === 401 && !r.url().endsWith('/api/v1/me')) || r.status() === 429) console.error(`  ${who}: ${r.status()} ${r.request().method()} ${r.url()}`);
    });
    watchResponses(page, 'admin');

    step('Super Admin signs in and is sent to set up two-factor');
    await page.goto(`${BASE}/login`);
    await shot(page, '01-login-mobile');
    await page.getByLabel('Email').fill(ADMIN.email);
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/account\?setup=two-factor/);
    await expectText(page, 'Your role needs two-factor sign-in');

    step('Sets up two-factor with an authenticator code');
    await page.getByRole('button', { name: 'Set up two-factor' }).click();
    const confirmPrompt = page.getByText('Confirm your password to continue.');
    await confirmPrompt.or(page.locator('code')).first().waitFor();
    if (await confirmPrompt.isVisible()) {
        await page.getByLabel(label('Password')).fill(ADMIN.password);
        await page.getByRole('button', { name: 'Confirm' }).click();
    }
    const secret = (await page.locator('code').first().textContent()).trim();
    await shot(page, '02-two-factor-setup');
    await page.getByLabel(label('Code')).fill(await waitForCode(secret));
    await page.getByRole('button', { name: 'Turn on' }).click();
    await expectText(page, 'Two-factor sign-in is on');
    await page.getByRole('button', { name: 'Done' }).click();

    step('Signs out and back in with password + code');
    await page.goto(`${BASE}/`);
    await page.getByRole('button', { name: 'Menu' }).click();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.waitForURL(/\/login/);
    await page.getByLabel('Email').fill(ADMIN.email);
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expectText(page, 'authenticator app');
    await new Promise((r) => setTimeout(r, (authenticator.timeRemaining() + 1) * 1000));
    await page.getByLabel('Code').fill(authenticator.generate(secret));
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.waitForURL(`${BASE}/`);
    await expectText(page, 'Hello, Demo Super Admin');
    await shot(page, '03-home-mobile');

    step('Adds a location, four branches and three courses');
    await page.goto(`${BASE}/organisation/locations`);
    await addViaModal(page, 'Add location', { Code: 'DEL', Name: 'Delhi campus', City: 'New Delhi' });
    await page.goto(`${BASE}/organisation/branches`);
    for (const [code, name] of [['BR1', 'Branch One'], ['BR2', 'Branch Two'], ['BR3', 'Branch Three'], ['BR4', 'Branch Four']]) {
        await addViaModal(page, 'Add branch', { Code: code, Name: name });
    }
    await expectText(page, 'Branch Four');
    await shot(page, '04-branches-mobile');
    await page.goto(`${BASE}/organisation/courses`);
    for (const [code, name, level, months, exam] of [
        ['MBA-IB', 'MBA (International Business)', 'PG', 24, 'CAT'],
        ['MBA-BA', 'MBA (Business Analytics)', 'PG', 24, 'CAT'],
        ['IPM', 'Integrated Programme in Management', 'Integrated', 60, 'IPMAT'],
    ]) {
        await addViaModal(page, 'Add course', { Code: code, Name: name, Level: level, 'Duration (months)': months, 'Entrance exam': exam });
    }

    step('Ticks the branch–course grid');
    await page.goto(`${BASE}/organisation/branch-courses`);
    for (const b of ['Branch One', 'Branch Two']) {
        for (const c of ['MBA (International Business)', 'MBA (Business Analytics)', 'Integrated Programme in Management']) {
            await page.getByLabel(`${b} offers ${c}`).check();
        }
    }
    await page.getByRole('button', { name: /Save/ }).click();
    await expectText(page, 'Saved.');
    await shot(page, '05-grid-mobile');

    step('Creates an academic year and a cycle with offerings');
    await page.goto(`${BASE}/cycles/academic-years`);
    await addViaModal(page, 'Add academic year', { Code: `${year}-${String(year + 1).slice(2)}`, 'Starts on': `${year}-01-01`, 'Ends on': `${year}-12-31` });
    await page.goto(`${BASE}/cycles`);
    await addViaModal(page, 'Add cycle', { 'Academic year': `${year}-${String(year + 1).slice(2)}`, Code: `ADM-${year}`, Name: `Admissions ${year}` });
    await page.getByRole('link', { name: `Admissions ${year}` }).click();
    for (const label of ['Branch One — MBA (International Business)', 'Branch Two — MBA (International Business)', 'Branch One — Integrated Programme in Management']) {
        await addViaModal(page, 'Add offering', { 'Branch and course': label, 'Sanctioned intake (seats)': 60 });
    }
    await page.getByRole('button', { name: 'Open cycle' }).click();
    await expectText(page, 'Cycle opened.');

    step('Creates Round 1 with branch-wise dates, activates, closes and reopens it');
    await page.getByRole('button', { name: 'Create first round' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Create' }).click();
    await page.waitForURL(/\/rounds\//);
    await page.getByLabel('Include Branch One MBA (International Business)').check();
    await page.getByLabel('Start date for Branch One MBA (International Business)').fill(`${year}-03-01`);
    await page.getByLabel('End date for Branch One MBA (International Business)').fill(`${year}-04-15`);
    await page.getByLabel('Include Branch Two MBA (International Business)').check();
    await page.getByLabel('Start date for Branch Two MBA (International Business)').fill(`${year}-03-10`);
    await page.getByLabel('End date for Branch Two MBA (International Business)').fill(`${year}-04-30`);
    await page.getByRole('button', { name: 'Save offerings and dates' }).click();
    await expectText(page, 'Offerings and dates saved.');
    await page.getByRole('button', { name: 'Activate' }).click();
    await expectText(page, 'Round activated.');
    await shot(page, '06-round-mobile');
    await page.getByRole('button', { name: 'Close round' }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByLabel('Reason').fill('Round 1 complete');
    await dialog.getByRole('checkbox').check();
    await dialog.getByRole('button', { name: 'Close round' }).click();
    await expectText(page, 'The round is read-only');
    await page.getByRole('button', { name: 'Reopen' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Reason').fill('Correction to dates');
    await dialog.getByRole('button', { name: 'Reopen' }).click();
    await expectText(page, 'Round reopened.');

    step('Invites an admission officer scoped to Branch One');
    await page.goto(`${BASE}/people/users`);
    await page.getByRole('button', { name: 'Invite user' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel(label('Name')).fill('Asha Officer');
    await dialog.getByLabel(label('Email')).fill('asha.officer@example.test');
    await dialog.getByLabel(label('Role')).selectOption({ label: 'Admission Cell Officer' });
    await dialog.getByLabel(label('Branch')).selectOption({ label: 'Branch One' });
    await shot(page, '07-invite-mobile');
    await dialog.getByRole('button', { name: 'Send invitation' }).click();
    await page.waitForURL(/\/people\/users\/.+/);
    await expectText(page, 'Branch One');

    step('Checks the audit log');
    await page.goto(`${BASE}/audit`);
    await expectText(page, 'round.closed');
    await expectText(page, 'round.reopened');
    await expectText(page, 'user.invited');
    await shot(page, '08-audit-mobile');

    step('The invited officer sets a password from the email link and signs in');
    const log = readFileSync(LOG, 'utf8');
    const links = [...log.matchAll(/(https?:\/\/[^\s"<>()]+\/set-password\?token=[^\s"<>()\]]+)/g)].map((m) => m[1].replace(/&amp;/g, '&'));
    const link = links.reverse().find((l) => l.includes('asha.officer'));
    if (!link) throw new Error('Invitation link not found in the mail log');
    const ctx2 = await browser.newContext({ viewport: { width: 360, height: 780 }, deviceScaleFactor: 2 });
    const page2 = await ctx2.newPage();
    watchResponses(page2, 'officer');
    await page2.goto(link.replace(/^https?:\/\/[^/]+/, BASE));
    await page2.getByLabel('New password').fill('officer-strong-password');
    await page2.getByLabel('Repeat the password').fill('officer-strong-password');
    await page2.getByRole('button', { name: 'Save password' }).click();
    await expectText(page2, 'Your password is set.');
    await page2.getByRole('link', { name: 'Sign in' }).click();
    await page2.getByLabel('Email').fill('asha.officer@example.test');
    await page2.getByLabel('Password').fill('officer-strong-password');
    await page2.getByRole('button', { name: 'Sign in' }).click();
    await expectText(page2, 'Hello, Asha Officer');
    await expectText(page2, 'Admission Cell Officer');
    await page2.goto(`${BASE}/organisation/branches`);
    await expectText(page2, 'Branch One');
    if (await page2.getByText('Branch Two').count()) throw new Error('Officer can see another branch');
    await shot(page2, '09-officer-branches-mobile');

    // ---------------- Phase 2: import and applicant master ----------------
    step('Phase 2: Super Admin adds the portal source and gives the officer an all-courses officer role');
    await page.goto(`${BASE}/sources`);
    await addViaModal(page, 'Add source', { Code: 'PORTAL', Name: 'Admission portal' });
    await expectText(page, 'Admission portal');
    await page.goto(`${BASE}/people/users`);
    await page.getByRole('link', { name: 'Asha Officer' }).click();
    await page.getByRole('button', { name: 'Add role' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel(label('Role')).selectOption({ label: 'Admission Cell Officer' });
    await dialog.getByRole('button', { name: 'Add role' }).click();
    await expectText(page, 'Role added.');

    step('The officer uploads a portal export for Round 1');
    const csv = `${SHOTS}/portal-round1.csv`;
    execFileSync('php', ['artisan', 'imports:make-sample', csv, '--rows=50', '--branches=BR1,BR2']);
    await page2.goto(`${BASE}/imports`);
    await page2.getByRole('button', { name: 'New import' }).click();
    dialog = page2.getByRole('dialog');
    await dialog.getByLabel(label('Round')).selectOption({ index: 0 });
    await dialog.locator('input[type=file]').setInputFiles(csv);
    await dialog.getByRole('button', { name: 'Upload' }).click();
    await page2.waitForURL(/\/imports\/.+/);
    await expectText(page2, 'Match the file');

    step('Matches columns, adds value translations, saves a template and checks the rows');
    const columns = {
        'Portal application number': 'Application No', 'Course': 'Programme', 'Full name': 'Candidate Name',
        'CAT/IPMAT application number': 'Entrance Reg No', 'Email': 'Email ID', 'Mobile': 'Mobile No',
        'Date of birth': 'DOB', 'Gender': 'Gender', 'Category': 'Category',
    };
    for (const [field, column] of Object.entries(columns)) await page2.getByLabel(label(field)).selectOption(column);
    await page2.getByLabel('Show optional fields too').check();
    const optional = {
        'Overall percentile': 'Percentile', 'PwBD (yes/no)': 'PwD', 'Class 10 marks': '10th %', 'Class 12 marks': '12th %',
        'Graduation marks': 'Graduation %', 'Graduation degree': 'Degree', 'Work experience (months)': 'Work Ex (Months)',
        'Campus preference 1': 'Campus Pref 1', 'Campus preference 2': 'Campus Pref 2',
    };
    for (const [field, column] of Object.entries(optional)) await page2.getByLabel(label(field)).selectOption(column);
    const translations = [['Course', 'MBA(IB)', 'MBA-IB'], ['Gender', 'M', 'Male'], ['Gender', 'F', 'Female'], ['Category', 'General', 'GEN'], ['Category', 'OBC', 'OBC-NCL']];
    for (const [field, from, to] of translations) {
        const block = page2.locator('div.mb-3', { has: page2.getByText(field, { exact: true }) }).last();
        await block.getByRole('button', { name: '+ Add translation' }).click();
        await block.getByPlaceholder('In the file').last().fill(from);
        await block.getByPlaceholder('In the system').last().fill(to);
    }
    await shot(page2, '11-mapping-mobile');
    await page2.getByRole('button', { name: 'Save as template' }).click();
    await page2.getByRole('dialog').getByLabel(label('Template name')).fill('Portal export (standard)');
    await page2.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
    await expectText(page2, 'Template “Portal export (standard)” saved.');
    await page2.getByRole('button', { name: 'Save and check rows' }).click();
    await expectText(page2, 'New (50)');
    await expectText(page2, 'someone else must approve');
    await shot(page2, '12-preview-mobile');

    step('The Super Admin approves; the batch writes and reconciles');
    const batchUrl = page2.url().replace(/^https?:\/\/[^/]+/, BASE);
    await page.goto(batchUrl);
    await page.getByRole('button', { name: 'Approve and write' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Approve and write' }).click();
    await expectText(page, 'Every row is accounted for.');
    await shot(page, '13-completed-mobile');

    step('Finds an applicant by CAT number and corrects the mobile number with a reason');
    await page.goto(`${BASE}/applications`);
    await page.getByLabel('Search').fill('CAT10000007');
    await page.getByRole('link', { name: /Sharma/ }).first().click();
    await expectText(page, 'PRTM0000007');
    await page.getByRole('button', { name: 'Correct a field' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel(label('Field')).selectOption({ label: 'Mobile' });
    await dialog.getByLabel(label('Correct value')).fill('9123456789');
    await dialog.getByLabel(label('Reason')).fill('Applicant called with a new number');
    await dialog.getByRole('button', { name: 'Save correction' }).click();
    await expectText(page, 'Corrected.');
    await expectText(page, 'Applicant called with a new number');
    await shot(page, '14-application-mobile');

    // ---------------- Phase 3: documents, scrutiny and eligibility ----------------
    step('Import templates: the Super Admin adds a column of their own and downloads the template with its instructions');
    await page.goto(`${BASE}/imports/templates`);
    await expectText(page, 'Columns in the template');
    await page.getByLabel(label('Heading')).fill('Hostel required');
    await page.getByLabel(label('Type')).selectOption('yesno');
    await page.getByRole('button', { name: 'Add column' }).click();
    await page.getByRole('button', { name: 'Save template' }).click();
    await expectText(page, 'Template saved');
    const [tpl] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: /Download template/ }).click()]);
    if (!tpl.suggestedFilename().endsWith(LIMITED ? '.csv' : '.xlsx')) throw new Error(`Template: ${tpl.suggestedFilename()}`);
    if (LIMITED && !(await import('node:fs')).readFileSync(await tpl.path(), 'utf8').includes('Hostel required')) throw new Error('Template lacks the new column');
    const ins = await page.request.get(`${BASE}/api/v1/import-layouts/students/instructions`, { headers: { Referer: `${BASE}/imports/templates` } });
    if (LIMITED ? !(await ins.text()).includes('Hostel required') : !(await ins.body()).subarray(0, 4).equals(Buffer.from('%PDF'))) throw new Error('Template instructions');
    await shot(page, '10b-import-template');

    step('Files (optional): Super Admin loads the suggested document types');
    await page.goto(`${BASE}/documents/types`);
    await page.getByRole('button', { name: 'Load the suggested types' }).click();
    await expectText(page, 'suggested document types added');

    step('The officer imports a ZIP of documents; the EICAR test file is caught');
    const docsZip = `${SHOTS}/documents.zip`;
    execFileSync('php', ['tests/e2e/make-docs-zip.php', docsZip]);
    await page2.goto(`${BASE}/imports`);
    await page2.getByRole('button', { name: 'Import documents' }).click();
    dialog = page2.getByRole('dialog');
    await dialog.getByLabel(label('Round')).selectOption({ index: 0 });
    await dialog.locator('input[type=file]').setInputFiles(docsZip);
    await dialog.getByRole('button', { name: 'Upload' }).click();
    if (LIMITED) {
        // A server without the zip extension says so clearly (limited mode); the walk-through goes on.
        await expectText(page2, 'This server cannot open ZIP files');
        await dialog.getByRole('button', { name: 'Cancel' }).click();
    } else {
        await page2.waitForURL(/\/imports\/.+/);
        await expectText(page2, 'New documents (7)');
        await page2.getByRole('tab', { name: /Errors/ }).click();
        await expectText(page2, 'Virus found');
        await shot(page2, '17-document-preview-mobile');
        await page.goto(page2.url().replace(/^https?:\/\/[^/]+/, BASE));
        await page.getByRole('button', { name: 'Approve and write' }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Approve and write' }).click();
        await expectText(page, 'Every row is accounted for.');
    }

    // ---------------- Interviews: faculty, panels, schedule, live marking ----------------
    const istDate = (days) => new Date(Date.now() + 5.5 * 3600e3 + days * 86400e3).toISOString().slice(0, 10);
    const panelDate = istDate(2);
    const mbaIb = 'MBA (International Business) (MBA-IB)';
    const pickCourse = (p) => p.getByLabel(label('Course')).first().selectOption({ label: mbaIb });

    step('Super Admin invites two faculty panel members; they set passwords');
    const faculty = {};
    for (const [name, email] of [['Ravi Faculty', 'ravi.faculty@example.test'], ['Meera Faculty', 'meera.faculty@example.test']]) {
        await page.goto(`${BASE}/people/users`);
        await page.getByRole('button', { name: 'Invite user' }).click();
        dialog = page.getByRole('dialog');
        await dialog.getByLabel(label('Name')).fill(name);
        await dialog.getByLabel(label('Email')).fill(email);
        await dialog.getByLabel(label('Role')).selectOption({ label: 'Faculty / Panel Member' });
        await dialog.getByRole('button', { name: 'Send invitation' }).click();
        await page.waitForURL(/\/people\/users\/.+/);
        const invite = [...readFileSync(LOG, 'utf8').matchAll(/(https?:\/\/[^\s"<>()]+\/set-password\?token=[^\s"<>()\]]+)/g)].map((m) => m[1].replace(/&amp;/g, '&')).reverse().find((l) => l.includes(email.split('@')[0]));
        const c = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
        const fp = await c.newPage();
        watchResponses(fp, name);
        await fp.goto(invite.replace(/^https?:\/\/[^/]+/, BASE));
        await fp.getByLabel('New password').fill('faculty-strong-password');
        await fp.getByLabel('Repeat the password').fill('faculty-strong-password');
        await fp.getByRole('button', { name: 'Save password' }).click();
        await expectText(fp, 'Your password is set.');
        await fp.getByRole('link', { name: 'Sign in' }).click();
        await fp.getByLabel('Email').fill(email);
        await fp.getByLabel('Password').fill('faculty-strong-password');
        await fp.getByRole('button', { name: 'Sign in' }).click();
        await expectText(fp, `Hello, ${name}`);
        faculty[name] = fp;
    }

    step('Stage A: Super Admin imports more faculty from a CSV file, also as Committee Members');
    const facCsv = `${SHOTS}/faculty.csv`;
    writeFileSync(facCsv, 'Name,Email ID,Mobile No,Designation,Department,Campus,Role\nAnil Kumar,anil.kumar@example.test,9000000101,Professor,Economics,BR1,Faculty\nBad Row,not-an-email,,,,BR1,Faculty\n');
    await page.goto(`${BASE}/people/users`);
    await page.getByRole('button', { name: 'Import faculty' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('File').setInputFiles(facCsv);
    await dialog.getByLabel('Also give the Committee Member role').check();
    await dialog.getByRole('button', { name: 'Check the file' }).click();
    await expectText(page, 'Email is missing or not valid.');
    await shot(page, '22a-faculty-import-mobile');
    await dialog.getByRole('button', { name: 'Confirm and send invitations' }).click();
    await expectText(page, '1 people added and invited');
    await dialog.getByRole('button', { name: 'Close' }).click();

    step('Sets up GD and PI with criteria; PI panels see the GD average');
    await page.goto(`${BASE}/selection/stages`);
    await pickCourse(page);
    await page.getByRole('button', { name: 'Start from the usual stages' }).click();
    await expectText(page, 'Starting stages added.');
    const stageCard = (code) => page.locator('section', { has: page.getByRole('heading', { name: new RegExp(`· ${code} `) }) });
    for (const [code, crit] of [['GD', [['Content', 10], ['Communication', 10]]], ['PI', [['Knowledge', 20], ['Clarity', 10]]]]) {
        const card = stageCard(code);
        for (const [i, [n, m]] of crit.entries()) {
            await card.getByRole('button', { name: '+ Criterion' }).click();
            await card.getByLabel(label(`Criterion ${i + 1}`)).fill(n);
            await card.getByLabel(label('Max marks')).nth(i).fill(String(m));
        }
        if (code === 'GD') await card.getByLabel(label('Remarks required')).selectOption('never');
        if (code === 'GD') await card.getByLabel(label("Stage result from panel members' totals")).fill('(SUM - MAX - MIN) / (COUNT - 2)');
        if (code === 'GD') await card.getByLabel(label('Members needed for this formula')).fill('3');
        if (code === 'PI') await card.getByLabel('Panel sees marks from earlier stages').check();
        await card.getByRole('button', { name: `Save ${code}` }).click();
        await expectText(page, `${code} saved.`);
    }
    await shot(page, '22-stage-settings-mobile');

    step('Creates a GD panel in Delhi and a PI panel, adds members and slots, fills the GD slot');
    const makePanel = async (stage, name, member, start, end, capacity) => {
        await page.goto(`${BASE}/panels`);
        await pickCourse(page);
        await page.getByRole('button', { name: 'New panel' }).click();
        dialog = page.getByRole('dialog');
        await dialog.getByLabel(label('Stage')).selectOption({ label: stage });
        await dialog.getByLabel(label('Panel name')).fill(name);
        await dialog.getByLabel(label('Date')).fill(panelDate);
        await dialog.getByLabel(label('Location')).selectOption({ index: 0 });
        await dialog.getByLabel(label('Room or hall')).fill('Seminar Hall A');
        await dialog.getByRole('button', { name: 'Create' }).click();
        await page.waitForURL(/\/panels\/.+/);
        await page.getByLabel(label('Find faculty or committee member')).fill(member.split(' ')[0]);
        await page.getByLabel(label('Role')).selectOption('chair');
        await page.getByRole('button', { name: 'Search' }).click();
        await page.getByRole('button', { name: 'Add', exact: true }).click();
        await expectText(page, `${member} added.`);
        await page.getByLabel(label('Start (IST)')).fill(start);
        await page.getByLabel(label('End (IST)')).fill(end);
        await page.getByLabel(label('Candidates')).fill(String(capacity));
        await page.getByRole('button', { name: 'Add slots' }).click();
        await expectText(page, 'Slots added.');
        return page.url();
    };
    const piUrl = await makePanel('PI · Personal Interview', 'PI Panel 1', 'Meera Faculty', '11:00', '11:20', 1);
    const gdUrl = await makePanel('GD · Group Discussion', 'GD Panel 1 · Delhi', 'Ravi Faculty', '10:00', '10:45', 1);
    step('Allocates candidates to GD panels automatically (preview, then confirm); the schedule is fixed, then emailed');
    await page.goto(`${BASE}/panels`);
    await pickCourse(page);
    await page.getByRole('button', { name: 'Allocate candidates automatically' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel(label('Stage')).selectOption({ label: 'GD · Group Discussion' });
    await dialog.getByRole('button', { name: 'Preview' }).click();
    await expectText(page, 'Nothing is saved until you confirm');
    await expectText(page, 'GD Panel 1 · Delhi');
    await shot(page, '24a-auto-allocate-mobile');
    await dialog.getByRole('button', { name: 'Confirm' }).click();
    await expectText(page, '1 candidates scheduled');
    await dialog.getByRole('button', { name: 'Close' }).click();
    await page.getByLabel(label('Stage')).first().selectOption({ label: 'GD · Group Discussion' });
    await page.getByRole('button', { name: 'Send schedule emails' }).click();
    await expectText(page, '1 candidates were emailed their schedule.');
    await page.goto(gdUrl);
    await shot(page, '24-panel-mobile');
    if (!/Your Group Discussion schedule/.test(readFileSync(LOG, 'utf8'))) throw new Error('Schedule email not found in the mail log');

    step('The GD panel member marks the candidate present, enters marks (autosaved) and submits; the admin locks all complete GD results');
    const ravi = faculty['Ravi Faculty'];
    await ravi.goto(`${BASE}/my-interviews`);
    await ravi.getByLabel('Interview date').selectOption(panelDate);
    await expectText(ravi, 'GD Panel 1 · Delhi');
    await ravi.getByRole('button', { name: 'Present' }).click();
    await ravi.getByRole('textbox', { name: /Content/ }).fill('7.5');
    await expectText(ravi, '✓ Saved');
    await ravi.reload(); // autosaved marks survive a reload mid-entry
    await ravi.getByRole('textbox', { name: /Content/ }).waitFor();
    if ((await ravi.getByRole('textbox', { name: /Content/ }).inputValue()) !== '7.5') throw new Error('Autosaved mark lost on reload');
    await ravi.getByRole('textbox', { name: /Communication/ }).fill('8');
    await expectText(ravi, '✓ Saved');
    await shot(ravi, '25-gd-marking-phone');
    await ravi.getByRole('button', { name: 'Submit marks for this group' }).click();
    await expectText(ravi, 'Marks submitted.');
    await page.goto(`${BASE}/panels`);
    await pickCourse(page);
    await page.getByLabel(label('Stage')).first().selectOption({ label: 'GD · Group Discussion' });
    await page.getByRole('button', { name: 'Lock all complete results' }).click();
    await expectText(page, '1 results locked');

    step('The admission office sends the candidate on to the PI panel');
    await page.goto(gdUrl);
    await page.getByRole('tab', { name: 'Results' }).click();
    await expectText(page, '15.50');
    await page.getByRole('checkbox', { name: /^Pick / }).check();
    await page.getByLabel(label('Panel')).selectOption({ index: 0 });
    await page.getByRole('button', { name: /Send 1 on/ }).click();
    await expectText(page, 'sent, 11:00 AM');
    await shot(page, '26-send-to-pi-mobile');

    step('The PI panel member sees the GD average and marks with the steppers on a phone');
    const meera = faculty['Meera Faculty'];
    await meera.goto(`${BASE}/my-interviews`);
    await meera.getByLabel('Interview date').selectOption(panelDate);
    await expectText(meera, 'PI Panel 1');
    await meera.getByRole('button', { name: 'Present' }).click();
    await expectText(meera, 'GD average:');
    await meera.getByRole('textbox', { name: /Knowledge/ }).fill('15');
    for (let i = 0; i < 3; i++) await meera.getByRole('button', { name: 'Knowledge plus 0.5' }).click();
    await meera.getByRole('textbox', { name: /Clarity/ }).fill('8');
    await meera.getByRole('textbox', { name: /Remarks/ }).fill('Clear reasoning on trade policy.');
    await expectText(meera, '✓ Saved');
    await shot(meera, '27-pi-marking-phone');
    await meera.getByRole('button', { name: /^Submit 24.5 \/ 30$/ }).click();
    await expectText(meera, 'Marks submitted.');
    await expectText(meera, '24.5');
    if (!piUrl) throw new Error('PI panel not created');

    // ---------------- Phase 5: merit ----------------
    step('Phase 5: Super Admin enters a merit formula (test values) and approves it');
    await page.goto(`${BASE}/merit/formula`);
    await pickCourse(page);
    await page.getByRole('button', { name: 'Start a formula' }).click();
    await expectText(page, 'New draft version created.');
    for (const [i, [name, reads, which, how, weight]] of [['CAT percentile', 'Entrance percentile', null, 'Scale to weight', '50'], ['GD', 'Selection stage result', 'GD · Group Discussion', 'As is × weight', '1']].entries()) {
        await page.getByRole('button', { name: '+ Add component' }).click();
        const comp = page.getByRole('region', { name: `Component ${i + 1}` });
        await comp.getByLabel(label(`Component ${i + 1}`)).fill(name);
        await comp.getByLabel(label('Reads')).selectOption({ label: reads });
        if (which) await comp.getByLabel(label('Which')).selectOption({ label: which });
        await comp.getByLabel(label('How')).selectOption({ label: how });
        await comp.getByLabel(/^Weight/).fill(weight);
    }
    await page.getByRole('button', { name: '+ Tie-break' }).click();
    await page.getByLabel(label('Tie-break 1')).selectOption({ label: 'Entrance Percentile' });
    await page.getByLabel(label('Source (IIFT policy or committee decision)')).fill('Walk-through: test values, not IIFT policy');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expectText(page, 'Draft saved.');
    await page.getByLabel(label('Application number')).fill('PRTM0000001');
    await page.getByRole('button', { name: 'Calculate' }).click();
    await expectText(page, 'Total ');
    await shot(page, '29-merit-formula-mobile');
    await page.getByRole('button', { name: 'Approve' }).click();
    await expectText(page, 'Approved. Merit lists now use this version.');

    step('The officer runs the merit list and checks a breakdown on a phone');
    await page2.goto(`${BASE}/merit/lists`);
    await pickCourse(page2);
    await page2.getByRole('button', { name: 'Run the formula' }).click();
    await expectText(page2, 'List made.');
    await page2.locator('table button.btn-link').first().click();
    await expectText(page2, 'Formula version 1');
    await shot(page2, '30-merit-breakdown-mobile');
    await page2.getByRole('dialog').getByRole('button', { name: /Close/ }).click().catch(() => page2.keyboard.press('Escape'));
    const [download] = await Promise.all([page2.waitForEvent('download'), page2.getByRole('link', { name: 'Excel' }).click()]);
    if (!download.suggestedFilename().endsWith(LIMITED ? '.csv' : '.xlsx')) throw new Error(`Excel export: ${download.suggestedFilename()}`);
    const pdf = await page2.request.get(`${BASE}${await page2.getByRole('link', { name: 'Notice PDF' }).getAttribute('href')}`, { headers: { Referer: `${BASE}/merit/lists` } });
    if (LIMITED ? !(await pdf.text()).includes('window.print()') : !(await pdf.body()).subarray(0, 4).equals(Buffer.from('%PDF'))) throw new Error('Notice PDF is not a PDF (or printable page)');

    step('The Super Admin approves and publishes the list (not the person who ran it)');
    await page.goto(`${BASE}/merit/lists`);
    await pickCourse(page);
    await page.getByRole('button', { name: 'Approve' }).click();
    await expectText(page, 'Approved.');
    await page.getByRole('button', { name: 'Publish' }).click();
    await expectText(page, 'Published.');
    await shot(page, '31-merit-list-mobile');

    step('People: a printed login sheet for a faculty member; at first sign-in he chooses his own password');
    await page.goto(`${BASE}/people/users`);
    await page.getByRole('button', { name: 'Login sheet' }).click();
    const sheetDialog = page.getByRole('dialog');
    await sheetDialog.getByRole('searchbox', { name: 'Search' }).fill('anil.kumar');
    await sheetDialog.getByRole('checkbox', { name: /^All 1 shown/ }).waitFor();
    await sheetDialog.getByRole('searchbox', { name: 'Search' }).fill('');
    await sheetDialog.getByRole('checkbox', { name: /^All \d+ shown/ }).uncheck();
    await sheetDialog.getByRole('checkbox', { name: /Anil Kumar/ }).check();
    await sheetDialog.getByRole('button', { name: 'Make passwords for 1' }).click();
    const tempPassword = (await sheetDialog.locator('code').first().textContent()).trim();
    if (!/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/.test(tempPassword)) throw new Error(`Odd temporary password: ${tempPassword}`);
    await shot(page, '30-login-sheet');
    await sheetDialog.getByRole('button', { name: 'Close' }).last().click();
    const anilCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
    const anil = await anilCtx.newPage();
    await anil.goto(`${BASE}/login`);
    await anil.getByLabel(label('Email')).fill('anil.kumar@example.test');
    await anil.getByLabel(label('Password')).fill(tempPassword);
    await anil.getByRole('button', { name: 'Sign in' }).click();
    await expectText(anil, 'Welcome, Anil Kumar');
    await anil.goto(`${BASE}/my-interviews`);
    await expectText(anil, 'Welcome, Anil Kumar'); // nothing else opens until the password is changed
    await anil.getByLabel(label('Password from the sheet')).fill(tempPassword);
    await anil.getByLabel(label('Your new password')).fill('anil-chooses-his-own');
    await anil.getByLabel(label('Repeat your new password')).fill('anil-chooses-his-own');
    await anil.getByRole('button', { name: 'Save and continue' }).click();
    await expectText(anil, 'Hello, Anil Kumar');

    step('System: the Super Admin sets the backup password and makes an encrypted backup');
    await page.goto(`${BASE}/system/backups`);
    await expectText(page, 'No password yet');
    await page.getByLabel(label('Backup password')).fill('a-long-backup-password');
    await page.getByLabel(label('Repeat it')).fill('a-long-backup-password');
    await page.getByRole('button', { name: 'Save password' }).click();
    await expectText(page, 'A password is set.');
    await page.getByRole('button', { name: 'Back up now' }).click();
    await expectText(page, 'Backup made');
    await expectText(page, 'Database');
    await shot(page, '31-backups');

    step('Stage map: the Super Admin adds a stage with components, sees the locks on GD, and deletes the new stage; reports open and export');
    await page.goto(`${BASE}/selection/stage-map`);
    await pickCourse(page);
    await expectText(page, 'Group Discussion');
    await page.getByRole('button', { name: 'Add a stage' }).click();
    const stageDialog = page.getByRole('dialog');
    await stageDialog.getByLabel(label('Code')).fill('ESSAY');
    await stageDialog.getByLabel(label('Name')).fill('Essay writing');
    await stageDialog.getByLabel(label('Component 1')).fill('Ideas');
    await stageDialog.getByRole('button', { name: 'Add stage' }).click();
    await expectText(page, 'ESSAY added.');
    await shot(page, '32-stage-map');
    await page.getByRole('article', { name: /^ESSAY/ }).getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click();
    await expectText(page, 'ESSAY deleted.');
    if (await page.getByRole('article', { name: /^GD/ }).getByRole('button', { name: 'Delete' }).isEnabled()) throw new Error('GD with marks could be deleted');
    await page.goto(`${BASE}/reports`);
    await pickCourse(page);
    await page.getByRole('tab', { name: 'Marks and results' }).click();
    await expectText(page, 'GD marks by member');
    await shot(page, '33-reports');

    step('Desktop layout');
    const desk = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2, storageState: await ctx.storageState() });
    const dp = await desk.newPage();
    await dp.goto(`${BASE}/cycles`);
    await expectText(dp, `Admissions ${year}`);
    await shot(dp, '10-cycles-desktop');

    console.log('\nTrinix Admission walk-through passed.');
} catch (e) {
    console.error('\nFAILED:', e.message);
    for (const c of browser.contexts()) for (const [i, p] of c.pages().entries()) await p.screenshot({ path: `${SHOTS}/failure-${browser.contexts().indexOf(c)}-${i}.png`, fullPage: true }).catch(() => {});
    process.exitCode = 1;
} finally {
    await browser.close();
}
