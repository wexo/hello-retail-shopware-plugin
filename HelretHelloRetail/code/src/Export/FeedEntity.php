<?php declare(strict_types=1);

namespace Helret\HelloRetail\Export;

use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;

/**
 * Class FeedEntity
 * @package Helret\HelloRetail\Export
 */
class FeedEntity implements FeedEntityInterface
{
    /**
     * @var string
     */
    private $feed;
    /**
     * @var string
     */
    private $file;
    /**
     * @var SalesChannelDomainEntity
     */
    private $salesChannelDomainEntity;
    /**
     * @var array
     */
    private $associations;
    /**
     * @var string|null
     */
    private $headerTemplate;
    /**
     * @var string|null
     */
    private $bodyTemplate;
    /**
     * @var string|null
     */
    private $footerTemplate;

    /**
     * {@inheritdoc}
     */
    public function getFeed(): string
    {
        return $this->feed;
    }

    /**
     * {@inheritdoc}
     */
    public function getFile(): string
    {
        return $this->file;
    }

    /**
     * {@inheritdoc}
     */
    public function getDomain(): SalesChannelDomainEntity
    {
        return $this->salesChannelDomainEntity;
    }

    /**
     * {@inheritdoc}
     */
    public function getAssociations(): array
    {
        return $this->associations;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderTemplate(): ?string
    {
        return $this->headerTemplate;
    }

    /**
     * {@inheritdoc}
     */
    public function getBodyTemplate(): ?string
    {
        return $this->bodyTemplate;
    }

    /**
     * {@inheritdoc}
     */
    public function getFooterTemplate(): ?string
    {
        return $this->footerTemplate;
    }

    /**
     * {@inheritdoc}
     */
    public function setFeed($feed): void
    {
        $this->feed = $feed;
    }

    /**
     * {@inheritdoc}
     */
    public function setFile($file): void
    {
        $this->file = $file;
    }

    /**
     * {@inheritdoc}
     */
    public function setDomain(SalesChannelDomainEntity $salesChannelDomainEntity): void
    {
        $this->salesChannelDomainEntity = $salesChannelDomainEntity;
    }

    /**
     * {@inheritdoc}
     */
    public function setAssociations($associations): void
    {
        $this->associations = $associations;
    }

    /**
     * {@inheritdoc}
     */
    public function setHeaderTemplate($template): void
    {
        $this->headerTemplate = $template;
    }

    /**
     * {@inheritdoc}
     */
    public function setBodyTemplate($template): void
    {
        $this->bodyTemplate = $template;
    }

    /**
     * {@inheritdoc}
     */
    public function setFooterTemplate($template): void
    {
        $this->footerTemplate = $template;
    }
}