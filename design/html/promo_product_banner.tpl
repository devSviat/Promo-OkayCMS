<a href="{url_generator route='sviat_promo_page' url=$promo->url}" class="sviat-promo-caption-banner{if $banner_extra_class|default:'' != ''} {$banner_extra_class|escape}{/if}">
                <img src="{$promo->caption_banner_image|resize:800:80:false:$config->resized_promo_images_dir}" alt="{$promo->name|escape}" title="{$promo->name|escape}"/>
            </a>
