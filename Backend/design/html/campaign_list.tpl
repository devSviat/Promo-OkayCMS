{$meta_title = $btr->sviat_promo__title scope=global}

<div class="main_header">
    <div class="main_header__item">
        <div class="main_header__inner">
            <div class="box_heading heading_page">
                {$btr->sviat_promo__title|escape} {if $promos_count}- {$promos_count}{/if}
            </div>
            <div class="box_btn_heading">
                <a class="btn btn_small btn-info" href="{url controller=[Sviat,Promo,CampaignEditAdmin] return=$smarty.server.REQUEST_URI}">
                    {include file='svg_icon.tpl' svgId='plus'}
                    <span>{$btr->sviat_promo__add|escape}</span>
                </a>
            </div>
        </div>
    </div>
    <div class="main_header__item">
        <div class="main_header__inner">
            <form class="search" method="get">
                <input type="hidden" name="controller" value="Sviat.Promo.CampaignListAdmin">
                <div class="input-group input-group--search">
                    <input name="keyword" class="form-control" placeholder="{$btr->sviat_promo__search|escape}" type="text" value="{$keyword|escape}" >
                    <span class="input-group-btn">
                    <button type="submit" class="btn"><i class="fa fa-search"></i></button>
                </span>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="boxed fn_toggle_wrap">
    <div class="row">
        <div class="col-lg-12 col-md-12 ">
            <div class="fn_toggle_wrap">
                <div class="heading_box visible_md">
                    {$btr->sviat_promo__filter|escape}
                    <div class="toggle_arrow_wrap fn_toggle_card text-primary">
                        <a class="btn-minimize" href="javascript:;" ><i class="fa fn_icon_arrow fa-angle-down"></i></a>
                    </div>
                </div>
                <div class="fn_step-0 boxed_sorting toggle_body_wrap off fn_card">
                    <div class="row">
                        <div class="col-md-3 col-lg-3 col-sm-12">
                            <div>
                                <select id="id_filter" name="promos_filter" class="selectpicker form-control" title="{$btr->sviat_promo__filter|escape}" data-live-search="true" onchange="location = this.value;">
                                    <option value="{url keyword=null page=null filter=null}" {if !$filter}selected{/if}>{$btr->sviat_promo__all_promos|escape}</option>
                                    <option value="{url keyword=null page=null filter='past_promos'}" {if $filter == 'past_promos'}selected{/if}>{$btr->sviat_promo__past_promos|escape}</option>
                                    <option value="{url keyword=null page=null filter='current_promos'}" {if $filter == 'current_promos'}selected{/if}>{$btr->sviat_promo__current_promos|escape}</option>
                                    <option value="{url keyword=null page=null filter='future_promos'}" {if $filter == 'future_promos'}selected{/if}>{$btr->sviat_promo__future_promos|escape}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {if $promos}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <form method="post" class="fn_form_list fn_fast_button">
                <input type="hidden" name="session_id" value="{$smarty.session.id}">
                <div class="okay_list products_list">
                    <div class="fn_step_sorting okay_list_head">
                        <div class="okay_list_boding okay_list_drag"></div>
                        <div class="okay_list_heading okay_list_check">
                            <input class="hidden_check fn_check_all" type="checkbox" id="check_all_1" name="" value="" />
                            <label class="okay_ckeckbox" for="check_all_1"></label>
                        </div>
                        <div class="okay_list_heading okay_list_photo">
                            {$btr->general_photo|escape}
                        </div>
                        <div class="okay_list_heading okay_list_usergroups_name">
                            {$btr->general_name|escape}
                        </div>
                        <div class="okay_list_heading okay_list_status">
                            {$btr->general_enable|escape}
                        </div>
                        <div class="okay_list_heading okay_list_setting okay_list_products_setting">{$btr->general_activities|escape}</div>
                        <div class="okay_list_heading okay_list_close"></div>
                    </div>
                    <div id="sortable" class="okay_list_body sortable">
                        {foreach $promos as $promo}
                            <div class="fn_step-1 fn_row okay_list_body_item fn_sort_item">
                                <div class="okay_list_row">
                                    <input type="hidden" name="positions[{$promo->id}]" value="{$promo->position|escape}">
                                    <div class="okay_list_boding okay_list_drag move_zone">
                                        {include file='svg_icon.tpl' svgId='drag_vertical'}
                                    </div>
                                    <div class="okay_list_boding okay_list_check">
                                        <input class="hidden_check" type="checkbox" id="id_{$promo->id}" name="check[]" value="{$promo->id}"/>
                                        <label class="okay_ckeckbox" for="id_{$promo->id}"></label>
                                    </div>
                                    <div class="okay_list_boding okay_list_photo">
                                        {if $promo->image}
                                            <a href="{url controller=[Sviat,Promo,CampaignEditAdmin] id=$promo->id return=$smarty.server.REQUEST_URI}">
                                                <img src="{$promo->image|resize:55:55:false:$config->resized_promo_images_dir}"/>
                                            </a>
                                        {else}
                                            <img height="55" width="55" src="design/images/no_image.png"/>
                                        {/if}
                                    </div>
                                    <div class="okay_list_boding okay_list_usergroups_name">
                                        <a class="text_400 link" href="{url controller=[Sviat,Promo,CampaignEditAdmin] id=$promo->id return=$smarty.server.REQUEST_URI}">
                                            {$promo->name|escape}
                                            {if $promo->date_start}
                                                <div class="okay_list_name_brand text_400 text_grey">({$promo->date_start|date} - {$promo->date_end|date})</div>
                                            {/if}
                                        </a>
                                    </div>
                                    <div class="okay_list_boding okay_list_status">
                                        <label class="switch switch-default ">
                                            <input class="switch-input fn_ajax_action {if $promo->visible}fn_active_class{/if}" data-controller="Sviat.Promo.PromoCampaignEntity" data-action="visible" data-id="{$promo->id}" name="visible" value="1" type="checkbox"  {if $promo->visible}checked=""{/if}/>
                                            <span class="switch-label"></span>
                                            <span class="switch-handle"></span>
                                        </label>
                                    </div>
                                    <div class="okay_list_setting okay_list_products_setting">
                                        <a href='{url_generator route='sviat_promo_page' url=$promo->url absolute=1}' target="_blank" data-hint="{$btr->general_view|escape}" class="setting_icon setting_icon_open hint-bottom-middle-t-info-s-small-mobile  hint-anim">
                                            {include file='svg_icon.tpl' svgId='eye'}
                                        </a>
                                    </div>
                                    <div class="okay_list_boding okay_list_close">
                                        <button data-hint="{$btr->sviat_promo__delete|escape}" type="button" class="btn_close fn_remove hint-bottom-right-t-info-s-small-mobile  hint-anim" data-toggle="modal" data-target="#fn_action_modal" onclick="success_action($(this));">
                                            {include file='svg_icon.tpl' svgId='trash'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        {/foreach}
                    </div>

                    <div class="okay_list_footer fn_action_block">
                        <div class="okay_list_foot_left">
                            <div class="okay_list_heading okay_list_check">
                                <input class="hidden_check fn_check_all" type="checkbox" id="check_all_2" name="" value=""/>
                                <label class="okay_ckeckbox" for="check_all_2"></label>
                            </div>
                            <div class="okay_list_option">
                                <select name="action" class="selectpicker form-control">
                                    <option value="enable">{$btr->general_do_enable|escape}</option>
                                    <option value="disable">{$btr->general_do_disable|escape}</option>
                                    <option value="delete">{$btr->general_delete|escape}</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn_small btn_blue">
                            {include file='svg_icon.tpl' svgId='checked'}
                            <span>{$btr->general_apply|escape}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12 txt_center">
            {include file='pagination.tpl'}
        </div>
    </div>
    {/if}
</div>