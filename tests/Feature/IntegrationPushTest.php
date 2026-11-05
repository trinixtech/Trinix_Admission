<?php

use App\Modules\Applicants\Models\Application;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Imports\Models\ImportBatch;

beforeEach(function () {
    $this->w = world();
    $this->round = AdmissionRound::create(['admission_cycle_id' => $this->w->cycle->id, 'number' => 1, 'type' => 'initial', 'name' => 'Round 1', 'status' => 'active']);
    $this->source = ExternalSource::create(['code' => 'PORTAL', 'name' => 'Admission portal', 'status' => 'active']);
    $this->super = userWithRole('super_admin');
    $this->token = $this->actingAs($this->super)->postJson("/api/v1/sources/{$this->source->uid}/tokens", ['name' => 'Portal push'])
        ->assertCreated()->json('data.token');
    signOut();
});

function record(int $i, array $override = []): array
{
    return $override + [
        'portal_application_no' => sprintf('API%05d', $i), 'course' => 'MBA-IB', 'full_name' => "Api Person $i",
        'entrance_application_no' => sprintf('CAT5%07d', $i), 'entrance_percentile' => 88.5, 'email' => "api$i@example.test",
        'mobile' => '98'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'date_of_birth' => '2000-01-15', 'gender' => 'Male',
        'category' => 'GEN', 'class10_marks' => 80, 'class12_marks' => 82, 'graduation_marks' => 70, 'preference_1' => 'BR-A',
        'sectional.VARC' => 92.1,
    ];
}

function push(object $t, array $body, ?string $token = null)
{
    return $t->withToken($token ?? $t->token)->postJson('/api/v1/integration/import-batches', $body);
}

it('accepts a push, stages it for preview, and writes only after approval', function () {
    $res = push($this, ['round' => $this->round->uid, 'idempotency_key' => 'push-001', 'records' => [record(1), record(2), record(3, ['email' => 'bad'])]])
        ->assertStatus(202);
    $uid = $res->json('data.batch');
    $batch = ImportBatch::where('uid', $uid)->first();
    expect($batch->channel)->toBe('api')->and($batch->status)->toBe('validated')
        ->and($batch->rows_new)->toBe(2)->and($batch->rows_error)->toBe(1)->and($batch->uploaded_by)->toBeNull()
        ->and(Application::count())->toBe(0);

    $this->actingAs($this->super)->postJson("/api/v1/import-batches/{$uid}/approve")->assertStatus(202);
    $app = Application::with('entrance')->where('portal_application_no', 'API00001')->first();
    expect(Application::count())->toBe(2)->and($app->entrance->sectional)->toBe(['VARC' => '92.1']);

    signOut();
    $this->withToken($this->token)->getJson("/api/v1/integration/import-batches/{$uid}")->assertOk()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.written', 2);
});

it('returns the first batch when the same idempotency key is pushed again', function () {
    $first = push($this, ['round' => $this->round->uid, 'idempotency_key' => 'same', 'records' => [record(1)]])->assertStatus(202)->json('data.batch');
    push($this, ['round' => $this->round->uid, 'idempotency_key' => 'same', 'records' => [record(1), record(2)]])
        ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.batch', $first);
    expect(ImportBatch::count())->toBe(1);
});

it('refuses missing, wrong and revoked tokens', function () {
    $this->postJson('/api/v1/integration/import-batches', [])->assertUnauthorized();
    push($this, ['round' => $this->round->uid, 'idempotency_key' => 'k', 'records' => [record(1)]], 'nonsense|token')->assertUnauthorized();

    $id = (int) explode('|', $this->token)[0];
    $this->actingAs($this->super)->postJson("/api/v1/source-tokens/{$id}/revoke")->assertOk();
    signOut();
    push($this, ['round' => $this->round->uid, 'idempotency_key' => 'k', 'records' => [record(1)]])->assertUnauthorized();
});

it('refuses pushes from an inactive source', function () {
    $this->source->update(['status' => 'inactive']);
    push($this, ['round' => $this->round->uid, 'idempotency_key' => 'k', 'records' => [record(1)]])->assertUnauthorized();
});

it('limits a push to 1,000 records', function () {
    push($this, ['round' => $this->round->uid, 'idempotency_key' => 'big', 'records' => array_map(fn ($i) => record($i), range(1, 1001))])
        ->assertStatus(422);
});

it('does not let a user session use the integration endpoint, or a source token use staff endpoints', function () {
    $this->actingAs($this->super)->postJson('/api/v1/integration/import-batches', ['round' => $this->round->uid])->assertUnauthorized();
    signOut();
    $this->withToken($this->token)->getJson('/api/v1/applications')->assertUnauthorized();
});

it('shows a source token only once and lets only the super admin manage sources', function () {
    $this->actingAs($this->super)->getJson("/api/v1/sources/{$this->source->uid}/tokens")->assertOk()
        ->assertJsonMissingPath('data.0.token')->assertJsonPath('data.0.name', 'Portal push');
    $central = userWithRole('central_admin');
    $this->actingAs($central)->postJson("/api/v1/sources/{$this->source->uid}/tokens", ['name' => 'x'])->assertForbidden();
    $this->actingAs($central)->getJson('/api/v1/sources')->assertOk();
});
