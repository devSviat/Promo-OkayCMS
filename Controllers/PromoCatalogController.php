<?php

namespace Okay\Modules\Sviat\Promo\Controllers;

use Okay\Controllers\AbstractController;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Entities\PagesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;

class PromoCatalogController extends AbstractController
{
    public function render(EntityFactory $entityFactory, FrontTranslations $lang)
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
        if ($page) {
            $this->design->assign('page', $page);
            $this->design->assign('meta_title', $page->meta_title ?: $page->name);
            $this->design->assign('meta_keywords', $page->meta_keywords);
            $this->design->assign('meta_description', $page->meta_description);
            $this->design->assign('description', $page->description ?? '');
        }

        $listPageName = $page ? ($page->name_h1 ?: $page->name) : $lang->getTranslation('sviat_promo__list_title');
        $this->design->assign('breadcrumbs', [$listPageName]);

        $this->response->setContent('catalog_promotions.tpl');
    }
}
