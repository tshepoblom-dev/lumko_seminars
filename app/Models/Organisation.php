<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organisation extends Model
{
    protected $fillable = ['name', 'normalized_name'];

    protected static function booted(): void
    {
        static::saving(function (self $organisation) {
            if ($organisation->isDirty('name')) {
                $organisation->normalized_name = strtolower(trim(preg_replace('/\s+/', ' ', $organisation->name)));
            }
        });
    }

    /**
     * D-Registration — case/whitespace-insensitive find-or-create, so
     * "ABC Municipality" and "abc municipality " land on the same row.
     */
    public static function findOrCreateByName(string $name): self
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $name)));

        return static::firstOrCreate(
            ['normalized_name' => $normalized],
            ['name' => trim($name)]
        );
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
