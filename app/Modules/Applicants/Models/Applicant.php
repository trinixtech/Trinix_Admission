<?php

namespace App\Modules\Applicants\Models;

use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One person as known by one portal application number (decision D12). */
class Applicant extends Model
{
    use HasUid;

    public const FIELDS = [
        'full_name', 'date_of_birth', 'gender', 'email', 'mobile', 'alternate_mobile', 'nationality',
        'domicile_state', 'guardian_name', 'address', 'city', 'state', 'pincode',
    ];

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d'];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
