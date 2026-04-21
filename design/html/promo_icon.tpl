{if $gift}
    {foreach $gift as $g}
    <div class="promo_reward_badge">
        <div class="promo_reward_badge__title">{$lang->sviat_promo__gift}</div>
        <div class="promo_reward_badge__image">
            {if $g->image->filename}
                <img src="{$g->image->filename|resize:60:60}" alt="{$g->name|escape}" title="{$g->name|escape}" />
            {else}
                <span class="promo_reward_badge__image_placeholder">
                    {include file="svg.tpl" svgId="no_image"}
                </span>
            {/if}
        </div>
    </div>
        <div class="fn_gift{if $g@first} selected{/if}" data-gift_id="{$g->id}" data-gift_variant_id="{$g->variant->id}" data-product_id="{$g->id}" data-variant_id="{$g->variant->id}" data-promo_id="{$promo->id}">
        </div>
    {/foreach}
{/if}