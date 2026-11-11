<?php

namespace Okay\Modules\Sviat\Promo\Entities;

use Okay\Core\Entity\Entity;

class PromoFeedLinkEntity extends Entity
{
    public const TYPE_FEEDS = 'feeds';
    public const TYPE_GM    = 'gm';
    /** Sviat/MerchantApi надсилає товари без фідів — ціль одна, з умовним id: зв'язки з id 0 не зберігаються. */
    public const TYPE_MERCHANT_API = 'merchant_api';
    public const MERCHANT_API_FEED_ID = 1;
    /** Значення ENUM-колонки feed_type: install() і оновлення беруть саме цей список. */
    public const TYPES = [self::TYPE_FEEDS, self::TYPE_GM, self::TYPE_MERCHANT_API];

    /**
     * Цілі, модулів яких у магазині немає: перемикача у формі акції для них немає, тож збереження
     * акції не мусить чіпати їхні прив'язки.
     *
     * @return list<string>
     */
    public static function hiddenTypes(bool $feeds, bool $gm, bool $merchantApi): array
    {
        return array_keys(array_filter([self::TYPE_FEEDS => !$feeds, self::TYPE_GM => !$gm, self::TYPE_MERCHANT_API => !$merchantApi]));
    }

    protected static $fields = [
        'promo_id',
        'feed_type',
        'feed_id',
    ];

    protected static $defaultOrderFields = ['promo_id'];
    protected static $table      = 'sviat__promo_feed_links';
    protected static $tableAlias = 'spfl';

    public function deleteByPromoId(int $promoId): void
    {
        $delete = $this->queryFactory->newDelete();
        $delete->from(self::getTable())
            ->where('promo_id = :pid')
            ->bindValue('pid', $promoId)
            ->execute();
    }

    /**
     * @return array<string, int[]> feed_type => [feed_id, ...]
     */
    public function getLinkedFeedIds(int $promoId): array
    {
        $rows   = $this->find(['promo_id' => $promoId]);
        $result = [];
        foreach ($rows as $row) {
            $result[$row->feed_type][] = (int) $row->feed_id;
        }
        return $result;
    }

    /**
     * @param int[] $promoIds
     * @return array<int, array<string, int[]>> promo_id => feed_type => [feed_id, ...]
     */
    public function getLinkedFeedsGrouped(array $promoIds): array
    {
        if (empty($promoIds)) {
            return [];
        }
        $rows   = $this->find(['promo_id' => $promoIds]);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->promo_id][$row->feed_type][] = (int) $row->feed_id;
        }
        return $result;
    }

    /**
     * Акції, які діяли у фідах GoogleMerchant, але ще не прив'язані до MerchantApi: при переході на нього вони
     * мають і далі потрапляти в Google.
     *
     * @param iterable<array{promo_id: int|string, feed_type: string, feed_id: int|string}> $rows
     * @return list<int>
     */
    public static function promoIdsMissingMerchantApi(iterable $rows): array
    {
        $gm = [];
        $merchantApi = [];
        foreach ($rows as $row) {
            if ($row['feed_type'] === self::TYPE_GM) {
                $gm[(int) $row['promo_id']] = true;
            } elseif ($row['feed_type'] === self::TYPE_MERCHANT_API) {
                $merchantApi[(int) $row['promo_id']] = true;
            }
        }

        return array_keys(array_diff_key($gm, $merchantApi));
    }
}
