{if $promo->has_date_range && $promo->seconds_left > 0 }
    <div class="promo_timer fn_promo_timer" data-seconds-left="{$promo->seconds_left|escape}">
        <div class="promo_timer__title" data-lang="time_left">{$lang->sviat_promo__time_left}</div>
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
            {/if}
