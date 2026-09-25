<?php

namespace App\Models;

use App\Contracts\BelongsToApartment;
use App\Models\Concerns\AuditsApartment;
use Database\Factories\LeaseTenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $lease_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 * @property-read string $full_name
 * @property-read Lease $lease
 */
#[Fillable(['first_name', 'last_name', 'email', 'phone', 'is_primary'])]
class LeaseTenant extends Model implements BelongsToApartment
{
    /** @use HasFactory<LeaseTenantFactory> */
    use AuditsApartment, HasFactory;

    protected function casts(): array
    {
        return [
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'is_primary' => 'boolean',
        ];
    }

    public function auditApartmentId(): ?int
    {
        return $this->lease->apartment_id;
    }

    protected function auditedAttributes(): array
    {
        return ['first_name', 'last_name', 'email', 'phone', 'is_primary'];
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }
}
