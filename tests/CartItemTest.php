<?php

namespace Gloudemans\Tests\Shoppingcart;

use Orchestra\Testbench\TestCase;
use Gloudemans\Shoppingcart\CartItem;
use Gloudemans\Shoppingcart\ShoppingcartServiceProvider;

class CartItemTest extends TestCase
{
    /**
     * Set the package service provider.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [ShoppingcartServiceProvider::class];
    }

    /** @test */
    public function it_can_be_cast_to_an_array()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00, ['size' => 'XL', 'color' => 'red']);
        $cartItem->setQuantity(2);

        $this->assertEquals([
            'id' => 1,
            'name' => 'Some item',
            'price' => 10.00,
            'rowId' => $cartItem->rowId,
            'qty' => 2,
            'options' => [
                'size' => 'XL',
                'color' => 'red'
            ],
            'taxRate' => 21,
            'tax' => 2.10,
            'subtotal' => 20.00,
            'class' => null,
        ], $cartItem->toArray());
    }

    /** @test */
    public function it_can_be_cast_to_json()
    {
        $cartItem = new CartItem(1, 'Some item', 10.00, ['size' => 'XL', 'color' => 'red']);
        $cartItem->setQuantity(2);

        $this->assertJson($cartItem->toJson());

        $json = '{"id":1,"name":"Some item","qty":2,"price":10,"options":{"size":"XL","color":"red"},"taxRate":21,"class":null,"rowId":"fe3938f7be1a3d7cc10d9fdaef74a729","tax":2.1,"subtotal":20}';

        $this->assertEquals($json, $cartItem->toJson());
    }
}