<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductLocation extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = ['code', 'aisle', 'rack', 'bin', 'zone', 'notes'];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_location_assignments', 'location_id', 'product_id')
            ->withPivot('is_primary')->withTimestamps();
    }

    public function label(): string
    {
        return trim(implode('-', array_filter([$this->aisle, $this->rack, $this->bin]))) ?: $this->code;
    }
}
