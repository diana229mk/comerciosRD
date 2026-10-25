<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductCategory extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        // business_id: trait
        'parent_id',
        'name',
        'slug',
        'description',
        'color',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function getFullNameAttribute(): string
    {
        if ($this->parent) {
            return $this->parent->name.' › '.$this->name;
        }

        return $this->name;
    }

    protected static function booted(): void
    {
        static::saving(function (ProductCategory $category): void {
            if (blank($category->slug) && filled($category->name)) {
                $base = Str::slug($category->name);
                $slug = $base;
                $i = 1;

                while (
                    static::query()
                        ->when($category->exists, fn ($q) => $q->whereKeyNot($category->getKey()))
                        ->where('slug', $slug)
                        ->exists()
                ) {
                    $slug = $base.'-'.$i++;
                }

                $category->slug = $slug;
            }
        });
    }
}
