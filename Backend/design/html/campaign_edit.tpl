{if $promo->id}
    {$meta_title = $promo->name scope=global}
{else}
    {$meta_title = $btr->sviat_promo__add  scope=global}
{/if}
<div class="main_header">
    <div class="main_header__item">
        <div class="main_header__inner">
            <div class="box_heading heading_page">
                {if !$promo->id}{$btr->sviat_promo__add|escape}{else}{$promo->name|escape}{/if}
            </div>
        </div>
    </div>
    <div class="main_header__item">
        <div class="main_header__inner">
            {if $promo->id && !empty($promo->url)}
                <a class="btn btn_small btn-info" target="_blank" href="{url_generator route='sviat_promo_page' url=$promo->url absolute=1}">
                    {include file='svg_icon.tpl' svgId='icon_desktop'}
                    <span>{$btr->general_open|escape}</span>
                </a>
            {/if}
            <a class="btn btn_small btn_border-info ml-1" href="{if $smarty.get.return}{$smarty.get.return|escape}{else}{url controller=CampaignListAdmin}{/if}">
                {include file='svg_icon.tpl' svgId='return'}
                <span>{$btr->general_back|escape}</span>
            </a>
        </div>
    </div>
</div>

{if $message_success}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="alert alert--center alert--icon alert--success">
                <div class="alert__content">
                    <div class="alert__title">
                        {if $message_success=='added'}
                            {$btr->sviat_promo__added|escape}
                        {elseif $message_success=='updated'}
                            {$btr->sviat_promo__updated|escape}
                        {else}
                            {$message_success|escape}
                        {/if}
                    </div>
                </div>
                {if $smarty.get.return}
                    <a class="alert__button" href="{$smarty.get.return}">
                        {include file='svg_icon.tpl' svgId='return'}
                        <span>{$btr->general_back|escape}</span>
                    </a>
                {/if}
            </div>
        </div>
    </div>
{/if}

{if $message_error}
<div class="row">
    <div class="col-lg-12 col-md-12 col-sm-12">
        <div class="alert alert--center alert--icon alert--error">
            <div class="alert__content">
                <div class="alert__title">
                    {if $message_error == 'url_exists'}
                        {$btr->sviat_promo__exists|escape}
                    {elseif $message_error=='empty_name'}
                        {$btr->general_enter_title|escape}
                    {elseif $message_error == 'empty_url'}
                        {$btr->general_enter_url|escape}
                    {elseif $message_error == 'url_wrong'}
                        {$btr->general_not_underscore|escape}
                    {elseif $message_error == 'empty_promo_gifts'}
                        {$btr->sviat_promo__empty_promo_gifts|escape}
                    {elseif $message_error == 'empty_discount_percent'}
                        {$btr->sviat_promo__empty_discount_percent|escape}
                    {elseif $message_error == 'empty_discount_fixed'}
                        {$btr->sviat_promo__empty_discount_fixed|escape}
                    {elseif $message_error == 'empty_promo_objects'}
                        {$btr->sviat_promo__empty_promo_objects|escape}
                    {else}
                        {$message_error|escape}
                    {/if}
                </div>
            </div>
        </div>
    </div>
</div>
{/if}

<form method="post" enctype="multipart/form-data" class="fn_fast_button">
    <input type="hidden" name="session_id" value="{$smarty.session.id}" />
    <input type="hidden" name="lang_id" value="{$lang_id}" />

    <div class="row">
        <div class="col-xs-12">
            <div class="boxed match_matchHeight_true fn_toggle_wrap">

                {* ── Заголовок: назва секції + перемикач «Активна» напроти + згортання ── *}
                <div class="heading_box heading_box--switch-right">
                    <span>{$btr->sviat_promo__section_main|escape}</span>
                    <label class="switch switch-default">
                        <input type="hidden" name="visible" value="0"/>
                        <input class="switch-input" name="visible" value="1" type="checkbox" {if $promo->visible}checked=""{/if}/>
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </label>
                    <div class="toggle_arrow_wrap fn_toggle_card text-primary">
                        <a class="btn-minimize" href="javascript:;"><i class="fa fn_icon_arrow fa-angle-down"></i></a>
                    </div>
                </div>

                <div class="toggle_body_wrap on fn_card">

                    {* ── Рядок 1: Назва/URL ── *}
                    <div class="row">
                        <div class="col-lg-6 col-md-12 col-sm-12">
                            <div class="heading_label">{$btr->general_name|escape}</div>
                            <input class="form-control" name="name" type="text" value="{$promo->name|escape}"/>
                            <input name="id" type="hidden" value="{$promo->id|escape}"/>
                        </div>
                        <div class="col-lg-6 col-md-12 col-sm-12">
                            <div class="heading_label">URL</div>
                            <div class="input-group input-group--dabbl">
                                <span class="input-group-addon input-group-addon--left">URL</span>
                                <input name="url" class="fn_meta_field form-control fn_url {if $promo->id}fn_disabled{/if}" {if $promo->id}readonly=""{/if} type="text" value="{$promo->url|escape}" />
                                <input type="checkbox" id="block_translit" class="hidden" value="1" {if $promo->id}checked=""{/if}>
                                <span class="input-group-addon fn_disable_url">
                                    {if $promo->id}
                                        <i class="fa fa-lock"></i>
                                    {else}
                                        <i class="fa fa-lock fa-unlock"></i>
                                    {/if}
                                </span>
                            </div>
                        </div>
                    </div>

                    {* ── Рядок 3: Тип акції + Поле знижки або підказка (залежно від типу) ── *}
                    <div class="row mt-1" style="align-items:flex-end;">
                        <div class="col-lg-3 col-md-6 col-sm-12 mb-1 mb-lg-0">
                            <div class="heading_label">{$btr->sviat_promo__type|escape}</div>
                            <select name="promo_type" id="fn_promo_type_select" class="selectpicker form-control fn_promo_type_select">
                                {foreach $promo_types as $ptype => $label}
                                    <option value="{$ptype|escape}" {if $promo->promo_type == $ptype}selected{/if}>{$label|escape}</option>
                                {/foreach}
                            </select>
                        </div>
                    <div class="fn_promo_type_section" data-show-for="percent"{if ($promo->promo_type|default:'percent') != 'percent'} style="display:none"{/if}>
                        <div class="col-lg-3 col-md-6 col-sm-12">
                            <div class="heading_label">{$btr->sviat_promo__discount_percent|escape}</div>
                            <input class="form-control fn_discount_percent" name="discount_percent" type="number" min="1" max="100" step="0.01" value="{$promo->discount_percent|escape}" placeholder="%"/>
                        </div>
                    </div>
                    <div class="fn_promo_type_section" data-show-for="fixed"{if ($promo->promo_type|default:'percent') != 'fixed'} style="display:none"{/if}>
                        <div class="col-lg-3 col-md-6 col-sm-12">
                            <div class="heading_label">{$btr->sviat_promo__discount_fixed|escape}</div>
                            <input class="form-control fn_discount_fixed" name="discount_fixed" type="number" min="0.01" step="0.01" value="{$promo->discount_fixed|escape}"/>
                        </div>
                    </div>
                    <div class="fn_promo_type_section" data-show-for="bundle_3x2"{if ($promo->promo_type|default:'percent') != 'bundle_3x2'} style="display:none"{/if}>
                        <div class="col-lg-8 col-md-12">
                            <p class="text_grey text_13 mb-0">{$btr->sviat_promo__hint_bundle|escape}</p>
                        </div>
                    </div>
                    <div class="fn_promo_type_section" data-show-for="free_shipping"{if ($promo->promo_type|default:'percent') != 'free_shipping'} style="display:none"{/if}>
                        <div class="col-lg-8 col-md-12">
                            <p class="text_grey text_13 mb-0">{$btr->sviat_promo__hint_free_shipping|escape}</p>
                        </div>
                    </div>

                    </div>

                    {* ── Рядок 4: Діапазон дат ── *}
                    <div class="row mt-1 fn_date_range_wrap">
                        <div class="col-lg-3 col-md-6 col-sm-12 mb-1 mb-lg-0">
                            <div class="heading_label">{$btr->sviat_promo__min_order|escape}</div>
                            <input class="form-control" name="min_order_amount" type="number" value="{$promo->min_order_amount|default:0|escape}"/>
                        </div>
                        <div class="col-lg-3 col-md-4 col-sm-6 col-xs-12 mb-1 mb-md-0">
                            <div class="heading_label">{$btr->sviat_promo__date_range|escape}</div>
                            <select name="has_date_range" id="fn_has_date_range" class="selectpicker form-control fn_date_range">
                                <option value='0'{if $promo->has_date_range == '0'} selected{/if}>{$btr->settings_general_turn_off|escape}</option>
                                <option value='1'{if $promo->has_date_range == '1'} selected{/if}>{$btr->settings_general_turn_on|escape}</option>
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-4 col-sm-6 col-xs-12 fn_date_range_dates mb-1 mb-sm-0"{if !$promo->has_date_range} style="display:none"{/if}>
                            <div class="heading_label">{$btr->sviat_promo__date_start|escape}</div>
                            <input name="date_start" class="form-control" type="datetime-local" value="{$date_start_local|escape}" />
                        </div>
                        <div class="col-lg-3 col-md-4 col-sm-6 col-xs-12 fn_date_range_dates"{if !$promo->has_date_range} style="display:none"{/if}>
                            <div class="heading_label">{$btr->sviat_promo__date_end|escape}</div>
                            <input name="date_end" class="form-control" type="datetime-local" value="{$date_end_local|escape}" />
                        </div>
                    </div>

                </div>{* /toggle_body_wrap *}
            </div>
        </div>
    </div>

    {* Область дії: включення + виключення *}
    {function name=promo_scope_product_list mode='include'}
        <div class="fn_promo_product_area" data-mode="{$mode}">
            <div class="okay_list">
                <div class="okay_list_body fn_promo_scope_products_{$mode}">
                    {foreach $promo_objects[$mode]['product'] as $promo_product}
                        <div class="fn_row okay okay_list_body_item">
                            <div class="okay_list_row">
                                <div class="okay_list_boding okay_list_related_photo">
                                    <input type="hidden" name="promo_objects[{$mode}][product][]" value="{$promo_product->id}">
                                    <a href="{url controller=ProductAdmin id=$promo_product->id}">
                                        {if $promo_product->images[0]}
                                            <img class="product_icon" src="{$promo_product->images[0]->filename|resize:40:40}">
                                        {else}
                                            <img class="product_icon" src="design/images/no_image.png" width="40">
                                        {/if}
                                    </a>
                                </div>
                                <div class="okay_list_boding okay_list_related_name">
                                    <a class="link" href="{url controller=ProductAdmin id=$promo_product->id}">{$promo_product->name|escape}</a>
                                </div>
                                <div class="okay_list_boding okay_list_close">
                                    <button type="button" class="btn_close fn_remove_item hint-bottom-right-t-info-s-small-mobile hint-anim">
                                        {include file='svg_icon.tpl' svgId='delete'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    {/foreach}
                </div>
            </div>
            <div class="fn_promo_scope_new_row_{$mode}" style="display:none;">
                <div class="fn_row okay okay_list_body_item">
                    <div class="okay_list_row">
                        <div class="okay_list_boding okay_list_related_photo">
                            <input type="hidden" name="promo_objects[{$mode}][product][]" value="">
                            <img class="product_icon" src="">
                        </div>
                        <div class="okay_list_boding okay_list_related_name">
                            <a class="link fn_promo_scope_product_name" href=""></a>
                        </div>
                        <div class="okay_list_boding okay_list_close">
                            <button type="button" class="btn_close fn_remove_item hint-bottom-right-t-info-s-small-mobile hint-anim">
                                {include file='svg_icon.tpl' svgId='delete'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="autocomplete_arrow mt-h">
                <input type="text" class="fn_promo_scope_autocomplete form-control" data-mode="{$mode}" placeholder="{$btr->general_add_product|escape}">
            </div>
        </div>
    {/function}

    <div class="row mt-1">
        <div class="col-lg-12 col-md-12">
            <div class="boxed fn_toggle_wrap">
                <div class="heading_box">
                    {$btr->sviat_promo__section_scope|escape}
                    <div class="toggle_arrow_wrap fn_toggle_card text-primary">
                        <a class="btn-minimize" href="javascript:;"><i class="fa fn_icon_arrow fa-angle-down"></i></a>
                    </div>
                </div>
                <div class="toggle_body_wrap on fn_card">
                    <p class="text_grey text_13 mb-1">{$btr->sviat_promo__hint_scope|escape}</p>
                    <div class="row">

                        {* ── Включення ── *}
                        <div class="col-lg-6 col-md-12 mb-2 mb-lg-0">
                            <div class="boxed">
                                <div class="heading_box mb-q">{$btr->sviat_promo__scope_inclusions|escape}</div>
                                <div class="row">
                                    <div class="col-md-12 mb-1">
                                        <div class="heading_label">{$btr->general_categories|escape}</div>
                                        <select class="selectpicker col-xs-12 px-0" multiple name="promo_objects[include][category][]" size="7" data-selected-text-format="count" data-actions-box="true" data-live-search="true">
                                            {function name=promo_cat_inc level=0}
                                                {foreach $categories as $category}
                                                    <option value="{$category->id}"{if in_array($category->id, $promo_objects['include']['category'])} selected{/if}>{section name=sp loop=$level}&nbsp;&nbsp;&nbsp;&nbsp;{/section}{$category->name}</option>
                                                    {promo_cat_inc categories=$category->subcategories level=$level+1}
                                                {/foreach}
                                            {/function}
                                            {promo_cat_inc categories=$categories}
                                        </select>
                                    </div>
                                    <div class="col-md-12 mb-1">
                                        <div class="heading_label">{$btr->brands_brands|escape}</div>
                                        <select class="selectpicker col-xs-12 px-0" multiple name="promo_objects[include][brand][]" size="5" data-selected-text-format="count" data-actions-box="true" data-live-search="true">
                                            {foreach $brands as $brand}
                                                <option value="{$brand->id}"{if in_array($brand->id, $promo_objects['include']['brand'])} selected{/if}>{$brand->name}</option>
                                            {/foreach}
                                        </select>
                                    </div>
                                    <div class="col-md-12 mb-1">
                                        <div class="heading_label">{$btr->sviat_promo__features|escape}</div>
                                        <div class="fn_feature_filter_rows" id="sv_feature_rows_include">
                                            {foreach $promo_objects.include.feature_value as $featureId => $selectedValueIds}
                                            <div class="fn_feature_row mb-h" data-mode="include">
                                                <div class="row align-items-start">
                                                    <div class="col-sm-5 mb-h">
                                                        <select class="selectpicker form-control fn_feature_type_sel"
                                                                data-live-search="true" data-size="8">
                                                            <option value="">{$btr->sviat_promo__feature_select|escape}</option>
                                                            {foreach $features as $f}
                                                            <option value="{$f->id}"{if $f->id == $featureId} selected{/if}>{$f->name|escape}</option>
                                                            {/foreach}
                                                        </select>
                                                    </div>
                                                    <div class="col-sm-6 fn_feature_values_wrap mb-h">
                                                        <select class="selectpicker col-xs-12 px-0" multiple
                                                                name="promo_objects[include][feature_value][{$featureId}][]"
                                                                size="5" data-selected-text-format="count" data-live-search="true">
                                                            {foreach from=$feature_values_map[$featureId]|default:[] item=fv}
                                                            <option value="{$fv->id}"{if in_array($fv->id, $selectedValueIds)} selected{/if}>{$fv->value|escape}</option>
                                                            {/foreach}
                                                        </select>
                                                    </div>
                                                    <div class="col-sm-1 mb-h">
                                                        <button type="button" class="btn btn-danger btn-sm fn_remove_feature_row">&#xD7;</button>
                                                    </div>
                                                </div>
                                            </div>
                                            {/foreach}
                                        </div>
                                        <button type="button" class="btn btn-sm btn-default mt-h fn_add_feature_row"
                                                data-mode="include" data-target="sv_feature_rows_include">
                                            + {$btr->sviat_promo__add_feature_row|escape}
                                        </button>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="heading_label">{$btr->general_products|escape}</div>
                                        {promo_scope_product_list mode='include'}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {* ── Виключення ── *}
                        <div class="col-lg-6 col-md-12">
                            <div class="boxed">
                                <div class="heading_box mb-q">
                                    {$btr->sviat_promo__scope_exclusions|escape}
                                    <i class="fn_tooltips ml-h" title="{$btr->sviat_promo__scope_exclusions_hint|escape}">
                                        {include file='svg_icon.tpl' svgId='icon_tooltips'}
                                    </i>
                                </div>
                                <div class="row">
                                    <div class="col-md-12 mb-1">
                                        <div class="heading_label">{$btr->general_categories|escape}</div>
                                        <select class="selectpicker col-xs-12 px-0" multiple name="promo_objects[exclude][category][]" size="7" data-selected-text-format="count" data-actions-box="true" data-live-search="true">
                                            {function name=promo_cat_exc level=0}
                                                {foreach $categories as $category}
                                                    <option value="{$category->id}"{if in_array($category->id, $promo_objects['exclude']['category'])} selected{/if}>{section name=sp loop=$level}&nbsp;&nbsp;&nbsp;&nbsp;{/section}{$category->name}</option>
                                                    {promo_cat_exc categories=$category->subcategories level=$level+1}
                                                {/foreach}
                                            {/function}
                                            {promo_cat_exc categories=$categories}
                                        </select>
                                    </div>
                                    <div class="col-md-12 mb-1">
                                        <div class="heading_label">{$btr->brands_brands|escape}</div>
                                        <select class="selectpicker col-xs-12 px-0" multiple name="promo_objects[exclude][brand][]" size="5" data-selected-text-format="count" data-actions-box="true" data-live-search="true">
                                            {foreach $brands as $brand}
                                                <option value="{$brand->id}"{if in_array($brand->id, $promo_objects['exclude']['brand'])} selected{/if}>{$brand->name}</option>
                                            {/foreach}
                                        </select>
                                    </div>
                                    <div class="col-md-12 mb-1">
                                        <div class="heading_label">{$btr->sviat_promo__features|escape}</div>
                                        <div class="fn_feature_filter_rows" id="sv_feature_rows_exclude">
                                            {foreach $promo_objects.exclude.feature_value as $featureId => $selectedValueIds}
                                            <div class="fn_feature_row mb-h" data-mode="exclude">
                                                <div class="row align-items-start">
                                                    <div class="col-sm-5 mb-h">
                                                        <select class="selectpicker form-control fn_feature_type_sel"
                                                                data-live-search="true" data-size="8">
                                                            <option value="">{$btr->sviat_promo__feature_select|escape}</option>
                                                            {foreach $features as $f}
                                                            <option value="{$f->id}"{if $f->id == $featureId} selected{/if}>{$f->name|escape}</option>
                                                            {/foreach}
                                                        </select>
                                                    </div>
                                                    <div class="col-sm-6 fn_feature_values_wrap mb-h">
                                                        <select class="selectpicker col-xs-12 px-0" multiple
                                                                name="promo_objects[exclude][feature_value][{$featureId}][]"
                                                                size="5" data-selected-text-format="count" data-live-search="true">
                                                            {foreach from=$feature_values_map[$featureId]|default:[] item=fv}
                                                            <option value="{$fv->id}"{if in_array($fv->id, $selectedValueIds)} selected{/if}>{$fv->value|escape}</option>
                                                            {/foreach}
                                                        </select>
                                                    </div>
                                                    <div class="col-sm-1 mb-h">
                                                        <button type="button" class="btn btn-danger btn-sm fn_remove_feature_row">&#xD7;</button>
                                                    </div>
                                                </div>
                                            </div>
                                            {/foreach}
                                        </div>
                                        <button type="button" class="btn btn-sm btn-default mt-h fn_add_feature_row"
                                                data-mode="exclude" data-target="sv_feature_rows_exclude">
                                            + {$btr->sviat_promo__add_feature_row|escape}
                                        </button>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="heading_label">{$btr->general_products|escape}</div>
                                        {promo_scope_product_list mode='exclude'}
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-1">
        <div class="col-lg-12 col-md-12">
            <div class="boxed fn_toggle_wrap">
                <div class="heading_box">
                    {$btr->sviat_promo__section_images|escape}
                    <div class="toggle_arrow_wrap fn_toggle_card text-primary">
                        <a class="btn-minimize" href="javascript:;" ><i class="fa fn_icon_arrow fa-angle-down"></i></a>
                    </div>
                </div>
                <div class="toggle_body_wrap on fn_card">
                    <div class="row">
                        <div class="col-lg-3 col-md-12 mb-3 mb-lg-0">
                            <div class="heading_label">
                                <span>{$btr->sviat_promo__col_main_image|escape}</span>
                                <i class="fn_tooltips" title="{$btr->sviat_promo__main_image_hint|escape}">
                                    {include file='svg_icon.tpl' svgId='icon_tooltips'}
                                </i>
                            </div>
                            <ul class="category_images_list">
                                <li class="category_image_item fn_image_block">
                                    {if $promo->image}
                                        <input type="hidden" class="fn_accept_delete" name="delete_image" value="">
                                        <div class="fn_parent_image">
                                            <div class="category_image image_wrapper fn_image_wrapper text-xs-center">
                                                <a href="javascript:;" class="fn_delete_item remove_image"></a>
                                                <img src="{$promo->image|resize:300:120:false:$config->resized_promo_images_dir}" alt="" />
                                            </div>
                                        </div>
                                    {else}
                                        <div class="fn_parent_image"></div>
                                    {/if}
                                    <div class="fn_upload_image dropzone_block_image {if $promo->image} hidden{/if}">
                                        <i class="fa fa-plus font-5xl" aria-hidden="true"></i>
                                        <input class="dropzone_image" name="image" type="file" />
                                    </div>
                                    <div class="category_image image_wrapper fn_image_wrapper fn_new_image text-xs-center hidden">
                                        <a href="javascript:;" class="fn_delete_item remove_image"></a>
                                        <img src="" alt="" />
                                    </div>
                                </li>
                            </ul>
                            <div class="heading_label mt-q">{$btr->sviat_promo__image_size_label|escape}</div>
                            <div class="banner_group__inputs mt-q">
                                <div class="banner_group__input">
                                    <div class="input-group">
                                        <input name="image_width" class="form-control" type="text" value="{$promo->image_width|default:1350|escape}" placeholder="1350" />
                                        <span class="input-group-addon">px</span>
                                    </div>
                                </div>
                                <div class="banner_group__input">
                                    <div class="input-group">
                                        <input name="image_height" class="form-control" type="text" value="{$promo->image_height|default:400|escape}" placeholder="400" />
                                        <span class="input-group-addon">px</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-12 mb-3 mb-lg-0">
                            <div class="heading_label">
                                <span>{$btr->sviat_promo__mobile_image|escape}</span>
                                <i class="fn_tooltips" title="{$btr->sviat_promo__mobile_image_hint|escape}">
                                    {include file='svg_icon.tpl' svgId='icon_tooltips'}
                                </i>
                            </div>
                            <ul class="category_images_list">
                                <li class="category_image_item fn_image_block">
                                    {if $promo->image_mobile}
                                        <input type="hidden" class="fn_accept_delete" name="delete_image_mobile" value="">
                                        <div class="fn_parent_image">
                                            <div class="category_image image_wrapper fn_image_wrapper text-xs-center">
                                                <a href="javascript:;" class="fn_delete_item remove_image"></a>
                                                <img src="{$promo->image_mobile|resize:300:120:false:$config->resized_promo_images_dir}" alt="" />
                                            </div>
                                        </div>
                                    {else}
                                        <div class="fn_parent_image"></div>
                                    {/if}
                                    <div class="fn_upload_image dropzone_block_image {if $promo->image_mobile} hidden{/if}">
                                        <i class="fa fa-plus font-5xl" aria-hidden="true"></i>
                                        <input class="dropzone_image" name="image_mobile" type="file" accept="image/*,.svg" />
                                    </div>
                                    <div class="category_image image_wrapper fn_image_wrapper fn_new_image text-xs-center hidden">
                                        <a href="javascript:;" class="fn_delete_item remove_image"></a>
                                        <img src="" alt="" />
                                    </div>
                                </li>
                            </ul>
                            <div class="heading_label mt-q">{$btr->sviat_promo__image_size_label|escape}</div>
                            <div class="banner_group__inputs mt-q">
                                <div class="banner_group__input">
                                    <div class="input-group">
                                        <input name="image_mobile_width" class="form-control" type="text" value="{$promo->image_mobile_width|default:1350|escape}" placeholder="1350" />
                                        <span class="input-group-addon">px</span>
                                    </div>
                                </div>
                                <div class="banner_group__input">
                                    <div class="input-group">
                                        <input name="image_mobile_height" class="form-control" type="text" value="{$promo->image_mobile_height|default:400|escape}" placeholder="400" />
                                        <span class="input-group-addon">px</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-12 mb-3 mb-lg-0">
                            <div class="heading_label">
                                <span>{$btr->sviat_promo__badge_image|escape}</span>
                                <i class="fn_tooltips" title="{$btr->sviat_promo__badge_image_hint|escape}">
                                    {include file='svg_icon.tpl' svgId='icon_tooltips'}
                                </i>
                            </div>
                            <ul class="category_images_list">
                                <li class="category_image_item fn_image_block">
                                    {if $promo->badge_image}
                                        <input type="hidden" class="fn_accept_delete" name="delete_badge_image" value="">
                                        <div class="fn_parent_image">
                                            <div class="category_image image_wrapper fn_image_wrapper text-xs-center">
                                                <a href="javascript:;" class="fn_delete_item remove_image"></a>
                                                <img src="{$promo->badge_image|resize:120:120:false:$config->resized_promo_images_dir}" alt="" />
                                            </div>
                                        </div>
                                    {else}
                                        <div class="fn_parent_image"></div>
                                    {/if}
                                    <div class="fn_upload_image dropzone_block_image {if $promo->badge_image} hidden{/if}">
                                        <i class="fa fa-plus font-5xl" aria-hidden="true"></i>
                                        <input class="dropzone_image" name="badge_image" type="file" accept="image/*,.svg" />
                                    </div>
                                    <div class="category_image image_wrapper fn_image_wrapper fn_new_image text-xs-center hidden">
                                        <a href="javascript:;" class="fn_delete_item remove_image"></a>
                                        <img src="" alt="" />
                                    </div>
                                </li>
                            </ul>
                        </div>
                        <div class="col-lg-3 col-md-12">
                            <div class="heading_label">
                                <span>{$btr->sviat_promo__caption_block_title|escape}</span>
                                <i class="fn_tooltips" title="{$btr->sviat_promo__caption_block_hint|escape}">
                                    {include file='svg_icon.tpl' svgId='icon_tooltips'}
                                </i>
                            </div>
                            <ul class="category_images_list">
                                <li class="category_image_item fn_image_block">
                                    {if $promo->caption_banner_image}
                                        <input type="hidden" class="fn_accept_delete" name="delete_caption_banner_image" value="">
                                        <div class="fn_parent_image">
                                            <div class="category_image image_wrapper fn_image_wrapper text-xs-center">
                                                <a href="javascript:;" class="fn_delete_item remove_image"></a>
                                                <img src="{$promo->caption_banner_image|resize:300:80:false:$config->resized_promo_images_dir}" alt="" />
                                            </div>
                                        </div>
                                    {else}
                                        <div class="fn_parent_image"></div>
                                    {/if}
                                    <div class="fn_upload_image dropzone_block_image {if $promo->caption_banner_image} hidden{/if}">
                                        <i class="fa fa-plus font-5xl" aria-hidden="true"></i>
                                        <input class="dropzone_image" name="caption_banner_image" type="file" accept="image/*,.svg" />
                                    </div>
                                    <div class="category_image image_wrapper fn_image_wrapper fn_new_image text-xs-center hidden">
                                        <a href="javascript:;" class="fn_delete_item remove_image"></a>
                                        <img src="" alt="" />
                                    </div>
                                </li>
                            </ul>
                            <div class="heading_label mt-q">{$btr->sviat_promo__image_size_label|escape}</div>
                            <div class="banner_group__inputs mt-q">
                                <div class="banner_group__input">
                                    <div class="input-group">
                                        <input name="caption_banner_width" class="form-control" type="text" value="{$promo->caption_banner_width|default:800|escape}" placeholder="800" />
                                        <span class="input-group-addon">px</span>
                                    </div>
                                </div>
                                <div class="banner_group__input">
                                    <div class="input-group">
                                        <input name="caption_banner_height" class="form-control" type="text" value="{$promo->caption_banner_height|default:80|escape}" placeholder="80" />
                                        <span class="input-group-addon">px</span>
                                    </div>
                                </div>
                            </div>
                            <div class="heading_label mt-1">{$btr->sviat_promo__caption_mode_label|escape}</div>
                            <select name="product_caption_mode" class="selectpicker form-control">
                                <option value="2"{if ($promo->product_caption_mode|default:0) == 2} selected{/if}>{$btr->sviat_promo__caption_mode_above|escape}</option>
                                <option value="0"{if ($promo->product_caption_mode|default:0) == 0} selected{/if}>{$btr->sviat_promo__caption_mode_below|escape}</option>
                                <option value="1"{if ($promo->product_caption_mode|default:0) == 1} selected{/if}>{$btr->sviat_promo__caption_mode_replace|escape}</option>
                                <option value="3"{if ($promo->product_caption_mode|default:0) == 3} selected{/if}>{$btr->sviat_promo__caption_mode_image_only|escape}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-1 fn_promo_type_section" data-show-for="gift"{if ($promo->promo_type|default:'gift') != 'gift'} style="display:none"{/if}>
        <div class="col-lg-12 col-md-12">
            <div class="boxed fn_toggle_wrap">
                <div class="heading_box">
                    {$btr->sviat_promo__section_gifts|escape}
                    <div class="toggle_arrow_wrap fn_toggle_card text-primary">
                        <a class="btn-minimize" href="javascript:;" ><i class="fa fn_icon_arrow fa-angle-down"></i></a>
                    </div>
                </div>
                <div class="toggle_body_wrap on fn_card fn_sort_list">
                    <p class="text-muted small mb-h">{$btr->sviat_promo__hint_gifts|escape}</p>
                    <div class="okay_list">
                        <div class="okay_list_body sortable promo_gifts">
                            {foreach $promo_gifts as $promo_gift}
                                <div class="fn_row okay okay_list_body_item fn_sort_item">
                                    <div class="okay_list_row">
                                        <div class="okay_list_boding okay_list_drag move_zone">
                                            {include file='svg_icon.tpl' svgId='drag_vertical'}
                                        </div>
                                        <div class="okay_list_boding okay_list_related_photo">
                                            <input type="hidden" name=promo_gifts[] value='{$promo_gift->id}'>
                                            <a href="{url controller=ProductAdmin id=$promo_gift->id}">
                                                {if $promo_gift->images[0]}
                                                    <img class="product_icon" src='{$promo_gift->images[0]->filename|resize:40:40}'>
                                                {else}
                                                    <img class="product_icon" src="design/images/no_image.png" width="40">
                                                {/if}
                                            </a>
                                        </div>
                                        <div class="okay_list_boding okay_list_related_name">
                                            <a class="link" href="{url controller=ProductAdmin id=$promo_gift->id}">{$promo_gift->name|escape}</a>
                                        </div>
                                        <div class="okay_list_boding okay_list_close">
                                            <button data-hint="{$btr->general_delete_product|escape}" type="button" class="btn_close fn_remove_item hint-bottom-right-t-info-s-small-mobile  hint-anim">
                                                {include file='svg_icon.tpl' svgId='delete'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            {/foreach}
                            <div id="new_promo_gift" class="fn_row okay okay_list_body_item fn_sort_item" style='display:none;'>
                                <div class="okay_list_row">
                                    <div class="okay_list_boding okay_list_drag move_zone">
                                        {include file='svg_icon.tpl' svgId='drag_vertical'}
                                    </div>
                                    <div class="okay_list_boding okay_list_related_photo">
                                        <input type="hidden" name="promo_gifts[]" value="">
                                        <img class=product_icon src="">
                                    </div>
                                    <div class="okay_list_boding okay_list_related_name">
                                        <a class="link promo_gift_name" href=""></a>
                                    </div>
                                    <div class="okay_list_boding okay_list_close">
                                        <button data-hint="{$btr->general_delete_product|escape}" type="button" class="btn_close fn_remove_item hint-bottom-right-t-info-s-small-mobile  hint-anim">
                                            {include file='svg_icon.tpl' svgId='delete'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="heading_label mt-1">{$btr->products_add|escape}</div>
                    <div class="autocomplete_arrow">
                        <input type=text name=promo id="promo_gifts" class="form-control" placeholder='{$btr->general_add_product|escape}'>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-1">
        <div class="col-lg-12 col-md-12">
            <div class="boxed match fn_toggle_wrap">
                <div class="heading_box">
                    {$btr->sviat_promo__section_seo|escape}
                    <div class="toggle_arrow_wrap fn_toggle_card text-primary">
                        <a class="btn-minimize" href="javascript:;" ><i class="fa fn_icon_arrow fa-angle-down"></i></a>
                    </div>
                </div>
                <div class="toggle_body_wrap on fn_card row">
                    <div class="col-lg-6 col-md-12">
                        <div class="heading_label">Meta-title</div>
                        <input name="meta_title" class="form-control fn_meta_field mb-h" type="text" value="{$promo->meta_title|escape}" />
                        <div class="heading_label">Meta-keywords</div>
                        <input name="meta_keywords" class="form-control fn_meta_field mb-h" type="text" value="{$promo->meta_keywords|escape}" />
                    </div>

                    <div class="col-lg-6 col-md-12 pl-lg-0">
                        <div class="heading_label">Meta-description</div>
                        <textarea name="meta_description" class="form-control okay_textarea fn_meta_field">{$promo->meta_description|escape}</textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12 col-md-12">
            <div class="boxed match fn_toggle_wrap tabs">
                <div class="heading_tabs">
                    <div class="tab_navigation">
                        <a href="#tab1" class="heading_box tab_navigation_link">{$btr->general_short_description|escape}</a>
                        <a href="#tab2" class="heading_box tab_navigation_link">{$btr->general_full_description|escape}</a>
                    </div>
                    <div class="toggle_arrow_wrap fn_toggle_card text-primary">
                        <a class="btn-minimize" href="javascript:;" ><i class="icon-arrow-down"></i></a>
                    </div>
                </div>
                <div class="toggle_body_wrap on fn_card">
                    <div class="tab_container">
                        <div id="tab1" class="tab">
                            <textarea name="annotation" id="annotation" class="editor_small">{$promo->annotation|escape}</textarea>
                        </div>
                        <div id="tab2" class="tab">
                            <textarea id="fn_editor" name="description" class="editor_large fn_editor_class">{$promo->description|escape}</textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-1 fn_promo_type_section" data-show-for="percent,fixed"{if !in_array($promo->promo_type|default:'percent', ['percent','fixed'])} style="display:none"{/if}>
        <div class="col-lg-12 col-md-12">
            <div class="boxed fn_toggle_wrap">
                <div class="heading_box heading_box--switch-right">
                    <span>{$btr->sviat_promo__section_feeds|escape}</span>
                    <label class="switch switch-default">
                        <input type="hidden" name="feed_enabled" value="0" />
                        <input class="switch-input fn_promo_feed_toggle" name="feed_enabled" value="1" type="checkbox"
                            {if !empty($promo->feed_enabled)}checked=""{/if} />
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </label>
                    <div class="toggle_arrow_wrap fn_toggle_card text-primary">
                        <a class="btn-minimize" href="javascript:;"><i class="fa fn_icon_arrow fa-angle-down"></i></a>
                    </div>
                </div>

                <div class="toggle_body_wrap fn_card fn_promo_feeds_body{if !empty($promo->feed_enabled)} on{/if}"{if empty($promo->feed_enabled)} style="display:none"{/if}>
                    {if empty($available_feeds.feeds) && empty($available_feeds.gm)}
                        <p class="text_grey text_13 mb-0">{$btr->sviat_promo__feeds_no_feeds|escape}</p>
                    {else}
                        <div class="permission_block">
                            <div class="permission_boxes row">

                                {* ── OkayCMS / Feeds ──────────────────────────────────────── *}
                                {if !empty($available_feeds.feeds)}
                                    <div class="col-lg-12 mb-1">
                                        <div class="heading_label">{$btr->sviat_promo__feeds_module_title|escape}</div>
                                    </div>
                                    {foreach $available_feeds.feeds as $avail_feed}
                                        <div class="col-xl-6 col-lg-6 col-md-12">
                                            <div class="permission_box permission_box--long">
                                                <span>{$avail_feed->name|escape}</span>
                                                <label class="switch switch-default">
                                                    <input class="switch-input" type="checkbox"
                                                        name="feed_ids[feeds][]"
                                                        value="{$avail_feed->id}"
                                                        {if in_array($avail_feed->id, $linked_feed_ids.feeds|default:[])}checked=""{/if} />
                                                    <span class="switch-label"></span>
                                                    <span class="switch-handle"></span>
                                                </label>
                                            </div>
                                        </div>
                                    {/foreach}
                                {/if}

                                {* ── OkayCMS / Google Merchant ────────────────────────────── *}
                                {if !empty($available_feeds.gm)}
                                    <div class="col-lg-12 mb-1{if !empty($available_feeds.feeds)} mt-1{/if}">
                                        <div class="heading_label">{$btr->sviat_promo__feeds_gm_title|escape}</div>
                                    </div>
                                    {foreach $available_feeds.gm as $avail_gm}
                                        <div class="col-xl-6 col-lg-6 col-md-12">
                                            <div class="permission_box permission_box--long">
                                                <span>{$avail_gm->name|escape}</span>
                                                <label class="switch switch-default">
                                                    <input class="switch-input" type="checkbox"
                                                        name="feed_ids[gm][]"
                                                        value="{$avail_gm->id}"
                                                        {if in_array($avail_gm->id, $linked_feed_ids.gm|default:[])}checked=""{/if} />
                                                    <span class="switch-label"></span>
                                                    <span class="switch-handle"></span>
                                                </label>
                                            </div>
                                        </div>
                                    {/foreach}
                                {/if}

                            </div>
                        </div>
                    {/if}
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-2">
        <div class="col-lg-12 col-md-12">
            <div class="text-xs-right py-h px-h">
                <button type="submit" class="btn btn_small btn_blue">
                    {include file='svg_icon.tpl' svgId='checked'}
                    <span>{$btr->general_apply|escape}</span>
                </button>
            </div>
        </div>
    </div>
</form>

{include file='tinymce_init.tpl'}
{literal}
    <script src="design/js/autocomplete/jquery.autocomplete-min.js"></script>
    <script>
        function applyPromoTypeSections() {
            var v = $('#fn_promo_type_select').val();
            if (!v) {
                v = 'percent';
            }
            $('.fn_promo_type_section').each(function() {
                var showFor = $(this).attr('data-show-for');
                if (typeof showFor === 'undefined' || showFor === '') {
                    return;
                }
                var types = String(showFor).split(',').map(function(s) { return $.trim(s); });
                $(this).toggle(types.indexOf(v) !== -1);
            });
        }

        $(document).on('change', '#fn_promo_type_select', applyPromoTypeSections);
        $(document).on('changed.bs.select', '#fn_promo_type_select', applyPromoTypeSections);

        function syncPromoFeedToggle($toggle, animate) {
            if (!$toggle || !$toggle.length) {
                return;
            }
            var $body = $toggle.closest('.boxed').find('.fn_promo_feeds_body');
            if (!$body.length) {
                return;
            }

            var enabled = $toggle.is(':checked');
            if (enabled) {
                if (animate) {
                    $body.stop(true, true).slideDown(150);
                } else {
                    $body.show();
                }
                $body.addClass('on');
            } else {
                if (animate) {
                    $body.stop(true, true).slideUp(150);
                } else {
                    $body.hide();
                }
                $body.removeClass('on');
            }
        }

        // Перемикач фідів: показати/сховати блок вибору фідів
        $(document).on('change', '.fn_promo_feed_toggle', function() {
            syncPromoFeedToggle($(this), true);
        });

        function clampDiscountPercent() {
            var $field = $('input[name="discount_percent"]');
            if (!$field.length) {
                return;
            }

            var raw = String($field.val() || '').replace(',', '.').trim();
            if (raw === '') {
                return;
            }

            var value = parseFloat(raw);
            if (isNaN(value)) {
                $field.val('');
                return;
            }

            if (value < 1) {
                value = 1;
            } else if (value > 100) {
                value = 100;
            }

            $field.val(value);
        }

        $(document).on('input change blur', 'input[name="discount_percent"]', clampDiscountPercent);

        function normalizeDiscountFixed() {
            var $field = $('input[name="discount_fixed"]');
            if (!$field.length) {
                return;
            }

            var raw = String($field.val() || '').replace(',', '.').trim();
            if (raw === '') {
                return;
            }

            var value = parseFloat(raw);
            if (isNaN(value) || value <= 0) {
                $field.val('');
                return;
            }

            $field.val(value);
        }

        $(document).on('input change blur', 'input[name="discount_fixed"]', normalizeDiscountFixed);

        function sviatPromoDateRangeSync() {
            var $sel = $("#fn_has_date_range");
            if (!$sel.length) {
                return;
            }
            var v = $sel.val();
            if (typeof $.fn.selectpicker !== "undefined" && $sel.data("selectpicker")) {
                try {
                    v = $sel.selectpicker("val");
                } catch (e) {}
            }
            if (v === null || typeof v === "undefined") {
                v = $sel.find("option:selected").val();
            }
            var show = (String(v) === "1");
            $sel.closest(".fn_date_range_wrap").find(".fn_date_range_dates").each(function () {
                $(this).css("display", show ? "" : "none");
            });
        }

        $(document).on("change", "#fn_has_date_range", sviatPromoDateRangeSync);
        $(document).on("changed.bs.select", "#fn_has_date_range", sviatPromoDateRangeSync);

        $(window).on("load", function() {
            applyPromoTypeSections();
            syncPromoFeedToggle($('.fn_promo_feed_toggle').first(), false);
            clampDiscountPercent();
            normalizeDiscountFixed();

            setTimeout(function () {
                sviatPromoDateRangeSync();
            }, 0);

            $(document).on('click', '.fn_remove_item', function() {
                $(this).closest('.fn_row').fadeOut(200, function() { $(this).remove(); });
                return false;
            });

            var new_promo_gift = $('#new_promo_gift').clone(true);
            $('#new_promo_gift').remove().removeAttr('id');

            $("input#promo_gifts").devbridgeAutocomplete({
                serviceUrl:'ajax/search_products.php',
                type: 'POST',
                minChars:0,
                noCache: false,
                onSelect:
                    function(suggestion){
                        $("input#promo_gifts").val('').focus().blur();
                        new_item = new_promo_gift.clone().appendTo('.promo_gifts');
                        new_item.removeAttr('id');
                        new_item.find('a.promo_gift_name').html(suggestion.data.name);
                        new_item.find('a.promo_gift_name').attr('href', 'index.php?module=ProductAdmin&id='+suggestion.data.id);
                        new_item.find('input[name*="promo_gifts"]').val(suggestion.data.id);
                        if(suggestion.data.image)
                            new_item.find('img.product_icon').attr("src", suggestion.data.image);
                        else
                            new_item.find('img.product_icon').remove();
                        new_item.show();
                    },
                formatResult:
                    function(suggestions, currentValue){
                        var reEscape = new RegExp('(\\' + ['/', '.', '*', '+', '?', '|', '(', ')', '[', ']', '{', '}', '\\'].join('|\\') + ')', 'g');
                        var pattern = '(' + currentValue.replace(reEscape, '\\$1') + ')';
                        return "<div>" + (suggestions.data.image?"<img align=absmiddle src='"+suggestions.data.image+"'> ":'') + "</div>" +  "<span>" + suggestions.value.replace(new RegExp(pattern, 'gi'), '<strong>$1<\/strong>') + "</span>";
                    }
            });

            // Автодоповнення товарів у скопі (працює і для включень, і для виключень)
            function reEscape(str) {
                return str.replace(/(\/|\.|\*|\+|\?|\||\(|\)|\[|\]|\{|\}|\\)/g, '\\$1');
            }

            $('.fn_promo_scope_autocomplete').each(function() {
                var $input = $(this);
                var mode = $input.data('mode');
                var $area = $input.closest('.fn_promo_product_area');
                var $list = $area.find('.fn_promo_scope_products_' + mode);
                var $newRowTemplate = $area.find('.fn_promo_scope_new_row_' + mode);

                $input.devbridgeAutocomplete({
                    serviceUrl: 'ajax/search_products.php',
                    type: 'POST',
                    minChars: 0,
                    noCache: false,
                    onSelect: function(suggestion) {
                        $input.val('').focus().blur();
                        var $newItem = $newRowTemplate.find('.fn_row').clone(true);
                        $newItem.find('.fn_promo_scope_product_name')
                            .html(suggestion.data.name)
                            .attr('href', 'index.php?controller=ProductAdmin&id=' + suggestion.data.id);
                        $newItem.find('input[type="hidden"]').val(suggestion.data.id);
                        if (suggestion.data.image) {
                            $newItem.find('img.product_icon').attr('src', suggestion.data.image);
                        } else {
                            $newItem.find('img.product_icon').remove();
                        }
                        $newItem.show();
                        $list.append($newItem);
                    },
                    formatResult: function(suggestions, currentValue) {
                        var pattern = '(' + reEscape(currentValue) + ')';
                        return '<div>' + (suggestions.data.image ? '<img align=absmiddle src="' + suggestions.data.image + '"> ' : '') + '</div>' +
                            '<span>' + suggestions.value.replace(new RegExp(pattern, 'gi'), '<strong>$1<\/strong>') + '</span>';
                    }
                });
            });
        });
    </script>
{/literal}
<script type="text/javascript">
(function ($) {
    var svFvData   = {$sv_promo_fv_json nofilter};
    var svFeatList = {$sv_promo_feat_json nofilter};
    var svFeatureValuesEndpoint = '{url controller=[Sviat,Promo,CampaignEditAdmin] id=$promo->id}';
    var svFvRequestByFeature = {};

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildFeaturesOptions(selectedId) {
        var opts = '<option value=""></option>';
        svFeatList.forEach(function (f) {
            opts += '<option value="' + f.id + '"' + (f.id == selectedId ? ' selected' : '') + '>' + escapeHtml(f.name) + '</option>';
        });
        return opts;
    }

    function buildValuesSelect(mode, featureId, selectedIds, values) {
        var vals = values || svFvData[featureId] || [];
        var opts = '';
        vals.forEach(function (v) {
            opts += '<option value="' + v.id + '"' + (selectedIds.indexOf(v.id) !== -1 ? ' selected' : '') + '>' + escapeHtml(v.value) + '</option>';
        });
        return '<select class="selectpicker col-xs-12 px-0" multiple '
             + 'name="promo_objects[' + escapeHtml(mode) + '][feature_value][' + escapeHtml(String(featureId)) + '][]" '
             + 'size="5" data-selected-text-format="count" data-live-search="true">'
             + opts + '</select>';
    }

    function loadFeatureValues(featureId) {
        var normalizedFeatureId = String(parseInt(featureId, 10) || '');
        var deferred = $.Deferred();

        if (!normalizedFeatureId) {
            deferred.resolve([]);
            return deferred.promise();
        }

        if (Array.isArray(svFvData[normalizedFeatureId])) {
            deferred.resolve(svFvData[normalizedFeatureId]);
            return deferred.promise();
        }

        if (svFvRequestByFeature[normalizedFeatureId]) {
            return svFvRequestByFeature[normalizedFeatureId];
        }

        svFvRequestByFeature[normalizedFeatureId] = $.ajax({
            url: svFeatureValuesEndpoint,
            type: 'GET',
            dataType: 'json',
            cache: true,
            data: {
                action: 'feature_values',
                feature_id: normalizedFeatureId
            }
        }).then(function (response) {
            var items = (response && Array.isArray(response.items)) ? response.items : [];
            svFvData[normalizedFeatureId] = items;
            return items;
        }).always(function () {
            delete svFvRequestByFeature[normalizedFeatureId];
        });

        return svFvRequestByFeature[normalizedFeatureId];
    }

    function makeRow(mode, featureId, selectedIds) {
        var $row   = $('<div class="fn_feature_row mb-h" data-mode="' + escapeHtml(mode) + '"></div>');
        var $inner = $('<div class="row align-items-start"></div>');
        var $fSel  = $('<select class="selectpicker form-control fn_feature_type_sel" data-live-search="true" data-size="8"></select>')
            .html(buildFeaturesOptions(featureId));
        var $vWrap = $('<div class="col-sm-6 fn_feature_values_wrap mb-h"></div>');
        if (featureId) {
            $vWrap.html(buildValuesSelect(mode, featureId, selectedIds || []));
        }
        $inner
            .append($('<div class="col-sm-5 mb-h"></div>').append($fSel))
            .append($vWrap)
            .append('<div class="col-sm-1 mb-h"><button type="button" class="btn btn-danger btn-sm fn_remove_feature_row">&#xD7;</button></div>');
        $row.append($inner);
        return $row;
    }

    $(document).on('click', '.fn_add_feature_row', function () {
        var mode   = $(this).data('mode');
        var target = $(this).data('target');
        var $row   = makeRow(mode, null, []);
        $('#' + target).append($row);
        $row.find('.fn_feature_type_sel').selectpicker();
    });

    $(document).on('click', '.fn_remove_feature_row', function () {
        $(this).closest('.fn_feature_row').remove();
    });

    function syncFeatureValuesByTypeSelect($typeSelect) {
        var $row      = $typeSelect.closest('.fn_feature_row');
        var mode      = $row.attr('data-mode');
        var featureId = $typeSelect.val();
        var $wrap     = $row.find('.fn_feature_values_wrap');

        if (typeof featureId === 'undefined' || featureId === null || featureId === '') {
            $wrap.empty();
            return;
        }

        $wrap.html('<div class="text_grey text_13">Завантаження...</div>');
        loadFeatureValues(featureId).done(function (values) {
            if (String($typeSelect.val() || '') !== String(featureId)) {
                return;
            }
            $wrap.html(buildValuesSelect(mode, featureId, [], values));
            $wrap.find('.selectpicker').selectpicker();
        }).fail(function () {
            if (String($typeSelect.val() || '') !== String(featureId)) {
                return;
            }
            $wrap.html('<div class="text_red text_13">Не вдалося завантажити значення</div>');
        });
    }

    $(document).on('change', '.fn_feature_type_sel', function () {
        syncFeatureValuesByTypeSelect($(this));
    });
    $(document).on('changed.bs.select', '.fn_feature_type_sel', function () {
        var $typeSelect = $(this);
        setTimeout(function () {
            syncFeatureValuesByTypeSelect($typeSelect);
        }, 0);
    });

    $('.fn_feature_row .selectpicker').each(function () {
        $(this).selectpicker();
    });

}(jQuery));
</script>
