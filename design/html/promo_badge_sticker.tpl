{if $product->sviat_promo_badge_image}
    <span class="sticker sticker--sviat-promo">
        <img class="sticker__image" src="{$product->sviat_promo_badge_image|resize:64:64:false:$config->resized_promo_images_dir}" alt="" />
    </span>
{/if}
{if $sviat_promo_show_conditions}
    {foreach $product->sviat_promo_conditions as $condition_variant_id => $condition}
        <span class="sticker sticker--discount fn_sviat_promo_condition{if $condition_variant_id != $product->variant->id} hidden-xs-up{/if}" data-variant-id="{$condition_variant_id|escape}" title="{$lang->sviat_promo__condition_from|escape} {$condition->min_order_amount|convert} {$currency->sign|escape}">-{$condition->percent|string_format:"%.0f"|escape}&nbsp;%</span>
    {/foreach}
{/if}
