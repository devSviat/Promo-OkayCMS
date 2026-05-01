{$canonical="{url_generator route="sviat_promo_page" url=$promo->url absolute=1}" scope=global}

<div class="block">
    {* Content with promo *}
    <div class="post_container__wrapper">
        <div class="promo_campaign__hero{if $promo->is_expired} promo_campaign__hero--expired{/if}{if $promo->is_upcoming} promo_campaign__hero--upcoming{/if}"
             style="--promo-hero-min-height:{if ($is_mobile == true && $is_tablet == false) && !empty($promo->image_mobile)}{$promo->image_mobile_height|default:400|escape}{else}{$promo->image_height|default:400|escape}{/if}px; background: #15151a url({if ($is_mobile == true && $is_tablet == false) && !empty($promo->image_mobile)}{$promo->image_mobile|resize:($promo->image_mobile_width|default:1350):($promo->image_mobile_height|default:400):false:$config->resized_promo_images_dir}{else}{$promo->image|resize:($promo->image_width|default:1350):($promo->image_height|default:400):false:$config->resized_promo_images_dir}{/if}) no-repeat center center;">
            {if $promo->is_expired}
                <div class="promo_campaign__expired_badge">
                    {$lang->sviat_promo_page__promo_expired|escape}
                </div>
            {elseif $promo->is_upcoming}
                <div class="promo_campaign__upcoming_badge">
                    {$lang->sviat_promo__upcoming|escape}
                </div>
            {/if}
        </div>
        {* The page heading *}
        <h1 class="block--boxed">
            <span data-lang="sviat_promo_page__promoaction">{$lang->sviat_promo_page__promoaction}</span> {$promo->name|escape}
        </h1>
        {if $promo->has_date_range}
            <div class="block block--boxed">
                {if $promo->is_upcoming}
                    <div class="promo_date_range">
                        <span>{$lang->sviat_promo_page__starts|escape} {$promo->date_start|date}</span>
                    </div>
                    <div class="promo_timer fn_promo_timer" data-seconds-left="{$promo->seconds_to_start|escape}">
                        <div class="promo_timer__title">{$lang->sviat_promo_page__time_to_start|escape}</div>
                        <div class="promo_timer__grid">
                            <div class="promo_timer__item">
                                <div class="promo_timer__value fn_timer_days">00</div>
                                <div class="promo_timer__label">{$lang->sviat_promo__timer_days}</div>
                            </div>
                            <div class="promo_timer__item">
                                <div class="promo_timer__value fn_timer_hours">00</div>
                                <div class="promo_timer__label">{$lang->sviat_promo__timer_hours}</div>
                            </div>
                            <div class="promo_timer__item">
                                <div class="promo_timer__value fn_timer_minutes">00</div>
                                <div class="promo_timer__label">{$lang->sviat_promo__timer_minutes}</div>
                            </div>
                            <div class="promo_timer__item">
                                <div class="promo_timer__value fn_timer_seconds">00</div>
                                <div class="promo_timer__label">{$lang->sviat_promo__timer_seconds}</div>
                            </div>
                        </div>
                    </div>
                    <div class="promo_campaign__upcoming_notice">
                        {$lang->sviat_promo_page__upcoming_products_hidden|escape}
                    </div>
                {elseif $promo->seconds_left > 0}
                    <div class="promo_date_range">
                        <span data-lang="sviat_promo_page__promo_date_range">{$lang->sviat_promo_page__promo_date_range} {$promo->date_start|date} - {$promo->date_end|date}</span>
                    </div>
                    <div class="promo_timer fn_promo_timer" data-seconds-left="{$promo->seconds_left|escape}">
                        <div class="promo_timer__title" data-lang="time_left">{$lang->sviat_promo_page__time_left}</div>
                        <div class="promo_timer__grid">
                            <div class="promo_timer__item">
                                <div class="promo_timer__value fn_timer_days">00</div>
                                <div class="promo_timer__label">{$lang->sviat_promo__timer_days}</div>
                            </div>
                            <div class="promo_timer__item">
                                <div class="promo_timer__value fn_timer_hours">00</div>
                                <div class="promo_timer__label">{$lang->sviat_promo__timer_hours}</div>
                            </div>
                            <div class="promo_timer__item">
                                <div class="promo_timer__value fn_timer_minutes">00</div>
                                <div class="promo_timer__label">{$lang->sviat_promo__timer_minutes}</div>
                            </div>
                            <div class="promo_timer__item">
                                <div class="promo_timer__value fn_timer_seconds">00</div>
                                <div class="promo_timer__label">{$lang->sviat_promo__timer_seconds}</div>
                            </div>
                        </div>
                    </div>
                {else}
                    <div class="promos_expired" data-lang="promo_expired">{$lang->sviat_promo_page__promo_expired}</div>
                {/if}
            </div>
        {/if}

        {if $promo->description}
            <div class="block block--boxed">
                {* Post content *}
                <div class="block__description">
                    {$promo->description}
                </div>
            </div>
        {/if}
    </div>

    {* Related products — hidden when campaign has ended or not yet started *}
    {if !$promo->is_expired && !$promo->is_upcoming && $promo->products}
        <div class="block block--boxed block--border">
            <div class="block__header">
                <div class="block__title">
                    <span data-language="sviat_promo_page__promo_products">{$lang->sviat_promo_page__promo_products}</span>
                </div>
            </div>

            <div class="block__body">
                <div class="products_list row">
                    {foreach $promo->products as $p}
                        <div class="product_item col-xs-6 col-sm-2 col-md-4 col-lg-4 col-xl-3">
                            {include "product_list.tpl" product = $p}
                        </div>
                    {/foreach}
                </div>
            </div>
        </div>
        {* Friendly URLs Pagination *}
        <div class="fn_pagination products_pagination">
            {include file='pagination.tpl'}
        </div>
    {/if}
</div>

