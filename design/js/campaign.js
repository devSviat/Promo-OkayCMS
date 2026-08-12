/* CSRF-токен вітрини. Куку okay_csrf сервер навмисно лишає доступною скриптам.

   Визначення одне на всю сторінку: тема вже віддає okayCsrfToken(), і тоді
   виграє воно — зміниш ім'я куки чи формат у темі, і всі модулі підуть за ним.
   Власне визначення тут потрібне лише для чужої теми, яка цієї функції не має. */
window.okayCsrfToken = window.okayCsrfToken || function () {
    var match = document.cookie.match(/(?:^|;\s*)okay_csrf=([0-9a-f]{64})/);
    return match ? match[1] : "";
};

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

    /* Віджет подарунка не обовʼязково лежить у тому самому .details_boxed__item,
       що й форма: модифікація модуля вставляє його СУСІДНІМ блоком перед нею.
       Тому йдемо вгору по предках — але не далі межі одного товару.

       Межа визначається не класом, а складом: щойно предок містить більш ніж
       одну форму купівлі, ми вже піднялись у список товарів, і будь-який
       знайдений там подарунок належить сусідній картці. На сторінці товару
       форма одна, тож обхід доходить до спільного блоку й знаходить віджет. */
    var $gift = $();
    $form.parents().each(function () {
        var $ancestor = $(this);

        if ($ancestor.find(".fn_variants").length > 1) {
            return false;
        }

        var $found = $ancestor.find(".fn_gift.selected").first();
        if ($found.length) {
            $gift = $found;
            return false;
        }
    });

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
            promo_id: promoId,
            customer_csrf_token: okayCsrfToken()
        },
        dataType: "json"
    }).fail(function (xhr) {
        /* Запит побічний до купівлі: товар усе одно кладеться в кошик, тож
           зупиняти покупця не можна. Але мовчати теж не варто — без цього
           рядка втрачений вибір подарунка не видно ніде, а сервер підставить
           перший подарунок кампанії замість обраного. */
        if (window.console && console.warn) {
            console.warn("Sviat/Promo: вибір подарунка не збережено, HTTP " + xhr.status);
        }
    });
});
