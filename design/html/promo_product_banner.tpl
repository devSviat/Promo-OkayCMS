<a href="{url_generator route='sviat_promo_page' url=$promo->url}" class="sviat-promo-caption-banner{if $banner_extra_class|default:'' != ''} {$banner_extra_class|escape}{/if}">
                <img src="{$promo->caption_banner_image|resize:($promo->caption_banner_width|default:800):($promo->caption_banner_height|default:80):false:$config->resized_promo_images_dir}" width="{$promo->caption_banner_width|default:800|escape}" height="{$promo->caption_banner_height|default:80|escape}" alt="{$promo->name|escape}" title="{$promo->name|escape}"/>
            </a>
