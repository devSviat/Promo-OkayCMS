{if !empty($product->sviat_promo_conditions)}
    {foreach $product->sviat_promo_conditions as $condition_variant_id => $condition}
        <div class="sviat_promo__condition fn_sviat_promo_condition{if $condition_variant_id != $product->variant->id} hidden-xs-up{/if}" data-variant-id="{$condition_variant_id|escape}">
            <strong class="sviat_promo__condition_discount">{if $condition->promo_type == 'fixed'}−{$condition->discount_fixed|convert}&nbsp;{$currency->sign|escape}{else}−{$condition->percent|string_format:"%g"|escape}&nbsp;%{/if}</strong>
            <span data-lang="sviat_promo__condition_from">{$lang->sviat_promo__condition_from|escape}</span>
            {$condition->min_order_amount|convert}&nbsp;{$currency->sign|escape} —
            <span data-lang="sviat_promo__condition_price">{$lang->sviat_promo__condition_price|escape}</span>
            <strong class="price--red">{$condition->price|convert}&nbsp;{$currency->sign|escape}</strong>
        </div>
    {/foreach}
{/if}
