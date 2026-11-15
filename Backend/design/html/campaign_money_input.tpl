{* Числове поле форми акції. Значення DECIMAL-колонки йде через sviat_promo_money_input: 0 → порожньо з placeholder, 500.0000 → 500. *}
<input class="form-control{if $class} {$class|escape}{/if}" name="{$name|escape}" type="number" min="{$min|escape}"{if $max} max="{$max|escape}"{/if} step="0.01" value="{$value|sviat_promo_money_input|escape}" placeholder="{$placeholder|escape}"/>
