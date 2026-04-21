{if $product->sviat_promo_badge_image}
    <span class="sticker sticker--sviat-promo">
        <img class="sticker__image" src="{$product->sviat_promo_badge_image|resize:64:64:false:$config->resized_promo_images_dir}" alt="" />
    </span>
{/if}
