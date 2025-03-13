<?php

namespace Gloudemans\Shoppingcart;

use Illuminate\Contracts\Support\Arrayable;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Illuminate\Contracts\Support\Jsonable;

use Illuminate\Support\Arr;

/**
 * @property-read float $tax
 * @property-read float $taxTotal
 */
class CartItem implements Arrayable, Jsonable
{
    /**
     * The rowID of the cart item.
     *
     * @var string
     */
    public string $rowId;

    /**
     * The quantity for this cart item.
     *
     * @var int|float
     */
    public $qty;


    /**
     * The options for this cart item.
     *
     * @var array
     */
    public $options;

    /**
     * The FQN of the associated model.
     *
     * @var string|null
     */
    private $associatedModel = null;

    /**
     * The tax rate for the cart item.
     */
    private float $taxRate = 0;

    private mixed $_model = null;

    /**
     * CartItem constructor.
     */
    public function __construct(
        public string|int $id,
        public string $name,
        public float $price,
        array $options = [])
    {
        if(empty($this->id)) {
            throw new \InvalidArgumentException('Please supply a valid identifier.');
        }
        if(empty($this->name)) {
            throw new \InvalidArgumentException('Please supply a valid name.');
        }

        $this->options  = new CartItemOptions($options);
        $this->rowId = $this->generateRowId();
    }

    /**
     * Returns the formatted price without TAX.
     */
    public function price(): float
    {
        return $this->price;
    }

    /**
     * Returns the formatted price with TAX.
     */
    public function priceTax(): float
    {
        return $this->priceTax;
    }

    /**
     * Returns the formatted subtotal.
     * Subtotal is price for whole CartItem without TAX
     */
    public function subtotal(): float
    {
        return $this->subtotal;
    }

    /**
     * Returns the formatted total.
     * Total is price for whole CartItem with TAX
     */
    public function total(): float
    {
        return $this->total;
    }

    /**
     * Returns the formatted tax.
     */
    public function tax(): float
    {
        return $this->tax;
    }

    /**
     * Returns the formatted tax.
     */
    public function taxTotal(): float
    {
        return $this->taxTotal;
    }

    /**
     * Set the quantity for this cart item.
     *
     * @param int|float $qty
     */
    public function setQuantity(int|float $qty): void
    {
        $this->qty = $qty;
    }

    /**
     * Update the cart item from a Buyable.
     *
     * @param Buyable $item
     * @return void
     */
    public function updateFromBuyable(Buyable $item)
    {
        $this->id       = $item->getBuyableIdentifier($this->options);
        $this->name     = $item->getBuyableDescription($this->options);
        $this->price    = $item->getBuyablePrice($this->options);
        $this->priceTax = $this->price + $this->tax;
    }

    /**
     * Update the cart item from an array.
     *
     * @param array $attributes
     * @return void
     */
    public function updateFromArray(array $attributes)
    {
        $this->id       = Arr::get($attributes, 'id', $this->id);
        $this->qty      = Arr::get($attributes, 'qty', $this->qty);
        $this->name     = Arr::get($attributes, 'name', $this->name);
        $this->price    = Arr::get($attributes, 'price', $this->price);
        $this->priceTax = $this->price + $this->tax;
        $this->options  = new CartItemOptions(Arr::get($attributes, 'options', $this->options));

        $this->rowId = $this->generateRowId();
    }

    /**
     * Associate the cart item with the given model.
     *
     * @param mixed $model
     * @return CartItem
     */
    public function associate($model)
    {
        $this->associatedModel = is_string($model) ? $model : get_class($model);

        return $this;
    }

    /**
     * Set the tax rate.
     *
     * @param int|float $taxRate
     * @return CartItem
     */
    public function setTaxRate($taxRate)
    {
        $this->taxRate = $taxRate;

        return $this;
    }

    /**
     * Get an attribute from the cart item or get the associated model.
     *
     * @param string $attribute
     * @return mixed
     */
    public function __get($attribute)
    {
        if(property_exists($this, $attribute)) {
            return $this->{$attribute};
        }

        // TODO this is an absolute mess
        if($attribute === 'priceTax') {
            return number_format($this->price + $this->tax, 2, '.', '');
        }

        if($attribute === 'subtotal') {
            return number_format($this->qty * $this->price, 2, '.', '');
        }

        if($attribute === 'total') {
            return number_format($this->qty * $this->priceTax, 2, '.', '');
        }

        if($attribute === 'tax') {
            return number_format($this->price * ($this->taxRate / 100), 2, '.', '');
        }

        if($attribute === 'taxTotal') {
            return number_format($this->tax * $this->qty, 2, '.', '');
        }

        if($attribute === 'model' && isset($this->associatedModel)) {
            if (!isset($this->_model)) {
                $this->_model = ($this->associatedModel)::withoutGlobalScopes()->find($this->id) ?? false;
            }
            return $this->_model;
        }

        return null;
    }

    /**
     * Create a new instance from a Buyable.
     *
     * @param Buyable $item
     * @param array $options
     * @return CartItem
     */
    public static function fromBuyable(Buyable $item, array $options = [])
    {
        return new self($item->getBuyableIdentifier($options), $item->getBuyableDescription($options), $item->getBuyablePrice($options), $options);
    }

    /**
     * Create a new instance from the given array.
     *
     * @param array $attributes
     * @return CartItem
     */
    public static function fromArray(array $attributes)
    {
        $options = Arr::get($attributes, 'options', []);

        return new self($attributes['id'], $attributes['name'], $attributes['price'], $options);
    }

    /**
     * Create a new instance from the given attributes.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param array      $options
     * @return CartItem
     */
    public static function fromAttributes($id, $name, $price, array $options = [])
    {
        return new self($id, $name, $price, $options);
    }

    /**
     * Generate a unique id for the cart item.
     *
     * @return string
     */
    protected function generateRowId(): string
    {

        return md5($this->id . serialize($this->options->sortKeys()->all()));
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'rowId'    => $this->rowId,
            'id'       => $this->id,
            'name'     => $this->name,
            'qty'      => $this->qty,
            'price'    => $this->price,
            'options'  => $this->options->toArray(),
            'tax'      => $this->tax,
            'subtotal' => $this->subtotal
        ];
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param int $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Get the formatted number.
     *
     * @param float  $value
     * @return string
     */
    private function numberFormat($value)
    {
        $decimals = is_null(config('cart.format.decimals')) ? 2 : config('cart.format.decimals');
        $decimalPoint = is_null(config('cart.format.decimal_point')) ? '.' : config('cart.format.decimal_point');
        $thousandSeparator = '';

        return number_format($value, $decimals, $decimalPoint, $thousandSeparator);
    }
}
