$(function () {
    function pad2(v) {
        return v < 10 ? "0" + v : String(v);
    }

    function renderTimer($timer, totalSeconds) {
        var sec = Math.max(0, parseInt(totalSeconds, 10) || 0);
        var days = Math.floor(sec / 86400);
        sec -= days * 86400;
        var hours = Math.floor(sec / 3600);
        sec -= hours * 3600;
        var minutes = Math.floor(sec / 60);
        sec -= minutes * 60;

        function setAnimatedValue(selector, value) {
            var $el = $timer.find(selector);
            if (!$el.length) {
                return;
            }

            var next = pad2(value);
            var prev = String($el.data("value") || "");
            if (prev === next) {
                return;
            }

            $el.data("value", next);
            var prevChars = prev.split("");
            var nextChars = next.split("");

            if (prevChars.length !== nextChars.length) {
                prevChars = [];
            }

            var html = "";
            for (var i = 0; i < nextChars.length; i++) {
                var changed = prevChars[i] !== nextChars[i];
                if (changed && prevChars[i] !== undefined) {
                    html += '' +
                        '<span class="promo_timer__digit is-ticking">' +
                        '<span class="promo_timer__digit_old">' + prevChars[i] + '</span>' +
                        '<span class="promo_timer__digit_new">' + nextChars[i] + '</span>' +
                        '</span>';
                } else {
                    html += '<span class="promo_timer__digit">' + nextChars[i] + "</span>";
                }
            }
            $el.html(html);
        }

        setAnimatedValue(".fn_timer_days", days);
        setAnimatedValue(".fn_timer_hours", hours);
        setAnimatedValue(".fn_timer_minutes", minutes);
        setAnimatedValue(".fn_timer_seconds", sec);
    }

    $(".fn_promo_timer").each(function () {
        var $timer = $(this);
        var left = parseInt($timer.data("seconds-left"), 10) || 0;
        renderTimer($timer, left);

        if (left <= 0) {
            return;
        }

        var t = setInterval(function () {
            left -= 1;
            renderTimer($timer, left);
            if (left <= 0) {
                clearInterval(t);
            }
        }, 1000);
    });
});

$(document).on("click", ".fn_gift", function () {
    $(".fn_gift").removeClass("selected");
    $(this).addClass("selected");
});

$(document).on("submit", ".fn_variants", function () {
    var $form = $(this);
    var $gift = $form.closest(".details_boxed__item").find(".fn_gift.selected").first();
    if (!$gift.length) {
        return;
    }

    var product = $gift.data("product_id");
    var giftProduct = $gift.data("gift_id");
    var giftVariant = $gift.data("gift_variant_id");
    var promoId = $gift.data("promo_id");
    var variant;

    if ($form.find("select[name=variant]").length > 0) {
        variant = $form.find("select[name=variant]").val();
    } else {
        variant = $gift.data("variant_id");
    }

    if (!(product && variant && giftProduct && giftVariant && promoId)) {
        return;
    }

    $.ajax({
        url: okay.router["sviat_ajax_promo_cart"],
        type: "POST",
        data: {
            product: product,
            gift_product: giftProduct,
            variant: variant,
            gift_variant: giftVariant,
            promo_id: promoId
        },
        dataType: "json"
    });
});
