{* The canonical address of the page *}
{$canonical="{url_generator route="sviat_promo_list" absolute=1}" scope=global}

<div class="block">
    {* The page heading *}
    <div class="block__header block__header--boxed block__header--border">
        <h1 class="block__heading">
            {if isset($page) && $page}
            <span data-page="{$page->id}">{if $page->name_h1|escape}{$page->name_h1|escape}{else}{$page->name|escape}{/if}</span>
            {else}
            <span>{$lang->sviat_promo__list_title|escape}</span>
            {/if}
        </h1>
    </div>

    {* The list of the promos *}
    <div class="d-flex flex-column">
        <div class="blog_container__boxed">
            <div class="article_list f_row">
                {foreach $promos as $promo}
                    <div class="article_item f_col-sm-6 f_col-md-4 f_col-lg-4">
                        <div class="article__preview">
                            <div class="article__body">
                                <div class="article__image{if $promo->is_expired} promo__image_wrap--expired{/if}{if $promo->is_upcoming} promo__image_wrap--upcoming{/if}">
                                    <a class="article__image_link" aria-label="{$promo->name|escape}"
                                       href="{url_generator route='sviat_promo_page' url=$promo->url}">
                                        {if $promo->image}
                                            <img class="promo-catalog-image" src="{$promo->image|resize:520:240:false:$config->resized_promo_images_dir:center:center}" width="520" height="240"
                                                 alt="{$promo->name|escape}" title="{$promo->name|escape}"/>
                                        {else}
                                            <div class="article__no_image d-flex align-items-start">
                                                {include file="svg.tpl" svgId="no_image"}
                                            </div>
                                        {/if}
                                    </a>
                                    {if $promo->is_expired}
                                        <div class="promo__expired_overlay" aria-hidden="true">
                                            {$lang->sviat_promo__promo_expired|escape}
                                        </div>
                                    {elseif $promo->is_upcoming}
                                        <div class="promo__upcoming_overlay" aria-hidden="true">
                                            {$lang->sviat_promo__upcoming|escape}
                                        </div>
                                    {/if}
                                </div>

                                <a class="article__title theme_link--color"
                                   href="{url_generator route='sviat_promo_page' url=$promo->url}">{$promo->name|escape}</a>

                                <div class="article__info">
                                    {if $promo->has_date_range}
                                        <div class="article__info_item promo">
                                            {if $promo->is_expired}
                                                <div class="promos_expired"
                                                     data-lang="sviat_promo_page__promo_expired">{$lang->sviat_promo_page__promo_expired}</div>
                                            {elseif $promo->is_upcoming}
                                                <div class="promos_date promos_date--upcoming">
                                                    <span>{$lang->sviat_promo__starts|escape} {$promo->date_start|date}</span>
                                                    {if $promo->days_to_start > 0}
                                                        <span class="promo__starts_hint">
                                                            {$lang->sviat_promo__in|escape} {$promo->days_to_start} {$promo->days_to_start|plural:'день':'днів':'дні'}
                                                        </span>
                                                    {/if}
                                                </div>
                                            {else}
                                                <div class="promos_date">
                                                    <span>{$promo->date_start|date} - {$promo->date_end|date}</span>
                                                </div>
                                            {/if}
                                        </div>
                                    {/if}
                                    {if $promo->has_date_range && !$promo->is_expired && !$promo->is_upcoming && $promo->days_left > 0}
                                        <div class="article__info_item promo">
                                            <div class="days_left">
                                                {$promo->days_left|plural:'Залишився':'Залишилось':'Залишилось'} {$promo->days_left} {$promo->days_left|plural:'день':'днів':'дні'}
                                            </div>
                                        </div>
                                    {/if}
                                </div>

                                {if $promo->annotation}
                                    <div class="article__annotation">{$promo->annotation}</div>
                                {/if}
                            </div>
                        </div>
                    </div>
                {/foreach}
            </div>
        </div>
    </div>
    {if isset($description) && $description}
    <div class="block block--boxed block--border">
        <div class="block__description">{$description}</div>
    </div>
    {/if}
</div>
