<?php

namespace Okay\Modules\Sviat\Promo\Controllers;

use Okay\Controllers\AbstractController;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Router;
use Okay\Entities\PagesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Helpers\ProductsHelper;
use Okay\Modules\Sviat\Promo\Helpers\CampaignRepository;

class CampaignLandingController extends AbstractController
{
    public function render(
        CampaignRepository $campaigns,
        EntityFactory $entityFactory,
        FrontTranslations $lang,
        ProductsHelper $productsHelper,
        $url
    ) {
        // Завантажуємо акцію без SQL-фільтрів по видимості/даті, щоб обробити стан вручну
        $promoList = $campaigns->findPromos(['url' => $url, 'admin_list' => 1]);
        $promo = reset($promoList);

        if (empty($promo->id)) {
            return false;
        }

        $now = time();

        $isDateExpired = !empty($promo->has_date_range)
            && !empty($promo->date_end)
            && strtotime((string) $promo->date_end) < $now;

        $isUpcoming = !$isDateExpired
            && !empty($promo->has_date_range)
            && !empty($promo->date_start)
            && strtotime((string) $promo->date_start) > $now;

        // Вимкнена в адмінці (visible=0) — не показуємо сторінку нікому, включно з адміном
        if ((int) ($promo->visible ?? 0) !== 1) {
            return false;
        }

        $promo->is_expired  = $isDateExpired;
        $promo->is_upcoming = $isUpcoming;

        if ($isUpcoming && !empty($promo->date_start)) {
            $promo->seconds_to_start = (int) strtotime((string) $promo->date_start) - $now;
        }

        if (!$isUpcoming && $promo->has_date_range && !empty($promo->date_end)) {
            $promo->seconds_left = (int) strtotime((string) $promo->date_end) - $now;
        }

        $productsEntity = $entityFactory->get(ProductsEntity::class);

        $lastModify = $productsEntity->cols(['last_modify'])->order('last_modify_desc')->find(['limit' => 1]);
        if ($this->page) {
            $lastModify[] = $this->page->last_modify;
        }
        $this->response->setHeaderLastModify(max($lastModify));

        // Товари показуємо тільки для активної (не завершеної і не майбутньої) акції
        if (!$promo->is_expired && !$promo->is_upcoming) {
            $filter = ['in_campaign' => (int) $promo->id];

            $itemsPerPage = $this->settings->get('products_num');
            $currentPage  = max(1, $this->request->get('page', 'integer'));
            $this->design->assign('current_page_num', $currentPage);

            $productsCount = $productsEntity->count($filter);
            if ($this->request->get('page') == 'all') {
                $itemsPerPage = $productsCount;
            }

            $pagesNum = ceil($productsCount / $itemsPerPage);
            $this->design->assign('total_pages_num', $pagesNum);
            $this->design->assign('total_products_num', $productsCount);

            $filter['page']  = $currentPage;
            $filter['limit'] = $itemsPerPage;

            $promo->products = $productsHelper->getList($filter);
        }

        $this->design->assign('promo', $promo);

        $pagesEntity = $entityFactory->get(PagesEntity::class);
        $listPage    = $pagesEntity->findOne(['url' => 'promo']);

        $this->design->assign('meta_title', $promo->meta_title);
        $this->design->assign('meta_keywords', $promo->meta_keywords);
        $this->design->assign('meta_description', $promo->meta_description);
        $listPageName = $listPage ? $listPage->name : $lang->getTranslation('sviat_promo__list_title');
        $breadcrumbs  = [Router::generateUrl('sviat_promo_list') => $listPageName, $promo->name];
        $this->design->assign('breadcrumbs', $breadcrumbs);

        $this->response->setContent('campaign_landing.tpl');
    }
}
