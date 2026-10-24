<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One farm setting. Read and write settings through App\Support\Settings, which caches them.
 *
 * @property int $id
 * @property string $key
 * @property string $value
 */
class Setting extends Model
{
    protected $guarded = ['id'];
}
