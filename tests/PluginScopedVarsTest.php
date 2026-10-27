<?php

namespace Modules\Sviat\Promo;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Helpers\ProductsHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoRewardLineEntity;
use Okay\Modules\Sviat\Promo\Plugins\GiftBadgePlugin;
use Okay\Modules\Sviat\Promo\Plugins\PromoBadgeStickerPlugin;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use PHPUnit\Framework\TestCase;

class PluginScopedVarsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $vars = [];

    /** @var array<string, mixed> */
    private array $varsSeenByFetch = [];

    private function design(): Design
    {
        $design = $this->createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($name, $value) {
            $this->vars[$name] = $value;
            return null;
        });
        $design->method('getVar')->willReturnCallback(fn($name) => $this->vars[$name] ?? null);
        $design->method('fetch')->willReturnCallback(function () {
            $this->varsSeenByFetch = $this->vars;
            return 'html';
        });

        return $design;
    }

    /**
     * head.tpl reads $product for og:image after the page body, so a card in
     * "related products" must not leave its own product in that variable.
     */
    public function testBadgeStickerKeepsPageProduct(): void
    {
        $pageProduct = (object) ['id' => 1];
        $cardProduct = (object) ['id' => 2, 'sviat_promo_badge_image' => 'badge.png'];
        $this->vars['product'] = $pageProduct;

        $html = (new PromoBadgeStickerPlugin($this->design()))->run(['product' => $cardProduct]);

        $this->assertSame('html', $html);
        $this->assertSame($cardProduct, $this->varsSeenByFetch['product']);
        $this->assertSame($pageProduct, $this->vars['product']);
    }

    /**
     * The campaign landing page assigns its own $promo, and its product list renders
     * a gift icon per card.
     */
    public function testGiftIconKeepsPagePromo(): void
    {
        $pagePromo = (object) ['id' => 7];
        $giftCampaign = (object) ['id' => 9];
        $this->vars['promo'] = $pagePromo;

        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('promoIdsForProduct')->willReturn([9]);
        $eligibility->method('pickBestActiveCampaign')->willReturn($giftCampaign);
        $rewardLines = $this->createStub(PromoRewardLineEntity::class);
        $rewardLines->method('findOne')->willReturn((object) ['gift_id' => 3]);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($rewardLines);
        $productsHelper = $this->createStub(ProductsHelper::class);
        $productsHelper->method('getList')->willReturn([]);

        $plugin = new GiftBadgePlugin($this->design(), $entityFactory, $productsHelper, $eligibility);
        $html = $plugin->run(['product' => (object) ['id' => 2]]);

        $this->assertSame('html', $html);
        $this->assertSame($giftCampaign, $this->varsSeenByFetch['promo']);
        $this->assertSame($pagePromo, $this->vars['promo']);
        $this->assertNull($this->vars['gift']);
    }
}
