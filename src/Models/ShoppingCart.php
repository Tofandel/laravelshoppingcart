<?php

namespace Gloudemans\Shoppingcart\Models;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShoppingCart extends Model
{
    public function getTable()
    {
        return config('cart.database.table', 'shopping_cart');
    }

    public $fillable = [
        'identifier',
        'instance',
        'content',
    ];

    protected static function booting(): void
    {
        self::addGlobalScope('instance', fn ($q) => $q->forInstance());
    }

    public function scopeForInstance(Builder $builder): void
    {
        $builder->where('instance', 'cart.'.Cart::currentInstance());
    }
}
