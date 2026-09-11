<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\ProductFeedRunLog as ProductFeedRunLogResource;

/**
 * One row per ProductFeedCacheWriter::writeSuccess()/writeError() call - see
 * etc/db_schema.xml's ordo_product_feed_run_log comment. Plain data holder, insert-only, read by
 * the new admin health grid (Controller\Adminhtml\ProductFeed\Index).
 */
class ProductFeedRunLog extends AbstractModel
{
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(ProductFeedRunLogResource::class);
    }

    public function setFeedCode(string $feedCode): self
    {
        $this->setData('feed_code', $feedCode);
        return $this;
    }

    public function setStoreId(int $storeId): self
    {
        $this->setData('store_id', $storeId);
        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->setData('status', $status);
        return $this;
    }

    public function setProductCount(int $productCount): self
    {
        $this->setData('product_count', $productCount);
        return $this;
    }

    public function setMessage(?string $message): self
    {
        $this->setData('message', $message);
        return $this;
    }
}
