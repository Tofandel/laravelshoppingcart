<?php

namespace Gloudemans\Shoppingcart;

use Carbon\Carbon;
use Gloudemans\Shoppingcart\Contracts\InstanceIdentifier;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Gloudemans\Shoppingcart\Exceptions\UnknownModelException;
use Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException;
use Gloudemans\Shoppingcart\Exceptions\CartAlreadyStoredException;
use Illuminate\Support\Str;

class Cart
{
    const DEFAULT_INSTANCE = 'default';

    const COST_SHIPPING = 'shipping';
    const COST_TRANSACTION = 'transaction';

    /**
     * Holds the current cart instance.
     */
    private string $instance;

    /**
     * Holds the extra additional costs on the cart
     */
    private Collection $extraCosts;
    private Carbon $createdAt;
    private Carbon $updatedAt;

    /**
     * Cart constructor.
     *
     * @param SessionManager $session
     * @param Dispatcher $events
     */
    public function __construct(private SessionManager $session, private Dispatcher $events)
    {
        $this->extraCosts = new Collection();

        $this->instance(self::DEFAULT_INSTANCE);
    }

    /**
     * Set the current cart instance.
     */
    public function instance(string $instance = null): static
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = 'cart.' . $instance;

        return $this;
    }

    /**
     * Set the current cart instance.
     */
    public function getInstance(string $instance = null): static
    {
        $clone = clone $this;

        $clone->instance($instance);

        return $clone;
    }

    /**
     * Get the current cart instance.
     */
    public function currentInstance(): string
    {
        return Str::after($this->instance, 'cart.');
    }

    /**
     * Add an item to the cart.
     *
     * @param mixed $id
     * @param mixed $name
     * @param array $options
     */
    public function add($id, Buyable|string $name = null, int|float|array $qty = null, float $price = null, array $options = []): array|CartItem
    {
        if ($this->isMulti($id)) {
            return array_map(function ($item) {
                return $this->add($item);
            }, $id);
        }

        $cartItem = $this->createCartItem($id, $name, $qty, $price, $options);

        $content = $this->getContent();

        if ($content->has($cartItem->rowId)) {
            $cartItem->qty += $content->get($cartItem->rowId)->qty;
        }

        $content->put($cartItem->rowId, $cartItem);

        $this->events->dispatch('cart.added', $cartItem);

        $this->session->put($this->instance, $content);

        return $cartItem;
    }

    /**
     * Sets/adds an additional cost on the cart.
     *
     * @param string $name
     * @param float $price
     * @todo add in session
     */
    public function addCost($name, $price)
    {
        $oldCost = $this->extraCosts->pull($name, 0);

        $this->extraCosts->put($name, $price + $oldCost);
    }

    /**
     * Gets an additional cost by name
     *
     * @param $name
     * @return string
     */
    public function getCost($name)
    {
        return $this->extraCosts->get($name, 0);
    }

    /**
     * Update the cart item with the given rowId.
     */
    public function update(string $rowId, array|int|Buyable $qty): ?CartItem
    {
        $cartItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $cartItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $cartItem->updateFromArray($qty);
        } else {
            $cartItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $cartItem->rowId) {
            $content->pull($rowId);

            if ($content->has($cartItem->rowId)) {
                $existingCartItem = $this->get($cartItem->rowId);
                $cartItem->setQuantity($existingCartItem->qty + $cartItem->qty);
            }
        }

        if ($cartItem->qty <= 0) {
            $this->remove($cartItem->rowId);
            return null;
        } else {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->dispatch('cart.updated', $cartItem);

        $this->session->put($this->instance, $content);

        return $cartItem;
    }

    /**
     * Remove the cart item with the given rowId from the cart.
     *
     * @param string $rowId
     * @return void
     */
    public function remove(string $rowId)
    {
        $cartItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($cartItem->rowId);

        $this->events->dispatch('cart.removed', $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Get a cart item from the cart by its rowId.
     *
     * @param string $rowId
     * @return CartItem
     */
    public function get(string $rowId)
    {
        $content = $this->getContent();

        if (!$content->has($rowId))
            throw new InvalidRowIDException("The cart does not contain rowId {$rowId}.");

        return $content->get($rowId);
    }

    /**
     * Destroy the current cart instance.
     *
     * @return void
     */
    public function destroy()
    {
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the cart.
     *
     * @return Collection
     */
    public function content()
    {
        if (is_null($this->session->get($this->instance))) {
            return new Collection();
        }

        return $this->session->get($this->instance);
    }

    /**
     * Get the number of items in the cart.
     *
     * @return int|float
     */
    public function count(): float|int
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * Get the total price of the items in the cart.
     */
    public function total(): float
    {
        $content = $this->getContent();

        $total = $content->reduce(function ($total, CartItem $cartItem) {
            return $total + ($cartItem->qty * $cartItem->priceTax);
        }, 0);

        $totalCost = $this->extraCosts->reduce(function ($total, $cost) {
            return $total + $cost;
        }, 0);

        $total += $totalCost;

        return $total;
    }

    /**
     * Get the total tax of the items in the cart.
     */
    public function tax(): float
    {
        $content = $this->getContent();

        return $content->reduce(function ($tax, CartItem $cartItem) {
            return $tax + ($cartItem->qty * $cartItem->tax);
        }, 0);
    }

    /**
     * Get the subtotal (total - tax) of the items in the cart.
     */
    public function subtotal(): float
    {
        $content = $this->getContent();

        return $content->reduce(function ($subTotal, CartItem $cartItem) {
            return $subTotal + ($cartItem->qty * $cartItem->price);
        }, 0);
    }

    /**
     * Search the cart content for a cart item matching the given search closure.
     */
    public function search(callable $search): Collection
    {
        $content = $this->getContent();

        return $content->filter($search);
    }

    /**
     * Associate the cart item with the given rowId with the given model.
     *
     * @param string $rowId
     * @param mixed $model
     * @return void
     */
    public function associate(string $rowId, $model)
    {
        if (is_string($model) && !class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cartItem = $this->get($rowId);

        $cartItem->associate($model);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the tax rate for the cart item with the given rowId.
     */
    public function setTax(string $rowId, int|float $taxRate): void
    {
        $cartItem = $this->get($rowId);

        $cartItem->setTaxRate($taxRate);

        $this->updateCartItem($cartItem);
    }

    protected function updateCartItem(CartItem $cartItem): void
    {
        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Store the current instance of the cart.
     *
     * @param mixed $identifier
     * @return void
     */
    public function store(string|InstanceIdentifier $identifier): void
    {
        $content = $this->getContent();
        if ($content->isEmpty()) {
            $this->delete($identifier);
            return;
        }
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $instance = $this->currentInstance();

        $this->getConnection()->table($this->getTableName())->updateOrInsert([
            'identifier' => $identifier,
            'instance' => $instance,
        ], [
            'content' => serialize($content),
            'created_at' => $this->createdAt ?? Carbon::now(),
            'updated_at' => $this->updatedAt ?? Carbon::now(),
        ]);

        $this->events->dispatch('cart.stored');

    }

    private function storedCartInstanceWithIdentifierExists(string $instance, string $identifier): bool
    {
        return $this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance' => $instance])->exists();
    }


    /**
     * Restore the cart with the given identifier.
     */
    public function restore(string|InstanceIdentifier $identifier): void
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $currentInstance = $this->currentInstance();

        if (!$this->storedCartInstanceWithIdentifierExists($currentInstance, $identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where(['identifier' => $identifier, 'instance' => $currentInstance])->first();

        $storedContent = unserialize($stored->content);

        $content = $this->getContent($stored->instance);

        foreach ($storedContent as $cartItem) {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->dispatch('cart.restored');

        $this->session->put($this->instance, $content);

        $this->createdAt = Carbon::parse($stored->created_at);
        $this->updatedAt = Carbon::parse($stored->updated_at);

        $this->delete($identifier);
    }

    public function delete(string|InstanceIdentifier $identifier): bool
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $currentInstance = $this->currentInstance();

        return !!$this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance' => $currentInstance])->delete();
    }

    /**
     * Merges the contents of another cart into this cart.
     */
    public function merge(string|InstanceIdentifier $identifier, bool $keepTax = false, bool $dispatchAdd = true, string $instance = null): bool
    {
        return $this->usingInstance($instance, function ($instance) use ($identifier, $keepTax, $dispatchAdd) {
            if ($identifier instanceof InstanceIdentifier) {
                $identifier = $identifier->getInstanceIdentifier();
            }

            if (!$this->storedCartInstanceWithIdentifierExists($instance, $identifier)) {
                return false;
            }

            $stored = $this->getConnection()->table($this->getTableName())
                ->where(['identifier' => $identifier, 'instance' => $instance])->first();

            $storedContent = unserialize($stored->content);

            foreach ($storedContent as $cartItem) {
                $this->addCartItem($cartItem, $keepTax, $dispatchAdd);
            }

            $this->events->dispatch('cart.merged');

            return true;
        });
    }

    /**
     * Add an item to the cart.
     */
    public function addCartItem(CartItem $item, bool $keepTax = false, bool $dispatchEvent = true): CartItem
    {
        if (!$keepTax) {
            $item->setTaxRate($this->taxRate);
        }

        $content = $this->getContent();

        if ($content->has($item->rowId)) {
            $item->qty += $content->get($item->rowId)->qty;
        }

        $content->put($item->rowId, $item);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.adding', $item);
        }

        $this->session->put($this->instance, $content);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.added', $item);
        }

        return $item;
    }

    /**
     * Magic method to make accessing the total, tax and subtotal properties possible.
     */
    public function __get(string $attribute)
    {
        if ($attribute === 'total') {
            return $this->total();
        }

        if ($attribute === 'tax') {
            return $this->tax();
        }

        if ($attribute === 'subtotal') {
            return $this->subtotal();
        }

        return null;
    }

    /**
     * Get the carts content, if there is no cart content set yet, return a new empty Collection
     */
    protected function getContent(string $instance = null): Collection
    {
        return $this->usingInstance($instance, function ($instance) {
            return $this->session->has($instance)
                ? $this->session->get($instance)
                : new Collection;
        });
    }

    public function usingInstance(?string $instance, callable $callable)
    {
        $oldInstance = $this->instance;
        if ($instance) {
            $this->instance($instance);
        }
        return tap(call_user_func($callable, $this->instance), fn() => $this->instance = $oldInstance);
    }

    /**
     * Create a new CartItem from the supplied attributes.
     */
    private function createCartItem(array|Buyable|string|int $id, int|string|null $name, int|float|null|array $qty, ?float $price, ?array $options): CartItem
    {
        if ($id instanceof Buyable) {
            $cartItem = CartItem::fromBuyable($id, $qty ?: []);
            $cartItem->setQuantity($name ?: 1);
            $cartItem->associate($id);
        } elseif (is_array($id)) {
            $cartItem = CartItem::fromArray($id);
            $cartItem->setQuantity($id['qty']);
        } else {
            $cartItem = CartItem::fromAttributes($id, $name, $price, $options);
            $cartItem->setQuantity($qty);
        }

        $cartItem->setTaxRate(config('cart.tax'));

        return $cartItem;
    }

    /**
     * Check if the item is a multidimensional array or an array of Buyables.
     *
     * @param mixed $item
     * @return bool
     */
    private function isMulti(mixed $item): bool
    {
        if (!is_array($item)) return false;

        return is_array(head($item)) || head($item) instanceof Buyable;
    }

    private function storedCartWithIdentifierExists(string $identifier): bool
    {
        return $this->getConnection()->table($this->getTableName())->where('identifier', $identifier)->exists();
    }

    /**
     * Get the database connection.
     */
    private function getConnection(): Connection
    {
        $connectionName = $this->getConnectionName();

        return app(DatabaseManager::class)->connection($connectionName);
    }

    /**
     * Get the database table name.
     */
    private function getTableName(): string
    {
        return config('cart.database.table', 'shopping_cart');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName(): string
    {
        return config('cart.database.connection') ?? config('database.default');
    }

    /**
     * Get the formatted number
     */
    private function numberFormat(int|float $value): string
    {
        $decimals = config('cart.format.decimals', 2);
        $decimalPoint = config('cart.format.decimal_point', '.');
        $thousandSeparator = '';

        return number_format($value, $decimals, $decimalPoint, $thousandSeparator);
    }
}
