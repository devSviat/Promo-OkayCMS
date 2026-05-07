<?php

namespace Okay\Modules\Sviat\Promo\Controllers;

use Okay\Controllers\AbstractController;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Languages;
use Okay\Entities\PagesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;

class PromoCatalogController extends AbstractController
{
    public function render(EntityFactory $entityFactory, FrontTranslations $lang, Languages $languages)
    {
        /** @var PromoCampaignEntity $campaigns */
        $campaigns = $entityFactory->get(PromoCampaignEntity::class);

        $activePromos = $campaigns->find(['cart_active' => 1, 'cart_promos' => 1]);

        $futurePromos = $campaigns->find(['future_promos' => 1]);
        $now = time();
        foreach ($futurePromos as $p) {
            $p->is_upcoming = true;
            if (!empty($p->date_start)) {
                $p->days_to_start = (int) ceil((strtotime((string) $p->date_start) - $now) / 86400);
            }
        }

        $pastPromos = $campaigns->find(['past_promos' => 1]);
        foreach ($pastPromos as $p) {
            $p->is_expired = true;
        }

        $promos = array_merge($activePromos, $futurePromos, $pastPromos);

        $this->design->assign('promos', $promos);

        $pagesEntity = $entityFactory->get(PagesEntity::class);
        $page = $pagesEntity->findOne(['url' => 'promo']);
        $defaultListTitle = $lang->getTranslation('sviat_promo__list_title');
        $langId = (int) $languages->getLangId();

        $settingsMetaTitle = trim((string) $this->settings->get('sviat__promo__list_meta_title'));
        if ($settingsMetaTitle === '') {
            $settingsMetaTitle = trim((string) $this->settings->get('sviat__promo__list_meta_title__' . $langId));
        }

        $settingsMetaKeywords = trim((string) $this->settings->get('sviat__promo__list_meta_keywords'));
        if ($settingsMetaKeywords === '') {
            $settingsMetaKeywords = trim((string) $this->settings->get('sviat__promo__list_meta_keywords__' . $langId));
        }

        $settingsMetaDescription = trim((string) $this->settings->get('sviat__promo__list_meta_description'));
        if ($settingsMetaDescription === '') {
            $settingsMetaDescription = trim((string) $this->settings->get('sviat__promo__list_meta_description__' . $langId));
        }
        $settingsH1 = trim((string) $this->settings->get('sviat__promo__list_h1'));
        if ($settingsH1 === '') {
            $settingsH1 = trim((string) $this->settings->get('sviat__promo__list_h1__' . $langId));
        }
        $finalListTitle = $settingsMetaTitle !== '' ? $settingsMetaTitle : $defaultListTitle;
        $finalH1 = $settingsH1 !== '' ? $settingsH1 : $finalListTitle;
        $this->design->assign('promo_list_title', $finalListTitle);
        $this->design->assign('h1', $finalH1);

        if ($page) {
            $this->design->assign('page', $page);
            $pageMetaKeywords = trim((string) ($page->meta_keywords ?? ''));
            $pageMetaDescription = trim((string) ($page->meta_description ?? ''));
            $this->design->assign('meta_title', $finalListTitle);
            $this->design->assign('meta_keywords', $settingsMetaKeywords !== '' ? $settingsMetaKeywords : $pageMetaKeywords);
            $this->design->assign('meta_description', $settingsMetaDescription !== '' ? $settingsMetaDescription : $pageMetaDescription);
            $this->design->assign('description', $page->description ?? '');
        } else {
            $this->design->assign('meta_title', $finalListTitle);
            $this->design->assign('meta_keywords', $settingsMetaKeywords);
            $this->design->assign('meta_description', $settingsMetaDescription);
        }

        $this->design->assign('breadcrumbs', [$finalListTitle]);

        $this->response->setContent('catalog_promotions.tpl');
    }
}
