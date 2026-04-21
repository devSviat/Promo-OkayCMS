{* The canonical address of the page *}
{$canonical="{url_generator route="promos_gift" absolute=1}" scope=global}

<div class="block">
    {* The page heading *}
    <div class="block__header block__header--boxed block__header--border">
        <h1 class="block__heading">
            <span data-page="{$page->id}">{if $page->name_h1|escape}{$page->name_h1|escape}{else}{$page->name|escape}{/if}</span>
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
                                <div class="article__image">
                                    <a class="article__image_link" aria-label="{$promo->name|escape}"
                                       href="{url_generator route='promo_gift' url=$promo->url}">
                                        {if $promo->image}
                                            <img src="{$promo->image|resize:380:240:false:$config->resized_promo_gift_images_dir:center:center}"
                                                 alt="{$promo->name|escape}" title="{$promo->name|escape}"/>
                                        {else}
                                            <div class="article__no_image d-flex align-items-start">
                                                {include file="svg.tpl" svgId="no_image"}
                                            </div>
                                        {/if}
                                    </a>
                                </div>

                                <a class="article__title theme_link--color"
                                   href="{url_generator route='promo_gift' url=$promo->url}">{$promo->name|escape}</a>

                                <div class="article__info">
                                    {if $promo->has_date_range}
                                        <div class="article__info_item promo">
                                            {if $promo->days_left > 0}
                                                <div class="promos_date">
                                                    <span>{$promo->date_start|date} - {$promo->date_end|date}</span>
                                                </div>
                                            {else}
                                                <div class="promos_expired"
                                                     data-lang="sviat_promo_page__promo_expired">{$lang->sviat_promo_page__promo_expired}</div>
                                            {/if}
                                        </div>
                                    {/if}
                                    {if $promo->has_date_range && $promo->days_left > 0}
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
    {* The page content *}
    <div class="block block--boxed block--border">
        <div class="block__description">{$description}</div>
    </div>
    {* Pagination *}
    {include file='pagination.tpl'}
</div>
