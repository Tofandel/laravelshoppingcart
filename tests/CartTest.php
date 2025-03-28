<?php

namespace Gloudemans\Tests\Shoppingcart;

use Gloudemans\Shoppingcart\Exceptions\UnknownModelException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Assert;
use Gloudemans\Shoppingcart\Cart;
use Orchestra\Testbench\TestCase;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Collection;
use Gloudemans\Shoppingcart\CartItem;
use Illuminate\Support\Facades\Event;
use Illuminate\Session\SessionManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Gloudemans\Shoppingcart\ShoppingcartServiceProvider;
use Gloudemans\Tests\Shoppingcart\Fixtures\ProductModel;
use Gloudemans\Tests\Shoppingcart\Fixtures\BuyableProduct;
use TypeError;

class CartTest extends TestCase
{
    use CartAssertions;

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

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('cart.database.connection', 'testing');

        $app['config']->set('session.driver', 'array');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->afterResolving('migrator', function ($migrator) {
            $migrator->path(realpath(__DIR__.'/../database/migrations'));
        });
    }

    /** @test */
    public function it_has_a_default_instance()
    {
        $cart = $this->getCart();

        $this->assertEquals(Cart::DEFAULT_INSTANCE, $cart->currentInstance());
    }

    /** @test */
    public function it_can_have_multiple_instances()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'First item'));

        $wishlist = $cart->getInstance('wishlist');
        $default = $cart->getInstance(Cart::DEFAULT_INSTANCE);
        $wishlist->add(new BuyableProduct(2, 'Second item'));

        $this->assertItemsInCart(1, $wishlist);
        $this->assertItemsInCart(1, $default);
    }
    
    /** @test */
    public function it_can_add_an_item()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $this->assertEquals(1, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_will_return_the_cartitem_of_the_added_item()
    {
        Event::fake();

        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct);

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertEquals('8f1b5fe7cbc2c0c42aaa57c61585e605', $cartItem->rowId);

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_multiple_buyable_items_at_once()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add([new BuyableProduct(1), new BuyableProduct(2)]);

        $this->assertEquals(2, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_will_return_an_array_of_cartitems_when_you_add_multiple_items_at_once()
    {
        Event::fake();

        $cart = $this->getCart();

        $cartItems = $cart->add([new BuyableProduct(1), new BuyableProduct(2)]);

        $this->assertTrue(is_array($cartItems));
        $this->assertCount(2, $cartItems);
        $this->assertContainsOnlyInstancesOf(CartItem::class, $cartItems);

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_an_item_from_attributes()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, 10.00);

        $this->assertEquals(1, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_an_item_from_an_array()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(['id' => 1, 'name' => 'Test item', 'qty' => 1, 'price' => 10.00]);

        $this->assertEquals(1, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_multiple_array_items_at_once()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add([
            ['id' => 1, 'name' => 'Test item 1', 'qty' => 1, 'price' => 10.00],
            ['id' => 2, 'name' => 'Test item 2', 'qty' => 1, 'price' => 10.00]
        ]);

        $this->assertEquals(2, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_an_item_with_options()
    {
        Event::fake();

        $cart = $this->getCart();

        $options = ['size' => 'XL', 'color' => 'red'];

        $cartItem = $cart->add(new BuyableProduct, 1, $options);

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertEquals('XL', $cartItem->options->size);
        $this->assertEquals('red', $cartItem->options->color);

        Event::assertDispatched('cart.added');
    }

    /**
     * @test
     */
    public function it_will_validate_the_identifier()
    {
        $this->expectException(TypeError::class);
        $cart = $this->getCart();

        $cart->add(null, 'Some title', 1, 10.00);
    }

    /**
     * @test
     */
    public function it_will_validate_the_name()
    {
        $this->expectException(TypeError::class);
        $cart = $this->getCart();

        $cart->add(1, null, 1, 10.00);
    }

    /**
     * @test
     */
    public function it_will_validate_the_quantity()
    {
        $this->expectException(\TypeError::class);
        $cart = $this->getCart();

        $cart->add(1, 'Some title', 'invalid', 10.00);
    }

    /**
     * @test
     */
    public function it_will_validate_the_price()
    {
        $this->expectException(\TypeError::class);
        $cart = $this->getCart();

        $cart->add(1, 'Some title', 1, 'invalid');
    }

    /** @test */
    public function it_will_update_the_cart_if_the_item_already_exists_in_the_cart()
    {
        $cart = $this->getCart();

        $item = new BuyableProduct;

        $cart->add($item);
        $cart->add($item);

        $this->assertItemsInCart(2, $cart);
        $this->assertRowsInCart(1, $cart);
    }

    /** @test */
    public function it_will_keep_updating_the_quantity_when_an_item_is_added_multiple_times()
    {
        $cart = $this->getCart();

        $item = new BuyableProduct;

        $cart->add($item);
        $cart->add($item);
        $cart->add($item);

        $this->assertItemsInCart(3, $cart);
        $this->assertRowsInCart(1, $cart);
    }

    /** @test */
    public function it_can_update_the_quantity_of_an_existing_item_in_the_cart()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('8f1b5fe7cbc2c0c42aaa57c61585e605', 2);

        $this->assertItemsInCart(2, $cart);
        $this->assertRowsInCart(1, $cart);

        Event::assertDispatched('cart.updated');
    }

    /** @test */
    public function it_can_update_an_existing_item_in_the_cart_from_a_buyable()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('8f1b5fe7cbc2c0c42aaa57c61585e605', new BuyableProduct(1, 'Different description'));

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('Different description', $cart->get('8f1b5fe7cbc2c0c42aaa57c61585e605')->name);

        Event::assertDispatched('cart.updated');
    }

    /** @test */
    public function it_can_update_an_existing_item_in_the_cart_from_an_array()
    {
        Event::fake();

        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct);

        $cart->update($cartItem->rowId, ['name' => 'Different description']);

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('Different description', $cartItem->name);

        Event::assertDispatched('cart.updated');
    }

    /**
     * @test
     */
    public function it_will_throw_an_exception_if_a_rowid_was_not_found()
    {
        $this->expectException(\Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException::class);
        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('none-existing-rowid', new BuyableProduct(1, 'Different description'));
    }

    /** @test */
    public function it_will_regenerate_the_rowid_if_the_options_changed()
    {
        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct, 1, ['color' => 'red']);

        $this->assertEquals('fe1d78fb0252e23016b779d4a053f569', $cartItem->rowId);

        $cart->update($cartItem->rowId, ['options' => ['color' => 'blue']]);

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('3d798927d1e77f20f667dd0f4a292602', $cartItem->rowId);
        $this->assertEquals('blue', $cart->get('3d798927d1e77f20f667dd0f4a292602')->options->color);
    }

    /** @test */
    public function it_will_add_the_item_to_an_existing_row_if_the_options_changed_to_an_existing_rowid()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct, 1, ['color' => 'red']);
        $cartItem = $cart->add(new BuyableProduct, 1, ['color' => 'blue']);

        $cart->update($cartItem->rowId, ['options' => ['color' => 'red']]);

        $this->assertItemsInCart(2, $cart);
        $this->assertRowsInCart(1, $cart);
    }

    /** @test */
    public function it_can_remove_an_item_from_the_cart()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->remove('8f1b5fe7cbc2c0c42aaa57c61585e605');

        $this->assertItemsInCart(0, $cart);
        $this->assertRowsInCart(0, $cart);

        Event::assertDispatched('cart.removed');
    }

    /** @test */
    public function it_will_remove_the_item_if_its_quantity_was_set_to_zero()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('8f1b5fe7cbc2c0c42aaa57c61585e605', 0);

        $this->assertItemsInCart(0, $cart);
        $this->assertRowsInCart(0, $cart);

        Event::assertDispatched('cart.removed');
    }

    /** @test */
    public function it_will_remove_the_item_if_its_quantity_was_set_negative()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->update('8f1b5fe7cbc2c0c42aaa57c61585e605', -1);

        $this->assertItemsInCart(0, $cart);
        $this->assertRowsInCart(0, $cart);

        Event::assertDispatched('cart.removed');
    }

    /** @test */
    public function it_can_get_an_item_from_the_cart_by_its_rowid()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cartItem = $cart->get('8f1b5fe7cbc2c0c42aaa57c61585e605');

        $this->assertInstanceOf(CartItem::class, $cartItem);
    }

    /** @test */
    public function it_can_get_the_content_of_the_cart()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1));
        $cart->add(new BuyableProduct(2));

        $content = $cart->content();

        $this->assertInstanceOf(Collection::class, $content);
        $this->assertCount(2, $content);
    }

    /** @test */
    public function it_will_return_an_empty_collection_if_the_cart_is_empty()
    {
        $cart = $this->getCart();

        $content = $cart->content();

        $this->assertInstanceOf(Collection::class, $content);
        $this->assertCount(0, $content);
    }

    /** @test */
    public function it_will_include_the_tax_and_subtotal_when_converted_to_an_array()
    {
        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct(1));
        $cartItem2 = $cart->add(new BuyableProduct(2));

        $content = $cart->content();

        $this->assertInstanceOf(Collection::class, $content);
        $this->assertEquals([
            $cartItem->rowId => [
                'rowId' => $cartItem->rowId,
                'id' => 1,
                'name' => 'Item name',
                'qty' => 1,
                'price' => 10.00,
                'tax' => 2.10,
                'taxRate' => 21.0,
                'subtotal' => 10.0,
                'options' => [],
                'class' => BuyableProduct::class
            ],
            $cartItem2->rowId => [
                'rowId' => $cartItem2->rowId,
                'id' => 2,
                'name' => 'Item name',
                'qty' => 1,
                'price' => 10.00,
                'tax' => 2.10,
                'taxRate' => 21.0,
                'subtotal' => 10.0,
                'options' => [],
                'class' => BuyableProduct::class
            ]
        ], $content->toArray());
    }

    /** @test */
    public function it_can_destroy_a_cart()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $this->assertItemsInCart(1, $cart);

        $cart->destroy();

        $this->assertItemsInCart(0, $cart);
    }

    /** @test */
    public function it_can_get_the_total_price_of_the_cart_content()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'First item', 10.00));
        $cart->add(new BuyableProduct(2, 'Second item', 25.00), 2);

        $this->assertItemsInCart(3, $cart);
        $this->assertEquals(60.00, $cart->subtotal());
    }

    /** @test */
    public function it_can_return_a_formatted_total()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'First item', 1000.00));
        $cart->add(new BuyableProduct(2, 'Second item', 2500.00), 2);

        $this->assertItemsInCart(3, $cart);
        $this->assertEquals('6.000,00', $cart->numberFormat($cart->subtotal(), 2, ',', '.'));
    }

    /** @test */
    public function it_can_search_the_cart_for_a_specific_item()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some item'));
        $cart->add(new BuyableProduct(2, 'Another item'));

        $cartItem = $cart->search(function ($cartItem, $rowId) {
            return $cartItem->name == 'Some item';
        });

        $this->assertInstanceOf(Collection::class, $cartItem);
        $this->assertCount(1, $cartItem);
        $this->assertInstanceOf(CartItem::class, $cartItem->first());
        $this->assertEquals(1, $cartItem->first()->id);
    }

    /** @test */
    public function it_can_search_the_cart_for_multiple_items()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some item'));
        $cart->add(new BuyableProduct(2, 'Some item'));
        $cart->add(new BuyableProduct(3, 'Another item'));

        $cartItem = $cart->search(function ($cartItem, $rowId) {
            return $cartItem->name == 'Some item';
        });

        $this->assertInstanceOf(Collection::class, $cartItem);
    }

    /** @test */
    public function it_can_search_the_cart_for_a_specific_item_with_options()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some item'), 1, ['color' => 'red']);
        $cart->add(new BuyableProduct(2, 'Another item'), 1, ['color' => 'blue']);

        $cartItem = $cart->search(function ($cartItem, $rowId) {
            return $cartItem->options->color == 'red';
        });

        $this->assertInstanceOf(Collection::class, $cartItem);
        $this->assertCount(1, $cartItem);
        $this->assertInstanceOf(CartItem::class, $cartItem->first());
        $this->assertEquals(1, $cartItem->first()->id);
    }

    /** @test */
    public function it_will_associate_the_cart_item_with_a_model_when_you_add_a_buyable()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cartItem = $cart->get('8f1b5fe7cbc2c0c42aaa57c61585e605');

        $this->assertEquals(BuyableProduct::class, $cartItem->associatedModel);
    }

    /** @test */
    public function it_can_associate_the_cart_item_with_a_model()
    {
        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, 10.00);

        $cart->associate('8f1b5fe7cbc2c0c42aaa57c61585e605', new ProductModel);

        $cartItem = $cart->get('8f1b5fe7cbc2c0c42aaa57c61585e605');

        $this->assertEquals(ProductModel::class, $cartItem->associatedModel);
    }

    /**
     * @test
     */
    public function it_will_throw_an_exception_when_a_non_existing_model_is_being_associated()
    {
        $this->expectExceptionMessage("The supplied model SomeModel does not exist.");
        $this->expectException(UnknownModelException::class);
        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, 10.00);

        $cart->associate('8f1b5fe7cbc2c0c42aaa57c61585e605', 'SomeModel');
    }

    /** @test */
    public function it_can_get_the_associated_model_of_a_cart_item()
    {
        try {
            Schema::create('product_models', function ($table) {
                $table->id();
                $table->string('some_value');
                $table->timestamps();
            });
            $pm = new ProductModel();
            $pm->some_value = 'Some value';
            $pm->save();

            $cart = $this->getCart();

            $cartItem = $cart->add($pm->id, 'Test item', 1, 10.00);

            $cart->associate($cartItem->rowId, $pm);

            $this->assertInstanceOf(ProductModel::class, $model = $cartItem->model);
            $this->assertEquals($pm === $cartItem->model, true);
            $serialized = unserialize(serialize($cartItem));
            $this->assertEquals('Some value', $serialized->model->some_value);
            $this->assertFalse($serialized->model === $model);
        } finally {
            Schema::dropIfExists('product_models');
        }
    }

    /** @test */
    public function it_can_calculate_the_subtotal_of_a_cart_item()
    {
        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct(1, 'Some title', 9.99), 3);

        $this->assertEquals(29.97, $cartItem->subtotal);
    }

    /** @test */
    public function it_can_return_a_formatted_subtotal()
    {
        $cart = $this->getCart();

        $item = $cart->add(new BuyableProduct(1, 'Some title', 500), 3);

        $cartItem = $cart->get($item->rowId);

        $this->assertEquals('1.500,00', $cart->numberFormat($cartItem->subtotal(), 2, ',', '.'));
    }

    /** @test */
    public function it_can_calculate_tax_based_on_the_default_tax_rate_in_the_config()
    {
        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct(1, 'Some title', 10.00), 1);

        $this->assertEquals(2.10, $cartItem->tax);
    }

    /** @test */
    public function it_can_calculate_tax_based_on_the_specified_tax()
    {
        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct(1, 'Some title', 10.00), 1);

        $cart->setTax($cartItem->rowId, 19);

        $this->assertEquals(1.90, $cartItem->tax);
    }

    /** @test */
    public function it_can_return_the_calculated_tax_formatted()
    {
        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct(1, 'Some title', 10000.00), 1);

        $this->assertEquals('2.100,00', $cart->numberFormat($cartItem->tax(), 2, ',', '.'));
    }

    /** @test */
    public function it_can_calculate_the_total_tax_for_all_cart_items()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 10.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 20.00), 2);

        $this->assertEquals(10.50, $cart->tax);
    }

    /** @test */
    public function it_can_return_formatted_total_tax()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 1000.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 2000.00), 2);

        $this->assertEquals('1050.00', $cart->numberFormat($cart->tax()));
    }

    /** @test */
    public function it_can_return_the_subtotal()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 10.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 20.00), 2);

        $this->assertEquals(50.00, $cart->subtotal);
    }

    /** @test */
    public function it_can_return_formatted_subtotal()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 1000.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 2000.00), 2);

        $this->assertEquals('5000,00', $cart->numberFormat($cart->subtotal(), 2, ',', ''));
    }

    /** @test */
    public function it_can_return_cart_formated_numbers_by_config_values()
    {
        $this->setConfigFormat(2, ',', '');

        $cart = $this->getCart();

        $cart->add(new BuyableProduct(1, 'Some title', 1000.00), 1);
        $cart->add(new BuyableProduct(2, 'Some title', 2000.00), 2);

        $this->assertEquals('5000,00', $cart->numberFormat($cart->subtotal()));
        $this->assertEquals('1050,00', $cart->numberFormat($cart->tax()));
        $this->assertEquals('6050,00', $cart->numberFormat($cart->total()));
    }

    /** @test */
    public function it_can_return_cartItem_formated_numbers_by_config_values()
    {
        $this->setConfigFormat(2, ',', '');

        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct(1, 'Some title', 2000.00), 2);

        $this->assertEquals('2000,00', $cart->numberFormat($cartItem->price()));
        $this->assertEquals('2420,00', $cart->numberFormat($cartItem->priceTax()));
        $this->assertEquals('4000,00', $cart->numberFormat($cartItem->subtotal()));
        $this->assertEquals('4840,00', $cart->numberFormat($cartItem->total()));
        $this->assertEquals('420,00', $cart->numberFormat($cartItem->tax()));
        $this->assertEquals('840,00', $cart->numberFormat($cartItem->taxTotal()));
    }

    /** @test */
    public function it_can_store_the_cart_in_a_database()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->store($identifier = 123);

        $serialized = serialize($cart->content()->map(fn ($item) => $item->toArray(true))->all());

        $this->assertDatabaseHas('shopping_cart', ['identifier' => $identifier, 'instance' => 'default', 'content' => $serialized]);

        Event::assertDispatched('cart.stored');
    }

    /**
     * @test
     * @expectedException \Gloudemans\Shoppingcart\Exceptions\CartAlreadyStoredException
     * @expectedExceptionMessage A cart with identifier 123 was already stored.
     */
    public function it_will_throw_an_exception_when_a_cart_was_already_stored_using_the_specified_identifier()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->store($identifier = 123);

        $cart->store($identifier);

        Event::assertDispatched('cart.stored');
    }

    /** @test */
    public function it_can_restore_a_cart_from_the_database()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct);

        $cart->store($identifier = 123);

        $cart->destroy();

        $this->assertItemsInCart(0, $cart);

        $cart->restore($identifier);

        $this->assertItemsInCart(1, $cart);

        $this->assertDatabaseMissing('shopping_cart', ['identifier' => $identifier, 'instance' => 'default']);

        Event::assertDispatched('cart.restored');
    }

    /** @test */
    public function it_will_just_keep_the_current_instance_if_no_cart_with_the_given_identifier_was_stored()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        $cart = $this->getCart();

        $cart->restore(123);

        $this->assertItemsInCart(0, $cart);
    }

    /** @test */
    public function it_can_calculate_all_values()
    {
        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct(1, 'First item', 10.00), 2);

        $cart->setTax($cartItem->rowId, 19);

        $this->assertEquals(10.00, $cartItem->price());
        $this->assertEquals(11.90, $cartItem->priceTax());
        $this->assertEquals(20.00, $cartItem->subtotal());
        $this->assertEquals(23.80, $cartItem->total());
        $this->assertEquals(1.90, $cartItem->tax());
        $this->assertEquals(3.80, $cartItem->taxTotal());

        $this->assertEquals(20.00, $cart->subtotal());
        $this->assertEquals(23.80, $cart->total());
        $this->assertEquals(3.80, $cart->tax());
    }

    /** @test */
    public function it_will_destroy_the_cart_when_the_user_logs_out_and_the_config_setting_was_set_to_true()
    {
        $this->app['config']->set('cart.destroy_on_logout', true);

        $this->app->instance(SessionManager::class, Mockery::mock(SessionManager::class, function ($mock) {
            $mock->shouldReceive('forget')->once()->with('cart');
        }));

        $user = Mockery::mock(Authenticatable::class);

        event(new Logout('auth', $user));
    }

    /**
     * Get an instance of the cart.
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    private function getCart()
    {
        $session = $this->app->make('session');
        $events = $this->app->make('events');

        return new Cart($session, $events);
    }

    /**
     * Set the config number format.
     * 
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     */
    private function setConfigFormat($decimals, $decimalPoint, $thousandSeperator)
    {
        $this->app['config']->set('cart.format.decimals', $decimals);
        $this->app['config']->set('cart.format.decimal_point', $decimalPoint);
        $this->app['config']->set('cart.format.thousand_separator', $thousandSeperator);
    }
}
