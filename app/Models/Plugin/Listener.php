<?php

namespace App\Models\Plugin;

use App\Plugin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Listener extends Model {
    protected $table = 'plugin_listeners';

    protected $fillable = [
        'plugin_id',
        'src',
        'order',
        'on',
    ];

    public function plugin(): BelongsTo {
        return $this->belongsTo(Plugin::class, 'plugin_id');
    }
}
