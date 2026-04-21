<div class="details_boxed__item details_boxed__item--one">
    {if $promo}
        {if $promo->caption_banner_image}
            {if $promo->product_caption_mode == 3}
                <div class="sviat_promo__promoaction sviat_promo__promoaction--img_only">
                    {include file='promo_product_banner.tpl'}
                </div>
            {elseif $promo->product_caption_mode == 2}
                <div class="sviat_promo__promoaction sviat_promo__promoaction--above">
                    {include file='promo_product_banner.tpl'}
                    <div class="sviat_promo__promoaction_line mt-1">
                        {include file='promo_product_line.tpl'}
                    </div>
                    {include file='promo_product_timer.tpl'}
                </div>
            {elseif $promo->product_caption_mode == 1}
                <div class="sviat_promo__promoaction sviat_promo__promoaction--replace">
                    {include file='promo_product_banner.tpl'}
                    {include file='promo_product_timer.tpl'}
                </div>
            {else}
                <div class="sviat_promo__promoaction sviat_promo__promoaction--below">
                    {include file='promo_product_line.tpl'}
                    {include file='promo_product_timer.tpl'}
                    {include file='promo_product_banner.tpl' banner_extra_class='mt-1'}
                </div>
            {/if}
        {else}
            <div class="sviat_promo__promoaction">
                {include file='promo_product_line.tpl'}
                {include file='promo_product_timer.tpl'}
            </div>
        {/if}

        {if $promo->promo_type == 'gift' && $promo->gifts}
            <div class="promo_reward__grid" id="promo_gifts">
                {foreach $promo->gifts as $g}
                    <div class="promo_reward__item">
                        <div class="promo_reward__card fn_gift{if $g@first} selected{/if}" data-gift_id="{$g->id}"
                             data-gift_variant_id="{$g->variant->id}" data-product_id="{$product->id}"
                             data-variant_id="{$product->variant->id}" data-promo_id="{$promo->id}">
                            <div class="promo_reward__image">
                                {if $g->image}
                                    <img src="{$g->image->filename|resize:80:80}" alt="{$g->name|escape}"
                                         title="{$g->name|escape}">
                                {else}
                                    <div>
                                        {include file="svg.tpl" svgId="no_image"}
                                    </div>
                                {/if}
                            </div>
                            <div class="promo_reward__info">
                                <a class="promo_reward__name" href="{url_generator route="product" url=$g->url}">{$g->name|escape}</a>
                                <div class="promo_reward__price">
                                    <span class="promo_reward__price_old"><del>{$g->variant->price|convert} {$currency->sign|escape}</del></span>
                                    <span class="promo_reward__price_free" data-lang="sviat_promo__free">{$lang->sviat_promo__free}</span>
                                </div>
                            </div>
                            <div class="promo_reward__mark" data-lang="sviat_promo__gift">{$lang->sviat_promo__gift}</div>
                        </div>
                    </div>
                {/foreach}
            </div>
        {/if}
    {/if}
</div>
