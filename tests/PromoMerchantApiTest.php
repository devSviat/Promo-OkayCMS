<?php

namespace Modules\Sviat\Promo;

use Closure;
use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Image;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogItemBuilder;
use Okay\Modules\Sviat\Promo\Entities\PromoFeedLinkEntity;
use Okay\Modules\Sviat\Promo\Extenders\PromoGoogleMerchantExtender;
use Okay\Modules\Sviat\Promo\Helpers\CampaignRepository;
use Okay\Modules\Sviat\Promo\Init\Init;
use Okay\Modules\Sviat\Promo\Requests\CampaignPayloadRequest;
use Okay\Modules\Sviat\Promo\Services\PromoFeedPriceResolver;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use Okay\Entities\CurrenciesEntity;
use ReflectionClass;
use ReflectionMethod;

require_once __DIR__ . '/PromoTestCase.php';

/**
 * Акція в Google через Sviat/MerchantApi: окрема ціль «Google Merchant API» (без фідів), як фід GoogleMerchant
 * чи Feeds, — діє лише для прив'язаних акцій.
 */
class PromoMerchantApiTest extends PromoTestCase
{
    public function testCampaignLinkedToMerchantApiApplies(): void
    {
        $this->assertSame([5], array_keys($this->campaignsFor([5 => [PromoFeedLinkEntity::TYPE_MERCHANT_API => [PromoFeedLinkEntity::MERCHANT_API_FEED_ID]]])));
    }

    public function testUnlinkedCampaignDoesNot(): void
    {
        $this->assertSame([], $this->campaignsFor([5 => [PromoFeedLinkEntity::TYPE_GM => [1, 2]]]));
    }

    public function testMerchantApiLinkIsSaved(): void
    {
        $added = [];
        $links = $this->createStub(PromoFeedLinkEntity::class);
        $links->method('add')->willReturnCallback(static function ($row) use (&$added) {
            $added[] = $row;

            return 1;
        });
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(fn($class) => $class === PromoFeedLinkEntity::class ? $links : $this->createStub($class));

        new CampaignRepository($entityFactory, $this->createStub(Config::class), $this->createStub(Image::class))
            ->saveFeedLinks(9, 1, ['gm' => [2], 'merchant_api' => [1]]);

        $this->assertContains(['promo_id' => 9, 'feed_type' => 'merchant_api', 'feed_id' => 1], $added);
        $this->assertContains(['promo_id' => 9, 'feed_type' => 'gm', 'feed_id' => 2], $added);
    }

    /**
     * Колонка feed_type — ENUM: значення поза списком нестрогий MariaDB мовчки записав би порожнім рядком.
     * Встановлення й оновлення мають брати той самий список, і в ньому має бути merchant_api.
     */
    public function testFeedTypeColumnAcceptsMerchantApi(): void
    {
        $this->assertContains(PromoFeedLinkEntity::TYPE_MERCHANT_API, PromoFeedLinkEntity::TYPES);
        $init = (string) file_get_contents((new ReflectionClass(Init::class))->getFileName());
        $this->assertSame(2, substr_count($init, 'setTypeEnum(PromoFeedLinkEntity::TYPES)'), 'install() and update_2_0_0() must share the enum list');
    }

    /** Перемикач «Google Merchant API» у картці акції доходить до збереження, а не відсіюється як невідомий тип. */
    public function testPostedMerchantApiSwitchIsKept(): void
    {
        $_POST = ['feed_ids' => ['gm' => ['2'], 'merchant_api' => ['1'], 'evil' => ['5']]];
        $request = new \Okay\Core\Request();

        $feedIds = (new ReflectionClass(CampaignPayloadRequest::class))->newInstanceWithoutConstructor();
        Closure::bind(fn() => $this->request = $request, $feedIds, CampaignPayloadRequest::class)();

        $this->assertSame(['gm' => [2], 'merchant_api' => [1]], $feedIds->postFeedIds());
    }

    /** Оновлення: акція, що діяла у фідах GoogleMerchant, діє і в MerchantApi; повторний запуск нічого не дублює. */
    public function testMigrationLinksCampaignsThatHadGmLinks(): void
    {
        $rows = [
            ['promo_id' => 1, 'feed_type' => 'gm', 'feed_id' => 1],
            ['promo_id' => 1, 'feed_type' => 'gm', 'feed_id' => 2],
            ['promo_id' => 2, 'feed_type' => 'feeds', 'feed_id' => 7],
            ['promo_id' => 3, 'feed_type' => 'gm', 'feed_id' => 2],
            ['promo_id' => 3, 'feed_type' => 'merchant_api', 'feed_id' => 1],
        ];

        $this->assertSame([1], PromoFeedLinkEntity::promoIdsMissingMerchantApi($rows));
    }

    public function testHooksIntoCatalogItemBuilderOnlyWhenItExists(): void
    {
        if (!class_exists(CatalogItemBuilder::class)) {
            $this->markTestSkipped('Sviat/MerchantApi не встановлено: підключення до нього перевіряється там, де він є.');
        }
        $recorded = [];
        $facade = $this->createStub(ExtenderFacade::class);
        $facade->method('newChainExtension')->willReturnCallback(static function ($expandable, $extension) use (&$recorded) {
            $recorded[] = [$expandable['class'] ?? $expandable[0], $expandable['method'] ?? $expandable[1], $extension['class'] ?? $extension[0], $extension['method'] ?? $extension[1]];
        });
        $init = (new ReflectionClass(Init::class))->newInstanceWithoutConstructor();
        Closure::bind(fn() => $this->extenderFacade = $facade, $init, AbstractInit::class)();
        (fn() => $this->registerMerchantApiExtensions())->call($init);

        $this->assertContains([CatalogItemBuilder::class, 'getQuery', PromoGoogleMerchantExtender::class, 'preloadForMerchantApi'], $recorded);
        $this->assertContains([CatalogItemBuilder::class, 'getItem', PromoGoogleMerchantExtender::class, 'applyPromoToItem'], $recorded);
    }

    /**
     * @param array<int, array<string, list<int>>> $links promo_id => feed_type => [feed_id]
     * @return array<int, object>
     */
    private function campaignsFor(array $links): array
    {
        $entityFactory = $this->mockEntityFactory([CurrenciesEntity::class => $this->mockCurrenciesEntity(2)]);
        $resolver = new PromoFeedPriceResolver($entityFactory, $this->createStub(QueryFactory::class), $this->createStub(PromotionEligibility::class));
        // setCurrentFeed() скидає завантажене для іншого фіда, тож стан — після нього.
        $resolver->setCurrentFeed(PromoFeedLinkEntity::TYPE_MERCHANT_API, PromoFeedLinkEntity::MERCHANT_API_FEED_ID);
        Closure::bind(function () use ($links) {
            $this->activeCampaigns = array_map(static fn($id) => (object) ['id' => $id], array_combine(array_keys($links), array_keys($links)));
            $this->feedLinksByCampaign = $links;
        }, $resolver, PromoFeedPriceResolver::class)();

        return new ReflectionMethod($resolver, 'getCampaignsForCurrentFeed')->invoke($resolver);
    }
}
