{if !empty($product->sviat_promo_min_order_amount)}
<div class="sviat_promo_min_order_note"><span data-language="sviat_promo__min_order_notice_from">{$lang->sviat_promo__min_order_notice_from}</span> {$product->sviat_promo_min_order_amount|convert} {$currency->sign|escape}</div>
{/if}
