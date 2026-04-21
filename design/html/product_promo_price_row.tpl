{if isset($product->sviat_promo_discounted_unit_price) && $product->sviat_promo_discounted_unit_price > 0}
<div class="w-100 promo_price_row text_14 mb-1" data-sviat-promo-price="1">
    <strong class="price--red">{$product->sviat_promo_discounted_unit_price|convert}</strong>
    <span class="currency">{$currency->sign|escape}</span>
    <del class="text-muted ml-1">{$product->variant->price|convert}</del>
    {if isset($product->sviat_promo_discount_percent)}
        <span class="text-muted">(−{$product->sviat_promo_discount_percent|string_format:"%.0f"}%)</span>
    {/if}
</div>
{/if}
