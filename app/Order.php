<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Order extends Model
{
    //
    use SoftDeletes;
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $dates = [
        'deleted_at',
    ];
    public function order_product()
    {
        return $this->hasMany('App\OrderProduct');
    }
    public function user()
    {
        return $this->belongsTo('App\User');
    }

}
