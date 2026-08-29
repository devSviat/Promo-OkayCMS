<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\ServiceLocator;

/**
 * Скидає товарний кеш, якщо Sviat/Redis встановлений, і мовчить, якщо ні.
 *
 * Через ServiceReference сервіс вимагати не можна: Redis може бути не
 * встановлений, і контейнер упав би на ServiceNotFoundException. Звідси пізнє
 * звʼязування через ServiceLocator і клас, названий рядком, — інакше
 * автозавантажувач шукав би відсутній файл.
 *
 * Словник тегів лишається на боці кешу: тут ми просимо результат, а не
 * перелічуємо, які саме ключі знецінити.
 */
final class OptionalRedisInvalidation implements ProductCacheInvalidation
{
    private const REDIS_CACHE_SERVICE_CLASS = 'Okay\\Modules\\Sviat\\Redis\\Services\\RedisCacheService';

    private bool $resolved = false;

    /** @var object|null */
    private $redis = null;

    public function invalidateProductData(): void
    {
        $redis = $this->redis();
        if ($redis !== null) {
            $redis->invalidateProductData();
        }
    }

    public function isAvailable(): bool
    {
        return $this->redis() !== null;
    }

    /** @return object|null */
    private function redis()
    {
        if ($this->resolved) {
            return $this->redis;
        }
        $this->resolved = true;

        $redisClass = self::REDIS_CACHE_SERVICE_CLASS;
        if (!class_exists($redisClass)) {
            return null;
        }

        try {
            $serviceLocator = ServiceLocator::getInstance();
            if (!$serviceLocator->hasService($redisClass)) {
                return null;
            }
            $service = $serviceLocator->getService($redisClass);
        } catch (\Throwable $e) {
            return null;
        }

        if (!method_exists($service, 'invalidateProductData')) {
            return null;
        }

        return $this->redis = $service;
    }
}
