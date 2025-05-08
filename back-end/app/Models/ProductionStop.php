<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_date',
        'to_date',
        'mo_key',
        'ws_key',
        'stop_t',
        'wo_key',
        'wo_name',
        'code1_key',
        'code2_key',
        'code3_key',
        'machine_name',
        'stop_duration',
        'machine_group'
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'stop_duration' => 'float',
    ];
}