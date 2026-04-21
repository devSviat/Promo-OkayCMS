<?php

namespace Okay\Modules\Sviat\Promo\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Helpers\CampaignRepository;

class CampaignListAdmin extends IndexAdmin
{
    public function fetch(EntityFactory $entityFactory, CampaignRepository $campaignRepository)
    {
        $campaigns = $entityFactory->get(PromoCampaignEntity::class);

        if ($this->request->method('post')) {
            $positions = $this->request->post('positions');
            if (\is_array($positions)) {
                foreach ($positions as $id => $position) {
                    $campaigns->update((int)$id, ['position' => (int)$position]);
                }
            }

            $ids = $this->request->post('check');
            if (is_array($ids)) {
                switch ($this->request->post('action')) {
                    case 'disable':
                        $campaignRepository->disable($ids);
                        break;
                    case 'enable':
                        $campaignRepository->enable($ids);
                        break;
                    case 'delete':
                        $campaignRepository->delete($ids);
                        break;
                }
            }
        }

        $filter = [];
        $filter['page'] = max(1, $this->request->get('page', 'integer'));
        $filter['limit'] = 20;
        $filter['admin_list'] = 1;

        $keyword = $this->request->get('keyword', 'string');
        if (!empty($keyword)) {
            $filter['keyword'] = $keyword;
            $this->design->assign('keyword', $keyword);
        }

        if ($f = $this->request->get('filter')) {
            if ($f == 'past_promos') {
                $filter['past_promos'] = 1;
            } elseif ($f == 'current_promos') {
                $filter['current_promos'] = 1;
            } elseif ($f == 'future_promos') {
                $filter['future_promos'] = 1;
            }
            $this->design->assign('filter', $f);
        }

        $promos_count = $campaignRepository->countPromos($filter);
        if ($this->request->get('page') == 'all') {
            $filter['limit'] = $promos_count;
        }

        $promos = $campaigns->find($filter);
        $this->design->assign('promos_count', $promos_count);
        $this->design->assign('pages_count', ceil($promos_count / $filter['limit']));
        $this->design->assign('current_page', $filter['page']);
        $this->design->assign('promos', $promos);

        $this->response->setContent($this->design->fetch('campaign_list.tpl'));
    }
}
